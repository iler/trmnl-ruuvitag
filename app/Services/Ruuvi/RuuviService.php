<?php

namespace App\Services\Ruuvi;

use App\Models\Sensor;
use App\Models\SensorReading;
use Bnussbau\LaravelTrmnl\Jobs\UpdateScreenContentJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class RuuviService
{
    public function __construct(
        private readonly Client $client,
        private readonly Rawv2Decoder $decoder,
    ) {}

    /**
     * Fetch from Ruuvi Cloud, persist new readings, push the latest payload to TRMNL.
     * Called by the scheduler.
     */
    public function pushUpdate(): bool
    {
        $executed = RateLimiter::attempt(
            key: 'ruuvi-cloud-fetch',
            maxAttempts: 6,
            callback: fn () => $this->refreshFromCloud(),
            decaySeconds: 60,
        );

        if ($executed === false) {
            Log::warning('Ruuvi: rate limit hit on outbound fetch — skipping cycle');

            return false;
        }

        $payload = $this->buildPayload();
        UpdateScreenContentJob::dispatchSync($payload);

        return true;
    }

    /**
     * Pull /sensors-dense, decode each sensor's latest measurement, persist if new.
     */
    private function refreshFromCloud(): void
    {
        try {
            $sensors = $this->client->fetchSensorsDense();
        } catch (Throwable $e) {
            Log::error('Ruuvi: fetch failed', ['exception' => $e->getMessage()]);

            return;
        }

        $configuredMacs = Sensor::where('enabled', true)
            ->pluck('id', 'mac')
            ->mapWithKeys(fn ($id, $mac) => [strtoupper($mac) => $id])
            ->all();

        foreach ($sensors as $raw) {
            $mac = strtoupper($raw['sensor'] ?? '');
            if (! isset($configuredMacs[$mac])) {
                continue; // skip unconfigured sensors
            }

            $hex = $raw['measurements'][0]['data'] ?? null;
            $timestamp = $raw['measurements'][0]['timestamp'] ?? null;
            $rssi = $raw['measurements'][0]['rssi'] ?? null;

            if (! $hex || ! $timestamp) {
                continue;
            }

            try {
                $reading = $this->decoder->decode($hex);
            } catch (Throwable $e) {
                Log::warning('Ruuvi: decode failed', [
                    'mac' => $mac,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            // Skip if we already have this exact measurement (dedupe by sequence number)
            $sensorId = $configuredMacs[$mac];
            $exists = $reading->measurementSequence !== null
                && SensorReading::where('sensor_id', $sensorId)
                    ->where('measurement_sequence', $reading->measurementSequence)
                    ->exists();

            if ($exists) {
                continue;
            }

            SensorReading::create([
                'sensor_id' => $sensorId,
                'temperature' => $reading->temperature,
                'humidity' => $reading->humidity,
                'pressure' => $reading->pressure,
                'battery_mv' => $reading->batteryMv,
                'tx_power_dbm' => $reading->txPowerDbm,
                'rssi' => $rssi,
                'measurement_sequence' => $reading->measurementSequence,
                'measured_at' => CarbonImmutable::createFromTimestamp($timestamp),
            ]);
        }
    }

    /**
     * Build the TRMNL merge-variable payload from persisted data.
     * Stays well under the 2 KB webhook limit.
     */
    public function buildPayload(): array
    {
        $tz = config('ruuvi.display_timezone');
        $staleAfter = config('ruuvi.stale_after');
        $now = CarbonImmutable::now();

        $sensors = Sensor::with('latestReading')
            ->where('enabled', true)
            ->orderBy('display_order')
            ->get()
            ->map(function (Sensor $s) use ($now, $tz, $staleAfter) {
                $r = $s->latestReading;
                if (! $r) {
                    return [
                        'name' => $s->display_name,
                        'status' => 'no_data',
                    ];
                }

                $ageSeconds = $now->diffInSeconds($r->measured_at);
                $isStale = $ageSeconds > $staleAfter;
                $batteryLow = $r->battery_mv < $s->battery_low_mv;

                return [
                    'name' => $s->display_name,
                    'temperature' => round($r->temperature, 1),
                    'humidity' => round($r->humidity, 0),
                    'pressure_hpa' => round($r->pressure / 100, 0),
                    'battery_mv' => $r->battery_mv,
                    'measured_at' => $r->measured_at
                        ->copy()
                        ->setTimezone($tz)
                        ->format('H:i'),
                    'age_seconds' => $ageSeconds,
                    'is_stale' => $isStale,
                    'battery_low' => $batteryLow,
                    'alarm' => $this->checkThresholds($s, $r),
                    'status' => $isStale ? 'stale' : 'ok',
                ];
            })
            ->all();

        return [
            'sensors' => $sensors,
            'updated_at' => $now->setTimezone($tz)->format('Y-m-d H:i'),
            'sensor_count' => count($sensors),
        ];
    }

    /**
     * Returns null, 'temperature', or 'humidity' depending on which threshold (if any) is breached.
     */
    private function checkThresholds(Sensor $s, SensorReading $r): ?string
    {
        if ($s->temp_min !== null && $r->temperature < $s->temp_min) {
            return 'temperature';
        }
        if ($s->temp_max !== null && $r->temperature > $s->temp_max) {
            return 'temperature';
        }
        if ($s->humidity_min !== null && $r->humidity < $s->humidity_min) {
            return 'humidity';
        }
        if ($s->humidity_max !== null && $r->humidity > $s->humidity_max) {
            return 'humidity';
        }

        return null;
    }
}
