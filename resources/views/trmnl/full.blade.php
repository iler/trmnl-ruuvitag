<x-trmnl::view class="view--full">
    <x-trmnl::layout>
        <div class="grid grid--cols-1 gap--medium">
            <div class="title">Indoor sensors</div>

            <table class="table">
                <thead>
                    <tr>
                        <th><span class="title title--small">Sensor</span></th>
                        <th><span class="title title--small">Temp</span></th>
                        <th><span class="title title--small">Humidity</span></th>
                        <th><span class="title title--small">Pressure</span></th>
                        <th><span class="title title--small">Updated</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sensors ?? [] as $sensor)
                        <tr class="{{ $sensor['is_stale'] ?? false ? 'opacity-50' : '' }}">
                            <td>
                                <span class="value value--small">
                                    {{ $sensor['name'] }}
                                    @if ($sensor['battery_low'] ?? false) ▼ @endif
                                    @if ($sensor['alarm'] ?? null) ⚠ @endif
                                </span>
                            </td>
                            @if (($sensor['status'] ?? null) === 'no_data')
                                <td colspan="4"><span class="value value--xsmall">no data</span></td>
                            @else
                                <td><span class="value value--small">{{ $sensor['temperature'] }}°C</span></td>
                                <td><span class="value value--small">{{ $sensor['humidity'] }}%</span></td>
                                <td><span class="value value--small">{{ $sensor['pressure_hpa'] }} hPa</span></td>
                                <td><span class="label">{{ $sensor['measured_at'] }}</span></td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-trmnl::layout>
    <x-trmnl::title-bar
        title="Ruuvi"
        :instance="$updated_at ?? ''"
    />
</x-trmnl::view>
