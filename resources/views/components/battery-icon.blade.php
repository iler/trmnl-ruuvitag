@props(['level' => 'unknown', 'size' => 20, 'strokeWidth' => 2])

@php
    // Picks the battery icon for the bucketed level emitted by RuuviService.
    //   full    → ≥ 2900 mV (fresh CR2477)
    //   medium  → 2500–2899 mV
    //   low     → < 2500 mV
    //   unknown → no battery_mv reported (Air sensors, older firmwares)
    //             — render the empty frame so it reads as "no signal" rather
    //             than overstating the battery as either healthy or dead.
    $iconName = match ($level) {
        'full' => 'icon.battery-full',
        'medium' => 'icon.battery-medium',
        'low' => 'icon.battery-low',
        'unknown' => 'icon.battery',
        default => 'icon.battery',
    };
@endphp

<x-dynamic-component :component="$iconName" :size="$size" :strokeWidth="$strokeWidth" {{ $attributes }} />
