@props(['sensor', 'showBattery' => true])

<div class="item {{ $sensor['is_stale'] ?? false ? 'opacity-50' : '' }}">
    <div class="meta">
        <span class="label">
            {{ $sensor['name'] }}
            @if ($sensor['battery_low'] ?? false) ▼ @endif
            @if ($sensor['alarm'] ?? null) ⚠ @endif
        </span>
    </div>
    <div class="content">
        @if (($sensor['status'] ?? null) === 'no_data')
            <span class="value value--xsmall">no data</span>
        @else
            <span class="value value--small">{{ $sensor['temperature'] }}°C</span>
            <span class="value value--xsmall">{{ $sensor['humidity'] }}%</span>
            <span class="value value--xsmall">{{ $sensor['pressure_hpa'] }} hPa</span>
            <span class="label label--small">{{ $sensor['measured_at'] }}</span>
        @endif
    </div>
</div>
