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
        private readonly Rawv2Decoder $rawv2Decoder,
        private readonly AirDecoder $airDecoder,
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

            // /sensors-dense returns the full BLE advertisement; strip the AD
            // wrapper to get the bare Rawv2 (or other-format) payload.
            $payloadHex = BleAdvertisement::extractRuuviPayload($hex);
            if ($payloadHex === null) {
                Log::warning('Ruuvi: no Ruuvi manufacturer payload in advertisement', ['mac' => $mac]);

                continue;
            }

            try {
                $reading = $this->decodePayload($payloadHex);
            } catch (Throwable $e) {
                Log::info('Ruuvi: skipping unsupported payload format', [
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
     * Dispatch decoding by the Ruuvi data-format byte at offset 0.
     * Add new formats here as decoders are introduced.
     */
    private function decodePayload(string $payloadHex): Reading
    {
        $format = hexdec(substr($payloadHex, 0, 2));

        return match ($format) {
            0x05 => $this->rawv2Decoder->decode($payloadHex),
            0xE1 => $this->airDecoder->decode($payloadHex),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported Ruuvi data format: 0x%02X', $format,
            )),
        };
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

            // Temperature is the primary metric — if the tag reports the Rawv2
            // "not available" sentinel for it (or we have no reading at all)
            // there's nothing useful to display. Humidity/pressure are allowed
            // to be null independently (e.g. freezer sensors don't report humidity).
            if (! $reading || $reading->temperature === null) {
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
                'humidity' => $reading->humidity !== null ? round($reading->humidity, 0) : null,
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
