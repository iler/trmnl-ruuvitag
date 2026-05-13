<?php

namespace App\Services\Ruuvi;

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

        foreach ($sensors as $raw) {
            $mac = strtoupper((string) ($raw['sensor'] ?? ''));
            if ($mac === '') {
                continue;
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

            // Dedupe by sequence number — skip if we already have this exact measurement.
            $exists = $reading->measurementSequence !== null
                && SensorReading::where('mac', $mac)
                    ->where('measurement_sequence', $reading->measurementSequence)
                    ->exists();

            if ($exists) {
                continue;
            }

            SensorReading::create([
                'mac' => $mac,
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
     * Build the TRMNL merge-variable payload from the cached API response + persisted readings.
     * Stays well under the 2 KB webhook limit.
     *
     * @return array{sensors: array<int, array<string, mixed>>, updated_at: string, sensor_count: int}
     */
    public function buildPayload(): array
    {
        $tz = (string) config('ruuvi.display_timezone');
        $staleAfter = (int) config('ruuvi.stale_after');
        $now = CarbonImmutable::now();

        try {
            $apiSensors = $this->client->fetchSensorsDense();
        } catch (Throwable $e) {
            Log::error('Ruuvi: payload build fetch failed', ['exception' => $e->getMessage()]);
            $apiSensors = [];
        }

        $sensors = [];
        foreach ($apiSensors as $raw) {
            $mac = strtoupper((string) ($raw['sensor'] ?? ''));
            if ($mac === '') {
                continue;
            }

            $name = (string) ($raw['name'] ?? $mac);
            $reading = SensorReading::where('mac', $mac)->latest('measured_at')->first();

            // A reading with a null temperature/humidity is the Rawv2 "not available"
            // sentinel — treat it the same as having no reading at all.
            if (! $reading || $reading->temperature === null || $reading->humidity === null) {
                $sensors[] = [
                    'name' => $name,
                    'status' => 'no_data',
                ];

                continue;
            }

            $ageSeconds = (int) $now->diffInSeconds($reading->measured_at, true);
            $isStale = $ageSeconds > $staleAfter;

            $sensors[] = [
                'name' => $name,
                'temperature' => round($reading->temperature, 1),
                'humidity' => round($reading->humidity, 0),
                'pressure_hpa' => $reading->pressure !== null ? round($reading->pressure / 100, 0) : null,
                'battery_mv' => $reading->battery_mv,
                'measured_at' => $reading->measured_at
                    ->copy()
                    ->setTimezone($tz)
                    ->format('H:i'),
                'age_seconds' => $ageSeconds,
                'is_stale' => $isStale,
                'battery_low' => $this->isBatteryLow($raw),
                'alarm' => $this->firstTriggeredAlarm($raw),
                'status' => $isStale ? 'stale' : 'ok',
            ];
        }

        return [
            'sensors' => $sensors,
            'updated_at' => $now->setTimezone($tz)->format('Y-m-d H:i'),
            'sensor_count' => count($sensors),
        ];
    }

    /**
     * Returns the type of the first triggered, enabled alert relevant to the display
     * ('temperature' or 'humidity'), or null. Battery alerts surface via `battery_low`.
     *
     * @param  array<string, mixed>  $raw
     */
    private function firstTriggeredAlarm(array $raw): ?string
    {
        foreach (($raw['alerts'] ?? []) as $alert) {
            if (! ($alert['enabled'] ?? false) || ! ($alert['triggered'] ?? false)) {
                continue;
            }
            $type = $alert['type'] ?? null;
            if ($type === 'temperature' || $type === 'humidity') {
                return $type;
            }
        }

        return null;
    }

    /**
     * Battery is low if the Ruuvi-side battery alert is enabled and triggered.
     *
     * @param  array<string, mixed>  $raw
     */
    private function isBatteryLow(array $raw): bool
    {
        foreach (($raw['alerts'] ?? []) as $alert) {
            if (($alert['type'] ?? null) === 'battery'
                && ($alert['enabled'] ?? false)
                && ($alert['triggered'] ?? false)) {
                return true;
            }
        }

        return false;
    }
}
