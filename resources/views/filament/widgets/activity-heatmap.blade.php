<x-filament-widgets::widget>
    <x-filament::section heading="Activity by hour and weekday">
        {{-- Inline grid so the layout holds inside the Filament panel regardless
             of which utility classes the panel's stylesheet ships. Alpine keeps
             a readout of the hovered/clicked cell's metric. --}}
        <div x-data="{ info: '' }">
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
                            @php($cellInfo = $label.' '.sprintf('%02d', $h).':00 — '.number_format($tokens).' tokens')
                            <div
                                title="{{ $cellInfo }}"
                                x-on:mouseenter="info = @js($cellInfo)"
                                x-on:mouseleave="info = ''"
                                x-on:click="info = @js($cellInfo)"
                                style="height:16px; border-radius:3px; cursor:pointer; background:{{ $tokens > 0 ? "rgba(217,119,6,{$opacity})" : 'rgba(120,120,140,.10)' }};"
                            ></div>
                        @endfor
                    @endforeach
                </div>
            </div>

            {{-- Live readout of the focused cell. --}}
            <div style="margin-top:.6rem; font-size:.75rem; min-height:1.2rem; font-variant-numeric:tabular-nums;">
                <span x-show="! info" style="opacity:.5;">Hover or tap a cell to see its tokens.</span>
                <span x-show="info" x-text="info" style="font-weight:600; opacity:.85;"></span>
            </div>

            {{-- Intensity legend. --}}
            <div style="display:flex; align-items:center; gap:.35rem; margin-top:.4rem; font-size:.68rem; opacity:.6;">
                <span>Less</span>
                <span style="width:13px; height:13px; border-radius:3px; background:rgba(120,120,140,.10);"></span>
                <span style="width:13px; height:13px; border-radius:3px; background:rgba(217,119,6,.35);"></span>
                <span style="width:13px; height:13px; border-radius:3px; background:rgba(217,119,6,.65);"></span>
                <span style="width:13px; height:13px; border-radius:3px; background:rgba(217,119,6,1);"></span>
                <span>More</span>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
