<x-trmnl::view class="view--half_vertical">
    <x-trmnl::layout>
        <div class="title title--small">Indoor sensors</div>
        <div class="grid grid--cols-2 gap--small">
            @foreach (array_slice($sensors ?? [], 0, 6) as $sensor)
                <div class="item {{ $sensor['is_stale'] ?? false ? 'opacity-50' : '' }}">
                    <span class="label label--small">
                        {{ $sensor['name'] }}
                        @if ($sensor['battery_low'] ?? false) ▼ @endif
                    </span>
                    @if (($sensor['status'] ?? null) === 'no_data')
                        <span class="value value--xsmall">no data</span>
                    @else
                        <span class="value value--medium">{{ $sensor['temperature'] }}°C</span>
                        <span class="label label--small">
                            {{ $sensor['humidity'] }}% · {{ $sensor['measured_at'] }}
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    </x-trmnl::layout>
    <x-trmnl::title-bar title="Ruuvi" :instance="$updated_at ?? ''" />
</x-trmnl::view>
