<?php

use App\Models\SensorReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ruuvi-cloud-fetch');
    Carbon::setTestNow('2026-05-09 12:00:00');
    // pushUpdate() dispatches UpdateScreenContentJob synchronously, which would
    // make a real HTTP POST to TRMNL_WEBHOOK_URL. We don't fake that URL in the
    // per-test Http::fake() blocks, so faking the bus keeps the test focused on
    // the Ruuvi-side cycle and avoids a stray cURL DNS failure.
    Bus::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('exits 0 and persists a reading on a healthy cycle', function () {
    Http::fake([
        'network.ruuvi.com/sensors-dense*' => Http::response([
            'data' => [
                'sensors' => [[
                    'sensor' => 'AA:BB:CC:DD:EE:FF',
                    'name' => 'Living Room',
                    'measurements' => [[
                        'data' => '0201061BFF99040512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F',
                        'timestamp' => Carbon::now()->subSeconds(30)->getTimestamp(),
                        'rssi' => -65,
                    ]],
                    'alerts' => [],
                ]],
            ],
        ], 200),
    ]);

    $this->artisan('ruuvi:doctor')
        ->expectsOutputToContain('Ruuvi cycle completed')
        ->expectsOutputToContain('Living Room')
        ->assertExitCode(0);

    expect(SensorReading::count())->toBe(1);
});

it('exits 1 when the API returns no sensors', function () {
    Http::fake([
        'network.ruuvi.com/sensors-dense*' => Http::response([
            'data' => ['sensors' => []],
        ], 200),
    ]);

    $this->artisan('ruuvi:doctor')->assertExitCode(1);
});

it('exits 1 when the API rejects auth', function () {
    Http::fake([
        'network.ruuvi.com/sensors-dense*' => Http::response('', 401),
    ]);

    $this->artisan('ruuvi:doctor')->assertExitCode(1);
});
