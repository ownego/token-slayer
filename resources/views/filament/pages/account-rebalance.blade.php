<x-filament-panels::page>
    <x-filament::section heading="Rebalance recommendations">
        @if (empty($moves) && $unresolvedOverflowTokens === 0.0)
            <p style="opacity:.6;">Click "Recalculate" to see recommendations.</p>
        @endif

        @if (! empty($moves))
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">User</th>
                            <th style="padding:.4rem .6rem;">From account</th>
                            <th style="padding:.4rem .6rem;">To account</th>
                            <th style="padding:.4rem .6rem;">Demand/day</th>
                            <th style="padding:.4rem .6rem;">Confidence</th>
                            <th style="padding:.4rem .6rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($moves as $move)
                            <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                <td style="padding:.4rem .6rem;">{{ \App\Models\User::find($move['userId'])?->displayHandle() }}</td>
                                <td style="padding:.4rem .6rem;">
                                    {{ \App\Models\Account::find($move['fromAccountId'])?->email }}
                                    ({{ $move['fromProjectedBefore'] }}% → {{ $move['fromProjectedAfter'] }}%)
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ \App\Models\Account::find($move['toAccountId'])?->email }}
                                    ({{ $move['toProjectedBefore'] }}% → {{ $move['toProjectedAfter'] }}%)
                                </td>
                                <td style="padding:.4rem .6rem;">{{ number_format($move['demandTokensPerDay']) }} ({{ $move['demandBasis'] }})</td>
                                <td style="padding:.4rem .6rem;">
                                    @if ($move['confident'])
                                        <x-filament::badge color="success">Confident</x-filament::badge>
                                    @else
                                        <x-filament::badge color="warning">Not enough data</x-filament::badge>
                                    @endif
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ ($this->switchUserAction)(['userId' => $move['userId'], 'fromAccountId' => $move['fromAccountId'], 'toAccountId' => $move['toAccountId']]) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($unresolvedOverflowTokens > 0.0)
            <p style="color:var(--danger-500, #dc2626); margin-top:.75rem;">
                Could not find enough headroom for {{ number_format($unresolvedOverflowTokens) }} remaining tokens — more capacity is needed.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Members" style="margin-top:1.5rem;">
        <label style="display:flex; align-items:center; gap:.5rem; font-size:.85rem;">
            <input type="checkbox" wire:model.live="showUntracked" />
            Show unverified (untracked) users
        </label>

        <div style="display:flex; gap:1rem; align-items:center; font-size:.75rem; opacity:.7; margin-top:.5rem;">
            <span style="display:flex; align-items:center; gap:.35rem;">
                <span style="display:inline-block; width:.5rem; height:.5rem; border-radius:9999px; background:#22c55e;"></span>
                Tracked
            </span>
            <span style="display:flex; align-items:center; gap:.35rem;">
                <span style="display:inline-block; width:.5rem; height:.5rem; border-radius:9999px; background:#3b82f6;"></span>
                Pending
            </span>
            <span style="display:flex; align-items:center; gap:.35rem;">
                <span style="display:inline-block; width:.5rem; height:.5rem; border-radius:9999px; background:#ef4444;"></span>
                Untracked
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
                            @elseif ($row['status'] === 'untracked')
                                <span style="display:inline-block; width:.5rem; height:.5rem; border-radius:9999px; background:#ef4444;" title="Untracked"></span>
                            @endif
                            {{ $row['handle'] }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
