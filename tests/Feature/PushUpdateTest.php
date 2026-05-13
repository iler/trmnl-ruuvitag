<?php

use App\Models\SensorReading;
use App\Services\Ruuvi\RuuviService;
use Bnussbau\LaravelTrmnl\Jobs\UpdateScreenContentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ruuvi-cloud-fetch');
    // 12:00 sits on a 15-minute boundary so the everyFifteenMinutes() task is due.
    Carbon::setTestNow('2026-05-09 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('runs the scheduled task end-to-end and dispatches the screen content job', function () {
    Bus::fake();
    Http::fake([
        'network.ruuvi.com/sensors-dense*' => Http::response([
            'data' => [
                'sensors' => [[
                    'sensor' => 'AA:BB:CC:DD:EE:FF',
                    'name' => 'Living Room',
                    'measurements' => [[
                        'data' => '0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F',
                        'timestamp' => Carbon::now()->subSeconds(30)->getTimestamp(),
                        'rssi' => -65,
                    ]],
                    'alerts' => [],
                ]],
            ],
        ], 200),
    ]);

    Artisan::call('schedule:run');

    Bus::assertDispatchedSync(UpdateScreenContentJob::class, function (UpdateScreenContentJob $job) {
        $sensors = $job->content['sensors'] ?? [];

        return count($sensors) === 1
            && $sensors[0]['name'] === 'Living Room'
            && $sensors[0]['temperature'] === 24.3
            && $sensors[0]['status'] === 'ok';
    });

    expect(SensorReading::count())->toBe(1);
});

it('keeps the dispatched payload under TRMNLs 2 KB webhook limit', function () {
    // 8 sensors — a typical home setup is 4–6, this stress-tests the upper bound.
    $apiSensors = [];
    for ($i = 1; $i <= 8; $i++) {
        $mac = sprintf('AA:BB:CC:DD:EE:%02X', $i);

        SensorReading::create([
            'mac' => $mac,
            'temperature' => 22.0 + ($i * 0.1),
            'humidity' => 45.0 + $i,
            'pressure' => 101000 + $i,
            'battery_mv' => 2900 - ($i * 10),
            'tx_power_dbm' => 4,
            'rssi' => -65,
            'measurement_sequence' => $i,
            'measured_at' => Carbon::now()->subSeconds(30),
        ]);

        $apiSensors[] = [
            'sensor' => $mac,
            'name' => "Sensor number {$i}",
            'measurements' => [],
            'alerts' => [],
        ];
    }

    Http::fake([
        'network.ruuvi.com/sensors-dense*' => Http::response([
            'data' => ['sensors' => $apiSensors],
        ], 200),
    ]);

    $payload = app(RuuviService::class)->buildPayload();
    $json = json_encode(['merge_variables' => $payload], JSON_THROW_ON_ERROR);

    expect(strlen($json))->toBeLessThan(2048);
});

it('dispatches an empty payload when the Ruuvi API rejects auth', function () {
    Bus::fake();
    Http::fake([
        'network.ruuvi.com/sensors-dense*' => Http::response('', 401),
    ]);

    expect(app(RuuviService::class)->pushUpdate())->toBeTrue();

    Bus::assertDispatchedSync(UpdateScreenContentJob::class, function (UpdateScreenContentJob $job) {
        return ($job->content['sensors'] ?? null) === []
            && ($job->content['sensor_count'] ?? null) === 0;
    });
});
