<?php

use App\Models\Sensor;
use App\Models\SensorReading;
use App\Services\Ruuvi\Client;
use App\Services\Ruuvi\Rawv2Decoder;
use App\Services\Ruuvi\RuuviService;
use Bnussbau\LaravelTrmnl\Jobs\UpdateScreenContentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('ruuvi-cloud-fetch');
    Carbon::setTestNow('2026-05-09 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Spec test vector 1 from the plan: temperature 24.3 °C, humidity 53.49 %,
 * pressure 100044 Pa, battery 2977 mV, sequence 205.
 */
const HEX_VALID = '0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F';

function makeSensor(array $overrides = []): Sensor
{
    return Sensor::create(array_merge([
        'mac' => 'AA:BB:CC:DD:EE:FF',
        'display_name' => 'Living Room',
        'battery_low_mv' => 2500,
        'display_order' => 1,
    ], $overrides));
}

function fakeReading(int $sensorId, array $overrides = []): SensorReading
{
    return SensorReading::create(array_merge([
        'sensor_id' => $sensorId,
        'temperature' => 22.0,
        'humidity' => 45.0,
        'pressure' => 101000,
        'battery_mv' => 2900,
        'measurement_sequence' => 1,
        'measured_at' => Carbon::now(),
    ], $overrides));
}

it('dispatches UpdateScreenContentJob exactly once with the expected payload shape', function () {
    Bus::fake();
    makeSensor();

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('fetchSensorsDense')->once()->andReturn([
        [
            'sensor' => 'AA:BB:CC:DD:EE:FF',
            'measurements' => [[
                'data' => HEX_VALID,
                'timestamp' => Carbon::now()->subSeconds(30)->getTimestamp(),
                'rssi' => -65,
            ]],
        ],
    ]);

    $service = new RuuviService($client, new Rawv2Decoder);

    expect($service->pushUpdate())->toBeTrue();

    Bus::assertDispatchedSync(UpdateScreenContentJob::class, function (UpdateScreenContentJob $job) {
        $sensors = $job->content['sensors'] ?? [];

        return count($sensors) === 1
            && $sensors[0]['name'] === 'Living Room'
            && $sensors[0]['temperature'] === 24.3
            && $sensors[0]['status'] === 'ok'
            && $job->content['sensor_count'] === 1;
    });
});

it('dedupes readings by measurement_sequence', function () {
    Bus::fake();
    makeSensor();

    $payload = [
        [
            'sensor' => 'AA:BB:CC:DD:EE:FF',
            'measurements' => [[
                'data' => HEX_VALID,
                'timestamp' => Carbon::now()->subSeconds(30)->getTimestamp(),
                'rssi' => -65,
            ]],
        ],
    ];

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('fetchSensorsDense')->twice()->andReturn($payload);

    $service = new RuuviService($client, new Rawv2Decoder);
    $service->pushUpdate();
    $service->pushUpdate();

    expect(SensorReading::count())->toBe(1);
});

it('flags readings older than stale_after as stale', function () {
    $sensor = makeSensor();
    fakeReading($sensor->id, ['measured_at' => Carbon::now()->subHour()]);

    $payload = app(RuuviService::class)->buildPayload();

    expect($payload['sensors'][0]['is_stale'])->toBeTrue();
    expect($payload['sensors'][0]['status'])->toBe('stale');
});

it('keeps recent readings flagged ok', function () {
    $sensor = makeSensor();
    fakeReading($sensor->id, ['measured_at' => Carbon::now()->subSeconds(60)]);

    $payload = app(RuuviService::class)->buildPayload();

    expect($payload['sensors'][0]['is_stale'])->toBeFalse();
    expect($payload['sensors'][0]['status'])->toBe('ok');
});

it('flags battery as low when below sensor threshold', function () {
    $sensor = makeSensor(['battery_low_mv' => 2500]);
    fakeReading($sensor->id, ['battery_mv' => 2400]);

    expect(app(RuuviService::class)->buildPayload()['sensors'][0]['battery_low'])->toBeTrue();
});

it('keeps battery_low false when above threshold', function () {
    $sensor = makeSensor(['battery_low_mv' => 2500]);
    fakeReading($sensor->id, ['battery_mv' => 2900]);

    expect(app(RuuviService::class)->buildPayload()['sensors'][0]['battery_low'])->toBeFalse();
});

it('returns alarm = temperature when temperature exceeds max', function () {
    $sensor = makeSensor(['temp_max' => 25.0]);
    fakeReading($sensor->id, ['temperature' => 28.0]);

    expect(app(RuuviService::class)->buildPayload()['sensors'][0]['alarm'])->toBe('temperature');
});

it('returns alarm = humidity when humidity falls below min', function () {
    $sensor = makeSensor(['humidity_min' => 30.0]);
    fakeReading($sensor->id, ['humidity' => 20.0]);

    expect(app(RuuviService::class)->buildPayload()['sensors'][0]['alarm'])->toBe('humidity');
});

it('returns alarm = null when readings are within all configured thresholds', function () {
    $sensor = makeSensor([
        'temp_min' => 18.0, 'temp_max' => 26.0,
        'humidity_min' => 30.0, 'humidity_max' => 60.0,
    ]);
    fakeReading($sensor->id, ['temperature' => 22.0, 'humidity' => 45.0]);

    expect(app(RuuviService::class)->buildPayload()['sensors'][0]['alarm'])->toBeNull();
});

it('returns false from pushUpdate when rate limit is exhausted, without dispatching', function () {
    Bus::fake();

    // The service's RateLimiter::attempt allows 6 calls per 60 seconds.
    // Pre-fill the bucket so the next attempt is rejected.
    for ($i = 0; $i < 6; $i++) {
        RateLimiter::hit('ruuvi-cloud-fetch', 60);
    }

    $client = Mockery::mock(Client::class);
    $client->shouldNotReceive('fetchSensorsDense');

    $service = new RuuviService($client, new Rawv2Decoder);

    expect($service->pushUpdate())->toBeFalse();
    Bus::assertNothingDispatched();
});
