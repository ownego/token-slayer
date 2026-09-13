<x-filament-panels::page>
    <x-filament::section heading="Rebalance recommendations">
        <div style="display:flex; align-items:center; gap:.75rem; font-size:.85rem; flex-wrap:wrap;">
            <span style="opacity:.6;">Based on</span>
            <select wire:model.live="range" style="padding:.3rem .5rem; border-radius:.375rem; border:1px solid rgba(120,120,140,.3); background:transparent;">
                <option value="week">the last week</option>
                <option value="month">the last month</option>
                <option value="all">all time</option>
            </select>
            @if ($computed)
                <span style="opacity:.6;">
                    Planning to {{ 100 - ($summary['safety_margin_percent'] ?? 0) }}% of each account's measured weekly capacity.
                </span>
            @endif
        </div>

        @if (! $computed)
            <p style="opacity:.6; margin-top:.75rem;">Click "Recalculate" to see recommendations.</p>
        @elseif (empty($moves))
            <p style="opacity:.6; margin-top:.75rem;">
                No move would meaningfully relieve the fullest account — the fleet is already as balanced as
                reassignment can make it (fullest account: {{ number_format($summary['peak_fill_before_percent'], 1) }}% of its weekly capacity).
            </p>
        @else
            <p style="margin-top:.75rem; font-size:.85rem;">
                Fullest account goes from
                <strong>{{ number_format($summary['peak_fill_before_percent'], 1) }}%</strong>
                to
                <strong>{{ number_format($summary['peak_fill_after_percent'], 1) }}%</strong>
                of its weekly capacity if every move below is applied.
            </p>

            <div style="overflow-x:auto; margin-top:.75rem;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">User</th>
                            <th style="padding:.4rem .6rem;">From</th>
                            <th style="padding:.4rem .6rem;">To</th>
                            <th style="padding:.4rem .6rem;">Demand / week</th>
                            <th style="padding:.4rem .6rem;">Swap with</th>
                            <th style="padding:.4rem .6rem;">Confidence</th>
                            <th style="padding:.4rem .6rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($moves as $index => $move)
                            <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                <td style="padding:.4rem .6rem;">{{ $move['userLabel'] }}</td>
                                <td style="padding:.4rem .6rem;">
                                    {{ $move['fromAccountLabel'] }}
                                    <span style="opacity:.6;">({{ number_format($move['fromFillBeforePercent'], 0) }}% → {{ number_format($move['fromFillAfterPercent'], 0) }}%)</span>
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ $move['toAccountLabel'] }}
                                    <span style="opacity:.6;">({{ number_format($move['toFillBeforePercent'], 0) }}% → {{ number_format($move['toFillAfterPercent'], 0) }}%)</span>
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ number_format($move['demandWeeklyTokens']) }}
                                    <span style="opacity:.6;">({{ str_replace('_', ' ', $move['demandBasis']) }})</span>
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ $move['swapWithLabel'] ?? '—' }}
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    @if ($move['confident'])
                                        <x-filament::badge color="success">Confident</x-filament::badge>
                                    @else
                                        <x-filament::badge color="warning">Not enough data</x-filament::badge>
                                    @endif
                                </td>
                                <td style="padding:.4rem .6rem; white-space:nowrap;">
                                    {{ ($this->explainMoveAction)(['index' => $index]) }}
                                    {{ ($this->switchUserAction)(['userId' => $move['userId'], 'fromAccountId' => $move['fromAccountId'], 'toAccountId' => $move['toAccountId']]) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($computed && ($summary['unplaced_tokens'] ?? 0) > 0)
            <p style="color:var(--danger-500, #dc2626); margin-top:.75rem; font-size:.85rem;">
                {{ number_format($summary['unplaced_tokens']) }} tokens/week do not fit on any account even after rebalancing — the fleet needs more capacity, not a different arrangement.
            </p>
        @endif
    </x-filament::section>

    @if ($computed && ! empty($accounts))
        <x-filament::section heading="Fleet capacity" style="margin-top:1.5rem;">
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">Account</th>
                            <th style="padding:.4rem .6rem;">Measured capacity / week</th>
                            <th style="padding:.4rem .6rem;">Planned load</th>
                            <th style="padding:.4rem .6rem;">Members</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                <td style="padding:.4rem .6rem; font-family:monospace;">{{ $account['email'] }}</td>
                                <td style="padding:.4rem .6rem;">{{ number_format($account['capacity_tokens']) }} tokens</td>
                                <td style="padding:.4rem .6rem;">
                                    {{ number_format($account['fill_before_percent'], 0) }}% → {{ number_format($account['fill_after_percent'], 0) }}%
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ $account['members_before'] }} → {{ $account['members_after'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p style="opacity:.6; font-size:.75rem; margin-top:.5rem;">
                Capacity is measured from closed quota windows over {{ $summary['window_label'] ?? '' }}: the tokens an
                account consumed in a window, scaled up by the highest utilisation that consumption reached.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Members" style="margin-top:1.5rem;">
        <div style="display:flex; gap:1rem; align-items:center; font-size:.75rem; opacity:.7;">
            <span style="display:flex; align-items:center; gap:.35rem;">
                <span style="display:inline-block; width:.5rem; height:.5rem; border-radius:9999px; background:#22c55e;"></span>
                Tracked
            </span>
            <span style="display:flex; align-items:center; gap:.35rem;">
                <span style="display:inline-block; width:.5rem; height:.5rem; border-radius:9999px; background:#3b82f6;"></span>
                Pending
            </span>
        </div>

        @foreach ($this->memberRowsByAccount() as $accountId => $rows)
            <div style="margin-top:.75rem;">
                <span style="font-family:monospace; font-size:.8rem;">{{ \App\Models\Account::find($accountId)?->email }}</span>
                <ul style="list-style:none; padding-left:0; margin-top:.25rem;">
                    @foreach ($rows as $row)
                        <li style="display:flex; align-items:center; gap:.5rem; padding:.15rem 0;">
                            @if ($row['status'] === 'tracked')
                                <span style="display:inline-block; width:.5rem; height:.5rem; border-radius:9999px; background:#22c55e;" title="Tracked"></span>
                            @elseif ($row['status'] === 'pending')
                                <span style="display:inline-block; width:.5rem; height:.5rem; border-radius:9999px; background:#3b82f6;" title="Pending"></span>
                            @endif
                            {{ $row['handle'] }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
