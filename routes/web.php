<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Mock sensor set used by both preview routes — covers every state we render
// (low battery, alarm, stale, no_data, null humidity/pressure).
$mockSensors = [
    ['name' => 'Living room', 'temperature' => 21.4,  'humidity' => 41,   'pressure_hpa' => 1017, 'measured_at' => '14:32', 'status' => 'ok',    'battery_level' => 'full'],
    ['name' => 'Kitchen',     'temperature' => 22.8,  'humidity' => 48,   'pressure_hpa' => 1017, 'measured_at' => '14:31', 'status' => 'ok',    'battery_level' => 'full'],
    ['name' => 'Bedroom',     'temperature' => 19.6,  'humidity' => 44,   'pressure_hpa' => 1016, 'measured_at' => '14:32', 'status' => 'ok',    'battery_level' => 'medium'],
    ['name' => 'Office',      'temperature' => 23.1,  'humidity' => 39,   'pressure_hpa' => 1017, 'measured_at' => '14:30', 'status' => 'ok',    'battery_level' => 'low',    'battery_low' => true],
    ['name' => 'Bathroom',    'temperature' => 24.3,  'humidity' => 71,   'pressure_hpa' => 1017, 'measured_at' => '14:29', 'status' => 'ok',    'battery_level' => 'medium', 'alarm' => 'humidity'],
    ['name' => 'Sauna',       'temperature' => 18.2,  'humidity' => 52,   'pressure_hpa' => 1017, 'measured_at' => '13:58', 'status' => 'stale', 'battery_level' => 'full', 'is_stale' => true],
    ['name' => 'Cellar',      'temperature' => 12.7,  'humidity' => 68,   'pressure_hpa' => 1018, 'measured_at' => '14:28', 'status' => 'ok',    'battery_level' => 'medium'],
    ['name' => 'Garage',      'temperature' => 9.4,   'humidity' => 58,   'pressure_hpa' => 1017, 'measured_at' => '14:30', 'status' => 'ok',    'battery_level' => 'full'],
    ['name' => 'Freezer',     'temperature' => -18.1, 'humidity' => null, 'pressure_hpa' => null, 'measured_at' => '14:27', 'status' => 'ok',    'battery_level' => 'unknown'],
    ['name' => 'Balcony',     'status' => 'no_data', 'battery_level' => 'unknown'],
];

// Standard TRMNL (800x480, 1-bit) preview.
Route::get('/preview/trmnl/{size}', function (string $size) use ($mockSensors) {
    $allowed = ['full', 'half_horizontal', 'half_vertical', 'quadrant'];
    abort_unless(in_array($size, $allowed, true), 404);

    return view('trmnl._preview', [
        'view' => "trmnl.{$size}",
        'sensors' => $mockSensors,
        'updated_at' => '14:32',
        'screenProps' => [],
    ]);
});

// TRMNL X (1872x1404 landscape, 16-grayscale) preview.
Route::get('/preview/trmnl-x', function () use ($mockSensors) {
    return view('trmnl._preview', [
        'view' => 'trmnl.trmnl_x',
        'sensors' => $mockSensors,
        'updated_at' => '14:32',
        'screenProps' => [
            'colorDepth' => '4bit',
            'deviceOrientation' => 'landscape',
            'forceSize' => [1872, 1404],
        ],
    ]);
});
