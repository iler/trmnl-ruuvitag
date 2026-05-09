<?php

use App\Services\Ruuvi\RuuviService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn (RuuviService $svc) => $svc->pushUpdate())
    ->name('ruuvi:push-update')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
