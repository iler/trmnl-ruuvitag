<?php

use App\Services\Ruuvi\RuuviService;
use Bnussbau\LaravelTrmnl\Facades\LaravelTrmnl as Trmnl;
use Illuminate\Support\Facades\Route;

// Public/internal: returns the latest merge payload as JSON.
// Useful for debugging and as a fallback polling endpoint.
Route::get('/data', function (RuuviService $svc) {
    return response()->json($svc->buildPayload());
});

// Manual trigger — handy for testing without waiting for the scheduler.
Route::post('/refresh', function (RuuviService $svc) {
    $svc->pushUpdate();

    return response()->json(['ok' => true]);
});

// Render endpoint — required for public plugins, optional for private.
Route::post('/render', function () {
    return response()->json(
        Trmnl::renderScreen(
            'trmnl.full',
            'trmnl.half_horizontal',
            'trmnl.half_vertical',
            'trmnl.quadrant',
        )
    );
});
