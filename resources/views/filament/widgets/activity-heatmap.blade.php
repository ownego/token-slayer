<x-filament-widgets::widget>
    <x-filament::section heading="Activity by hour and weekday">
        {{-- Inline grid so the layout holds inside the Filament panel regardless
             of which utility classes the panel's stylesheet ships. --}}
        <div style="overflow-x:auto;">
            <div style="display:grid; grid-template-columns:2.75rem repeat(24, minmax(14px, 1fr)); gap:3px; min-width:660px;">
                <div></div>
                @for ($h = 0; $h < 24; $h++)
                    <div style="font-size:.6rem; text-align:center; opacity:.5; font-variant-numeric:tabular-nums;">{{ $h }}</div>
                @endfor

                @foreach ($weekdays as $wd => $label)
                    <div style="font-size:.72rem; opacity:.65; display:flex; align-items:center; padding-right:.35rem;">{{ $label }}</div>
                    @for ($h = 0; $h < 24; $h++)
                        @php($tokens = $cells["{$wd}:{$h}"]['tokens'] ?? 0)
                        @php($opacity = $tokens > 0 ? max(0.12, $tokens / $max) : 0)
                        <div
                            title="{{ $label }} {{ sprintf('%02d', $h) }}:00 — {{ number_format($tokens) }} tokens"
                            style="height:16px; border-radius:3px; background:{{ $tokens > 0 ? "rgba(217,119,6,{$opacity})" : 'rgba(120,120,140,.10)' }};"
                        ></div>
                    @endfor
                @endforeach
            </div>
        </div>

        {{-- Intensity legend. --}}
        <div style="display:flex; align-items:center; gap:.35rem; margin-top:.75rem; font-size:.68rem; opacity:.6;">
            <span>Less</span>
            <span style="width:13px; height:13px; border-radius:3px; background:rgba(120,120,140,.10);"></span>
            <span style="width:13px; height:13px; border-radius:3px; background:rgba(217,119,6,.35);"></span>
            <span style="width:13px; height:13px; border-radius:3px; background:rgba(217,119,6,.65);"></span>
            <span style="width:13px; height:13px; border-radius:3px; background:rgba(217,119,6,1);"></span>
            <span>More</span>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
