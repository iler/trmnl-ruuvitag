<?php

use App\Services\Ruuvi\RuuviService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn (RuuviService $svc) => $svc->pushUpdate())
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('ruuvi:push-update');
