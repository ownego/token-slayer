<x-filament-widgets::widget>
    <x-filament::section heading="Fleet quota">
        <div style="display:flex; align-items:baseline; justify-content:space-between; gap:.5rem; margin-bottom:.75rem; padding-bottom:.6rem; border-bottom:1px solid rgba(120,120,140,.16);">
            <span style="font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; opacity:.6;">Total usage (all accounts)</span>
            <span style="font-size:1.05rem; font-weight:700; font-variant-numeric:tabular-nums; font-family:ui-monospace,monospace;">{{ number_format($totalUsage) }}</span>
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:.75rem;">
            @forelse ($gauges as $g)
                @include('filament.widgets.partials.gauge-card', ['g' => $g, 'members' => $contributors[$g['account_id']] ?? [], 'accountTotal' => $accountTotals[$g['account_id']] ?? 0])
            @empty
                <p style="opacity:.6; font-size:.875rem;">No accounts yet.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
