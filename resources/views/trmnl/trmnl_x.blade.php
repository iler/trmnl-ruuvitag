@php
    $sensors = $sensors ?? [];
    $total = count($sensors);
    $alarmCount = collect($sensors)->filter(fn ($s) => ! empty($s['alarm']))->count();
    $lowBatCount = collect($sensors)->filter(fn ($s) => ! empty($s['battery_low']))->count();
    $staleCount = collect($sensors)->filter(fn ($s) => ! empty($s['is_stale']))->count();

    // Until plugins.css ships a TRMNL X variant, our preview wrapper forces
    // the canvas to 1872x1404 via inline style. Card sizing below is tuned
    // for that canvas; the container query in the stylesheet lets cards
    // adapt cleanly if a smaller view variant is ever swapped in.
@endphp

<style>
@layer ruuvi {
    .ruuvi {
        --ruuvi-gap-canvas: 1.5rem;
        --ruuvi-gap-card: 0.5rem;
        --ruuvi-gap-row: 1rem;
        --ruuvi-pad-canvas: 2rem;
        --ruuvi-pad-card-block: 1rem;
        --ruuvi-pad-card-inline: 1.25rem;
        --ruuvi-radius-card: 0.375rem;
        --ruuvi-rule: 1.5px solid currentColor;
        --ruuvi-rule-strong: 3px solid currentColor;
        --ruuvi-stripe-alert: 8px;
        --ruuvi-opacity-stale: 0.55;
        --ruuvi-opacity-muted: 0.4;

        display: flex;
        flex-direction: column;
        inline-size: 100%;
        block-size: 100%;
        padding: var(--ruuvi-pad-canvas);
        gap: var(--ruuvi-gap-canvas);
        box-sizing: border-box;
    }

    /* HEADER BAND */
    .ruuvi__header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding-block-end: 1rem;
        border-block-end: var(--ruuvi-rule-strong);
    }

    .ruuvi__title {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .ruuvi__pills {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    /* CARD GRID — 5 cols × 2 rows on TRMNL X */
    .ruuvi__grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        grid-template-rows: repeat(2, 1fr);
        gap: var(--ruuvi-gap-row);
        flex: 1 1 auto;
        min-block-size: 0;
    }

    /* CARDS */
    .ruuvi-card {
        container: ruuvi-card / inline-size;
        display: flex;
        flex-direction: column;
        padding: var(--ruuvi-pad-card-block) var(--ruuvi-pad-card-inline);
        gap: var(--ruuvi-gap-card);
        border: var(--ruuvi-rule);
        border-radius: var(--ruuvi-radius-card);
        box-sizing: border-box;
        overflow: hidden;
        min-inline-size: 0;
    }

    .ruuvi-card--primary {
        border-width: 3px;
    }

    /* Alert stripe lights up when the card contains an alarm chip OR a
       low-battery chip — no PHP-side coordination needed. */
    .ruuvi-card:has(.ruuvi-card__chip--alarm),
    .ruuvi-card:has(.ruuvi-card__chip--low-bat) {
        border-block-start-width: var(--ruuvi-stripe-alert);
    }

    .ruuvi-card.is-stale {
        opacity: var(--ruuvi-opacity-stale);
    }

    /* CARD HEAD: index left, chips + battery right */
    .ruuvi-card__head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
        min-block-size: 1.75rem;
    }

    .ruuvi-card__status {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-shrink: 0;
    }

    .ruuvi-card__chip-inner {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }

    .ruuvi-card__battery--unknown {
        opacity: var(--ruuvi-opacity-muted);
    }

    /* ROOM NAME */
    .ruuvi-card__name {
        line-height: 1.05;
        text-wrap: balance;
    }

    /* HERO: threshold icon sits at the top-left, temperature reading below. */
    .ruuvi-card__hero {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: center;
        gap: 0.25rem;
        flex: 1 1 auto;
    }

    .ruuvi-card__temp {
        display: flex;
        align-items: baseline;
        gap: 0.25rem;
        min-inline-size: 0;
    }

    .ruuvi-card__temp-badge {
        flex-shrink: 0;
    }

    /* STATS ROW: humidity + pressure */
    .ruuvi-card__stats {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding-block-start: 0.5rem;
        border-block-start: var(--ruuvi-rule);
    }

    .ruuvi-card__stat {
        display: flex;
        flex-direction: column;
    }

    .ruuvi-card__stat--right {
        align-items: flex-end;
    }

    .ruuvi-card__stat-label {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }

    /* If the canvas ever shrinks and cards squeeze below ~22ch, drop the
       right-side temperature icon to keep the value legible. */
    @container ruuvi-card (inline-size < 22ch) {
        .ruuvi-card__temp-badge {
            display: none;
        }

        .ruuvi-card__stat-label {
            gap: 0.125rem;
        }
    }
}
</style>

<x-trmnl::view class="view--full">
    <div class="ruuvi">

        {{-- HEADER BAND --}}
        <header class="ruuvi__header">
            <div class="ruuvi__title">
                <span class="title title--xxlarge">Indoor climate</span>
                <span class="label label--gray label--base">{{ $total }} stations · {{ $updated_at ?? '—' }} · Helsinki</span>
            </div>

            <div class="ruuvi__pills">
                @if ($alarmCount > 0)
                    <x-trmnl::label variant="error" size="large">{{ $alarmCount }} alert{{ $alarmCount > 1 ? 's' : '' }}</x-trmnl::label>
                @endif
                @if ($lowBatCount > 0)
                    <x-trmnl::label variant="warning" size="large">{{ $lowBatCount }} low battery</x-trmnl::label>
                @endif
                @if ($staleCount > 0)
                    <x-trmnl::label variant="gray" size="large">{{ $staleCount }} stale</x-trmnl::label>
                @endif
                @if ($alarmCount + $lowBatCount + $staleCount === 0 && $total > 0)
                    <x-trmnl::label variant="success" size="large">all nominal</x-trmnl::label>
                @endif
            </div>
        </header>

        {{-- CARD GRID --}}
        <div class="ruuvi__grid">
            @foreach ($sensors as $idx => $sensor)
                @php
                    $isStale = $sensor['is_stale'] ?? false;
                    $hasAlarm = ! empty($sensor['alarm']);
                    $batteryLevel = $sensor['battery_level'] ?? 'unknown';
                    $noData = ($sensor['status'] ?? null) === 'no_data';
                @endphp

                <article @class([
                    'ruuvi-card',
                    'ruuvi-card--primary' => $idx === 0,
                    'is-stale' => $isStale,
                ])>
                    {{-- Card head: index + status row --}}
                    <div class="ruuvi-card__head">
                        <span class="label label--small label--gray">{{ str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT) }}</span>

                        <div class="ruuvi-card__status">
                            @if ($hasAlarm)
                                <x-trmnl::label variant="error" size="small" class="ruuvi-card__chip--alarm">
                                    <span class="ruuvi-card__chip-inner">
                                        <x-icon.triangle-alert :size="14" /> {{ $sensor['alarm'] }}
                                    </span>
                                </x-trmnl::label>
                            @endif
                            @if ($isStale)
                                <x-trmnl::label variant="gray-out" size="small">
                                    <span class="ruuvi-card__chip-inner">
                                        <x-icon.clock :size="14" /> stale
                                    </span>
                                </x-trmnl::label>
                            @endif
                            @if ($batteryLevel === 'low')
                                <x-trmnl::label variant="warning" size="small" class="ruuvi-card__chip--low-bat">low bat</x-trmnl::label>
                            @endif
                            <x-battery-icon
                                :level="$batteryLevel"
                                :size="22"
                                @class(['ruuvi-card__battery--unknown' => $batteryLevel === 'unknown'])
                            />
                        </div>
                    </div>

                    {{-- Room name --}}
                    <span class="title title--large ruuvi-card__name">{{ $sensor['name'] }}</span>

                    {{-- Hero temperature --}}
                    @if ($noData)
                        <div class="ruuvi-card__hero">
                            <x-icon.thermometer :size="32" class="ruuvi-card__temp-badge ruuvi-card__battery--unknown" />
                            <span class="value value--xxlarge">—</span>
                        </div>
                        <span class="label label--gray label--small">awaiting reading</span>
                    @else
                        <div class="ruuvi-card__hero">
                            <x-temperature-icon
                                :temperature="$sensor['temperature']"
                                :size="32"
                                class="ruuvi-card__temp-badge"
                            />
                            <div class="ruuvi-card__temp">
                                <span class="value value--xxlarge value--tnums">{{ $sensor['temperature'] }}</span>
                                <span class="value value--base text--muted">°C</span>
                            </div>
                        </div>

                        {{-- Humidity + pressure --}}
                        <div class="ruuvi-card__stats">
                            <div class="ruuvi-card__stat">
                                <span class="value value--large value--tnums">{{ $sensor['humidity'] ?? '—' }}<span class="value value--small text--muted">%</span></span>
                                <span class="label label--gray label--small ruuvi-card__stat-label">
                                    <x-icon.droplet :size="12" /> Humidity
                                </span>
                            </div>
                            <div class="ruuvi-card__stat ruuvi-card__stat--right">
                                <span class="value value--large value--tnums">{{ $sensor['pressure_hpa'] ?? '—' }}</span>
                                <span class="label label--gray label--small ruuvi-card__stat-label">
                                    <x-icon.gauge :size="12" /> hPa
                                </span>
                            </div>
                        </div>
                    @endif

                    {{-- Footer meta --}}
                    <div class="ruuvi-card__foot">
                        <span class="label label--gray label--small">
                            @if ($noData) — @else read {{ $sensor['measured_at'] }} @endif
                        </span>
                    </div>
                </article>
            @endforeach
        </div>
    </div>

    <x-trmnl::title-bar
        title="Ruuvi · Indoor climate"
        :instance="$updated_at ?? ''"
    />
</x-trmnl::view>
