<x-trmnl::view class="view--half_horizontal">
    <x-trmnl::layout class="layout--col">
        <div class="title title--small">Indoor sensors</div>
        <div class="flex flex--col gap--small">
            @foreach (array_slice($sensors ?? [], 0, 4) as $sensor)
                <div class="flex flex--row stretch-x {{ $sensor['is_stale'] ?? false ? 'opacity-50' : '' }}">
                    <span class="label stretch-x">
                        {{ $sensor['name'] }}
                        @if ($sensor['battery_low'] ?? false) ▼ @endif
                    </span>
                    @if (($sensor['status'] ?? null) === 'no_data')
                        <span class="value value--xsmall">—</span>
                    @else
                        <span class="value value--small">{{ $sensor['temperature'] }}°</span>
                        <span class="value value--xsmall">{{ $sensor['humidity'] }}%</span>
                    @endif
                </div>
            @endforeach
        </div>
    </x-trmnl::layout>
    <x-trmnl::title-bar title="Ruuvi" :instance="$updated_at ?? ''" />
</x-trmnl::view>
