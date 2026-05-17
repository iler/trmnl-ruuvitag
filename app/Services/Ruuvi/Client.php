<?php

namespace App\Services\Ruuvi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Client
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
        private readonly int $cacheTtl,
    ) {}

    /**
     * Fetches /sensors-dense?measurements=true and caches the entire response.
     * Returns the raw `data.sensors` array — one call per cache-miss covers all sensors.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchSensorsDense(): array
    {
        return Cache::remember(
            'ruuvi:sensors-dense',
            $this->cacheTtl,
            function () {
                $response = Http::withToken($this->token)
                    ->timeout(10)
                    ->retry(2, 500, throw: false)
                    ->acceptJson()
                    ->get("{$this->baseUrl}/sensors-dense", [
                        'measurements' => 'true',
                        'alerts' => 'true',
                    ]);

                if ($response->status() === 401) {
                    throw new \RuntimeException('Ruuvi API: unauthorized — check RUUVI_API_TOKEN');
                }

                $response->throw();

                return $response->json('data.sensors', []);
            }
        );
    }

    /**
     * Fetches a single sensor's history. Use sparingly — for sparkline/graph features.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchHistory(string $mac, ?int $sinceTs = null, ?int $untilTs = null): array
    {
        $params = ['sensor' => $mac];
        if ($sinceTs) {
            $params['since'] = $sinceTs;
        }
        if ($untilTs) {
            $params['until'] = $untilTs;
        }

        $response = Http::withToken($this->token)
            ->timeout(15)
            ->acceptJson()
            ->get("{$this->baseUrl}/get", $params);

        $response->throw();

        return $response->json('data.measurements', []);
    }
}
