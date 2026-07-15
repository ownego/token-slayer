<x-filament-widgets::widget>
    <x-filament::section heading="Current quota">
        @if ($gauge === null)
            <p style="opacity:.6; font-size:.875rem;">No quota data for this account.</p>
        @else
            <div style="max-width:26rem;">
                @include('filament.widgets.partials.gauge-card', ['g' => $gauge])
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
