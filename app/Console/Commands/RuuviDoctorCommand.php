<?php

namespace App\Console\Commands;

use App\Models\SensorReading;
use App\Services\Ruuvi\RuuviService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Smoke-test the full Ruuvi cycle (fetch → decode → persist → build payload)
 * and print a high-signal summary. Intended to be run manually after deploys
 * or when triaging "is anything coming through?".
 *
 *   php artisan ruuvi:doctor
 *
 * Returns exit code 0 on a clean cycle, 1 if anything went wrong on the
 * happy path (HTTP failure, zero sensors, etc.).
 */
class RuuviDoctorCommand extends Command
{
    protected $signature = 'ruuvi:doctor';

    protected $description = 'Run one Ruuvi fetch+persist+publish cycle and report what happened';

    public function handle(RuuviService $service): int
    {
        Cache::forget('ruuvi:sensors-dense');
        RateLimiter::clear('ruuvi-cloud-fetch');

        $before = SensorReading::count();
        $start = microtime(true);

        try {
            $ok = $service->pushUpdate();
        } catch (Throwable $e) {
            $this->components->error("Fetch threw: {$e->getMessage()}");

            return self::FAILURE;
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        if (! $ok) {
            $this->components->error("pushUpdate returned false (rate limited or fetch failed) after {$elapsedMs} ms");

            return self::FAILURE;
        }

        $persisted = SensorReading::count() - $before;
        $payload = $service->buildPayload();
        /** @var array<int, array<string, mixed>> $sensors */
        $sensors = $payload['sensors'];
        $okSensors = array_values(array_filter($sensors, fn ($s) => ($s['status'] ?? null) === 'ok'));
        $noData = array_values(array_filter($sensors, fn ($s) => ($s['status'] ?? null) === 'no_data'));
        $payloadBytes = strlen((string) json_encode(['merge_variables' => $payload]));

        $this->components->info("Ruuvi cycle completed in {$elapsedMs} ms");
        $this->components->twoColumnDetail('sensors in API response', (string) count($sensors));
        $this->components->twoColumnDetail('new readings persisted', (string) $persisted);
        $this->components->twoColumnDetail('sensors reporting data', count($okSensors).' / '.count($sensors));
        $this->components->twoColumnDetail('TRMNL payload size', "{$payloadBytes} bytes");

        if (! empty($okSensors)) {
            $s = $okSensors[0];
            $this->newLine();
            $this->components->bulletList([sprintf(
                '%s: %s °C / %s%% / %s hPa  (battery %s mV, age %ds)',
                $s['name'] ?? '?',
                $s['temperature'] ?? '?',
                $s['humidity'] ?? '?',
                $s['pressure_hpa'] ?? '?',
                $s['battery_mv'] ?? '—',
                $s['age_seconds'] ?? '?',
            )]);
        }

        if (! empty($noData)) {
            $this->newLine();
            $this->components->warn('Sensors without data:');
            $this->components->bulletList(array_map(fn ($s) => $s['name'] ?? '?', $noData));
        }

        return count($sensors) === 0 ? self::FAILURE : self::SUCCESS;
    }
}
