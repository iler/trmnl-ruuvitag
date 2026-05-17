<?php

return [
    'api_token' => env('RUUVI_API_TOKEN'),
    'api_base_url' => env('RUUVI_API_BASE', 'https://network.ruuvi.com'),
    'display_timezone' => env('RUUVI_DISPLAY_TZ', 'Europe/Helsinki'),
    'cache_ttl' => (int) env('RUUVI_CACHE_TTL', 300),
    'stale_after' => (int) env('RUUVI_STALE_AFTER', 1800),
];
