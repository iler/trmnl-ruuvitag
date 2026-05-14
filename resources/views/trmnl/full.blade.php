@php
    $sensors = $sensors ?? [];
    $primary = $sensors[0] ?? ['status' => 'no_data', 'name' => 'Awaiting'];
    $others = array_slice($sensors, 1);
    $primaryStale = $primary['is_stale'] ?? false;
    $primaryNoData = ($primary['status'] ?? null) === 'no_data';
@endphp

<x-trmnl::view class="view--full">
    <x-trmnl::layout class="layout--col">
        <x-trmnl::grid cols="12" class="gap--large stretch-y">
            {{-- HERO: primary sensor as editorial showpiece --}}
            <x-trmnl::col span="5">
                <div class="flex flex--col stretch-y gap--medium {{ $primaryStale ? 'opacity-50' : '' }}">
                    <div class="flex flex--col gap--xxsmall">
                        <span class="label label--small label--underline">Station 01</span>
                        <span class="title">{{ $primary['name'] }}</span>
                    </div>

                    @if ($primaryNoData)
                        <span class="value value--xlarge">—</span>
                        <span class="label label--small">awaiting reading</span>
                    @else
                        <div class="flex flex--row gap--xsmall">
                            <span class="value value--xlarge">{{ $primary['temperature'] }}</span>
                            <span class="value value--medium">°C</span>
                        </div>

                        <x-trmnl::divider />

                        <x-trmnl::grid cols="2" class="gap--medium">
                            <div class="flex flex--col gap--xxsmall">
                                <span class="label label--small">Humidity</span>
                                <span class="value value--medium">{{ $primary['humidity'] ?? '—' }}%</span>
                            </div>
                            <div class="flex flex--col gap--xxsmall">
                                <span class="label label--small">Pressure</span>
                                <span class="value value--medium">{{ $primary['pressure_hpa'] ?? '—' }}<span class="value value--xsmall"> hPa</span></span>
                            </div>
                        </x-trmnl::grid>

                        <div class="stretch-y"></div>

                        <span class="label label--small">
                            read at {{ $primary['measured_at'] }}
                            @if ($primary['battery_low'] ?? false)
                                · low battery
                            @endif
                            @if ($primary['alarm'] ?? null)
                                · ⚠ {{ $primary['alarm'] }}
                            @endif
                        </span>
                    @endif
                </div>
            </x-trmnl::col>

            {{-- LEDGER: remaining sensors as indexed table --}}
            <x-trmnl::col span="7">
                <table class="table table--small table--indexed">
                    <thead>
                        <tr>
                            <th></th>
                            <th><span class="title title--small">Station</span></th>
                            <th class="text--right"><span class="title title--small">°C</span></th>
                            <th class="text--right"><span class="title title--small">RH</span></th>
                            <th class="text--right"><span class="title title--small">hPa</span></th>
                            <th class="text--right"><span class="title title--small">Time</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($others as $idx => $sensor)
                            <tr class="{{ $sensor['is_stale'] ?? false ? 'opacity-50' : '' }}">
                                <td>
                                    <x-trmnl::meta>
                                        <span class="index">{{ $idx + 2 }}</span>
                                    </x-trmnl::meta>
                                </td>
                                <td>
                                    <div class="flex flex--row gap--xsmall">
                                        <span data-clamp="1" class="label">{{ $sensor['name'] }}</span>
                                        @if ($sensor['battery_low'] ?? false)
                                            <x-trmnl::label variant="outline" size="small">bat</x-trmnl::label>
                                        @endif
                                        @if ($sensor['alarm'] ?? null)
                                            <x-trmnl::label variant="outline" size="small">alert</x-trmnl::label>
                                        @endif
                                    </div>
                                </td>
                                @if (($sensor['status'] ?? null) === 'no_data')
                                    <td colspan="4" class="text--right"><span class="label label--small">no data</span></td>
                                @else
                                    <td class="text--right"><span class="value value--small">{{ $sensor['temperature'] }}°</span></td>
                                    <td class="text--right"><span class="value value--small">{{ $sensor['humidity'] ?? '—' }}%</span></td>
                                    <td class="text--right"><span class="value value--small">{{ $sensor['pressure_hpa'] ?? '—' }}</span></td>
                                    <td class="text--right"><span class="label label--small">{{ $sensor['measured_at'] }}</span></td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text--center"><span class="label label--small">no additional stations</span></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-trmnl::col>
        </x-trmnl::grid>
    </x-trmnl::layout>
    <x-trmnl::title-bar
        title="Ruuvi · Indoor climate"
        :instance="$updated_at ?? ''"
    />
</x-trmnl::view>
