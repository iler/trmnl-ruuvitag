@php
    $screenProps = $screenProps ?? [];
    // Force the canvas to TRMNL X's native 1872x1404 when previewing the X
    // layout — the framework CSS doesn't ship an explicit screen-w/h for the
    // X yet, so without this override the preview falls back to 800x480 and
    // typography looks wildly oversized.
    $forceSize = $screenProps['forceSize'] ?? null;
@endphp

<x-trmnl::screen
    :colorDepth="$screenProps['colorDepth'] ?? '1bit'"
    :deviceOrientation="$screenProps['deviceOrientation'] ?? null"
    :scaleLevel="$screenProps['scaleLevel'] ?? null"
>
    @if ($forceSize)
        <style>
            .screen, .screen .layout, .screen .view { width: {{ $forceSize[0] }}px !important; height: {{ $forceSize[1] }}px !important; }
            .screen .view { display: flex; flex-direction: column; }
        </style>
    @endif

    @include($view, ['sensors' => $sensors, 'updated_at' => $updated_at])
</x-trmnl::screen>
