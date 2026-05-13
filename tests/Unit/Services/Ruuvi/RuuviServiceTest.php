<?php

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
 * Spec test vector 1 wrapped in a BLE advertisement, as the Ruuvi Cloud
 * /sensors-dense endpoint returns it (Flags AD + Ruuvi Manufacturer-Specific AD).
 * Decoded: temperature 24.3 °C, humidity 53.49 %, pressure 100044 Pa,
 * battery 2977 mV, sequence 205.
 */
const HEX_VALID = '0201061BFF99040512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F';

/**
 * @param  array<int, array<string, mixed>>  $alerts
 * @return array<string, mixed>
 */
function apiSensor(array $overrides = [], array $alerts = []): array
{
    return array_merge([
        'sensor' => 'AA:BB:CC:DD:EE:FF',
        'name' => 'Living Room',
        'measurements' => [[
            'data' => HEX_VALID,
            'timestamp' => Carbon::now()->subSeconds(30)->getTimestamp(),
            'rssi' => -65,
        ]],
        'alerts' => $alerts,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function alert(string $type, bool $triggered, bool $enabled = true): array
{
    return [
        'type' => $type,
        'min' => 0,
        'max' => 0,
        'counter' => 0,
        'delay' => 0,
        'enabled' => $enabled,
        'description' => '',
        'triggered' => $triggered,
        'lastUpdated' => Carbon::now()->getTimestamp(),
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $apiSensors
 */
function mockClient(array $apiSensors): Client
{
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('fetchSensorsDense')->andReturn($apiSensors);

    return $client;
}

it('dispatches UpdateScreenContentJob with the expected payload shape', function () {
    Bus::fake();

    $service = new RuuviService(mockClient([apiSensor()]), new Rawv2Decoder);

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

    $service = new RuuviService(mockClient([apiSensor()]), new Rawv2Decoder);
    $service->pushUpdate();
    $service->pushUpdate();

    expect(SensorReading::count())->toBe(1);
});

it('flags readings older than stale_after as stale', function () {
    SensorReading::create([
        'mac' => 'AA:BB:CC:DD:EE:FF',
        'temperature' => 22.0,
        'humidity' => 45.0,
        'pressure' => 101000,
        'battery_mv' => 2900,
        'measurement_sequence' => 1,
        'measured_at' => Carbon::now()->subHour(),
    ]);

    $service = new RuuviService(mockClient([apiSensor(['measurements' => []])]), new Rawv2Decoder);
    $payload = $service->buildPayload();

    expect($payload['sensors'][0]['is_stale'])->toBeTrue();
    expect($payload['sensors'][0]['status'])->toBe('stale');
});

it('keeps recent readings flagged ok', function () {
    Bus::fake();
    $service = new RuuviService(mockClient([apiSensor()]), new Rawv2Decoder);
    $service->pushUpdate();

    $payload = $service->buildPayload();

    expect($payload['sensors'][0]['is_stale'])->toBeFalse();
    expect($payload['sensors'][0]['status'])->toBe('ok');
});

it('flags battery_low from an enabled, triggered battery alert', function () {
    Bus::fake();
    $service = new RuuviService(
        mockClient([apiSensor([], [alert('battery', triggered: true)])]),
        new Rawv2Decoder,
    );
    $service->pushUpdate();

    expect($service->buildPayload()['sensors'][0]['battery_low'])->toBeTrue();
});

it('keeps battery_low false when no battery alert is triggered', function () {
    Bus::fake();
    $service = new RuuviService(
        mockClient([apiSensor([], [alert('battery', triggered: false)])]),
        new Rawv2Decoder,
    );
    $service->pushUpdate();

    expect($service->buildPayload()['sensors'][0]['battery_low'])->toBeFalse();
});

it('ignores disabled battery alerts even when triggered', function () {
    Bus::fake();
    $service = new RuuviService(
        mockClient([apiSensor([], [alert('battery', triggered: true, enabled: false)])]),
        new Rawv2Decoder,
    );
    $service->pushUpdate();

    expect($service->buildPayload()['sensors'][0]['battery_low'])->toBeFalse();
});

it('returns alarm = temperature when a triggered temperature alert is enabled', function () {
    Bus::fake();
    $service = new RuuviService(
        mockClient([apiSensor([], [alert('temperature', triggered: true)])]),
        new Rawv2Decoder,
    );
    $service->pushUpdate();

    expect($service->buildPayload()['sensors'][0]['alarm'])->toBe('temperature');
});

it('returns alarm = humidity when a triggered humidity alert is enabled', function () {
    Bus::fake();
    $service = new RuuviService(
        mockClient([apiSensor([], [alert('humidity', triggered: true)])]),
        new Rawv2Decoder,
    );
    $service->pushUpdate();

    expect($service->buildPayload()['sensors'][0]['alarm'])->toBe('humidity');
});

it('returns alarm = null when no display-relevant alert is triggered', function () {
    Bus::fake();
    $service = new RuuviService(
        mockClient([apiSensor([], [
            alert('temperature', triggered: false),
            alert('humidity', triggered: false),
        ])]),
        new Rawv2Decoder,
    );
    $service->pushUpdate();

    expect($service->buildPayload()['sensors'][0]['alarm'])->toBeNull();
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
