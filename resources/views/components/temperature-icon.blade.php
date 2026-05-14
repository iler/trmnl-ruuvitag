@props(['temperature', 'size' => 32, 'strokeWidth' => 2])

@php
    // Picks the visual cue for the current reading.
    //   ≤ 0°C        → frost (snowflake)
    //   0 < t ≤ 20°C → temperate (thermometer)
    //   > 20°C       → hot (thermometer + sun)
    //   null         → fall back to a plain thermometer; caller is showing
    //                  a no-data state and still wants the metric badge.
    $iconName = match (true) {
        $temperature === null => 'icon.thermometer',
        $temperature <= 0 => 'icon.thermometer-snowflake',
        $temperature > 20 => 'icon.thermometer-sun',
        default => 'icon.thermometer',
    };
@endphp

<x-dynamic-component :component="$iconName" :size="$size" :strokeWidth="$strokeWidth" {{ $attributes }} />
