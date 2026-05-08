@php
    // Primary sensor = first in display_order, fall back to "no data" placeholder
    $primary = collect($sensors ?? [])->first() ?? ['status' => 'no_data', 'name' => '—'];
    $isStale = $primary['is_stale'] ?? false;
@endphp

<x-trmnl::view class="view--quadrant">
    <x-trmnl::layout class="layout--col layout--center {{ $isStale ? 'opacity-50' : '' }}">
        <span class="label">
            {{ $primary['name'] }}
            @if ($primary['battery_low'] ?? false) ▼ @endif
            @if ($primary['alarm'] ?? null) ⚠ @endif
        </span>

        @if (($primary['status'] ?? null) === 'no_data')
            <span class="value value--large">—</span>
            <span class="label label--small">no data</span>
        @else
            <span class="value value--xlarge">{{ $primary['temperature'] }}°C</span>
            <div class="flex flex--row gap--medium">
                <span class="value value--small">{{ $primary['humidity'] }}%</span>
                <span class="value value--small">{{ $primary['pressure_hpa'] }} hPa</span>
            </div>
            <span class="label label--small">{{ $primary['measured_at'] }}</span>
        @endif
    </x-trmnl::layout>
</x-trmnl::view>
