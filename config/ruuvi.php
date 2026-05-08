<?php

return [
    'api_token' => env('RUUVI_API_TOKEN'),
    'api_base_url' => env('RUUVI_API_BASE', 'https://network.ruuvi.com'),
    'display_timezone' => env('RUUVI_DISPLAY_TZ', 'Europe/Helsinki'),
    'cache_ttl' => (int) env('RUUVI_CACHE_TTL', 300),
    'stale_after' => (int) env('RUUVI_STALE_AFTER', 1800),

    // Seed list — synced into the sensors table by `php artisan ruuvi:sync-sensors`
    'sensors' => [
        // [
        //     'mac' => 'AA:BB:CC:DD:EE:FF',
        //     'display_name' => 'Living Room',
        //     'temp_min' => 18, 'temp_max' => 26,
        //     'humidity_min' => 30, 'humidity_max' => 60,
        //     'battery_low_mv' => 2500,
        //     'display_order' => 1,
        // ],
    ],
];
