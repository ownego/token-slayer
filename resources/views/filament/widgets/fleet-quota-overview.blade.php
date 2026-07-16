<x-filament-widgets::widget>
    <x-filament::section heading="Fleet quota">
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:.75rem;">
            @forelse ($gauges as $g)
                @include('filament.widgets.partials.gauge-card', ['g' => $g, 'members' => $contributors[$g['account_id']] ?? []])
            @empty
                <p style="opacity:.6; font-size:.875rem;">No accounts yet.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
