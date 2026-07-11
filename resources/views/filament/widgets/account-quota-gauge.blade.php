<x-filament-widgets::widget>
    <x-filament::section heading="Current quota">
        @if ($gauge === null)
            <p class="text-sm text-gray-500">No quota data for this account.</p>
        @else
            <div @class([
                'rounded-lg border p-3 max-w-sm',
                'border-danger-400 bg-danger-50 dark:bg-danger-950/30' => $gauge['near_cap'],
                'border-gray-200 dark:border-white/10' => ! $gauge['near_cap'],
            ])>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium truncate">{{ $gauge['email'] }}</span>
                    @if ($gauge['near_cap'])
                        <span class="text-[10px] font-semibold text-danger-600">NEAR CAP</span>
                    @endif
                </div>

                @foreach (['5h' => $gauge['util_5h'], '7d' => $gauge['util_7d']] as $label => $pct)
                    <div class="mt-2">
                        <div class="flex justify-between text-xs text-gray-500">
                            <span>{{ $label }}</span>
                            <span>{{ $pct === null ? '—' : $pct.'%' }}</span>
                        </div>
                        <div class="h-1.5 rounded bg-gray-100 dark:bg-white/10">
                            <div class="h-1.5 rounded"
                                 style="width: {{ $pct ?? 0 }}%; background-color: {{ ($pct ?? 0) >= 90 ? '#dc2626' : (($pct ?? 0) >= 70 ? '#d97706' : '#059669') }};"></div>
                        </div>
                    </div>
                @endforeach

                <div class="mt-2 text-[11px] text-gray-400">
                    @if ($gauge['reset_7d_at'])
                        7d resets {{ $gauge['reset_7d_at']->diffForHumans() }}
                        @if ($gauge['projected_7d'] !== null) · proj {{ $gauge['projected_7d'] }}% @endif
                    @else
                        Never probed
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
