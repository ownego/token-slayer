<x-filament-panels::page>
    <x-filament::section heading="Rebalance recommendations">
        <div style="display:flex; align-items:center; gap:.75rem; font-size:.85rem; flex-wrap:wrap;">
            <span style="opacity:.6;">Based on</span>
            <select wire:model.live="range" style="padding:.3rem .5rem; border-radius:.375rem; border:1px solid rgba(120,120,140,.3); background:transparent;">
                <option value="week">the last week</option>
                <option value="month">the last month</option>
                <option value="all">all time</option>
            </select>
        </div>

        @if ($computed)
            <p style="margin-top:.5rem; font-size:.8rem; opacity:.6;">
                Every percentage here is <strong>worst case</strong>: what an account would carry if all of its
                members hit their heaviest week at once. That is what an arrangement has to survive, so it is
                normally well above 100%
                @if (! empty($capacity))
                    — on a typical week this fleet runs at {{ number_format($capacity['typical']['percent'], 0) }}%.
                    See <strong>Fleet sizing</strong> below.
                @endif
                People are placed to {{ 100 - ($summary['safety_margin_percent'] ?? 0) }}% of each account's capacity,
                leaving the rest as slack.
            </p>
        @endif

        @if (! $computed)
            <p style="opacity:.6; margin-top:.75rem;">Click "Recalculate" to see recommendations.</p>
        @elseif (empty($moves))
            <p style="opacity:.6; margin-top:.75rem;">
                No move would meaningfully relieve the fullest account — the fleet is already as balanced as
                reassignment can make it (fullest account: {{ number_format($summary['peak_fill_before_percent'], 1) }}% worst case).
            </p>
        @else
            <p style="margin-top:.75rem; font-size:.85rem;">
                Fullest account goes from
                <strong>{{ number_format($summary['peak_fill_before_percent'], 1) }}%</strong>
                to
                <strong>{{ number_format($summary['peak_fill_after_percent'], 1) }}%</strong>
                if every move below is applied. Accounts converge rather than all drop: the load has to go
                somewhere, and levelling it is what stops one account burning out days before the others.
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
            <p style="margin-top:.75rem; font-size:.85rem; opacity:.75;">
                In that worst case, {{ number_format($summary['unplaced_tokens']) }} tokens/week exceed the whole
                fleet's capacity — no arrangement can absorb them. Whether that means buying accounts depends on how
                close the fleet runs to its worst case: see <strong>Fleet sizing</strong>.
            </p>
        @endif
    </x-filament::section>

    @if ($computed && ! empty($capacity))
        <x-filament::section heading="Fleet sizing" style="margin-top:1.5rem;">
            <p style="font-size:.85rem; opacity:.7;">
                Two different questions, two different answers. Sizing for the worst case buys enough for a week in
                which every person's heaviest week lands at once — which is not the week the fleet usually lives in.
            </p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:1rem; margin-top:1rem;">
                @foreach ([['A typical week', $capacity['typical'], 'What the fleet normally gets through.'], ['The worst case', $capacity['worst_case'], "Everyone's heaviest week, all in the same week."]] as [$label, $figures, $blurb])
                    <div style="border:1px solid rgba(120,120,140,.2); border-radius:.5rem; padding:.75rem 1rem;">
                        <div style="opacity:.6; font-size:.75rem;">{{ $label }}</div>
                        <div style="font-size:1.75rem; font-weight:600; line-height:1.2; color:{{ $figures['percent'] > 100 ? 'var(--danger-500, #dc2626)' : 'inherit' }};">
                            {{ number_format($figures['percent'], 0) }}%
                        </div>
                        <div style="font-size:.8rem; opacity:.7;">
                            {{ number_format($figures['tokens']) }} tokens/week of {{ number_format($capacity['capacity_tokens']) }} capacity
                        </div>
                        <div style="font-size:.85rem; margin-top:.5rem;">
                            @if ($figures['accounts_needed'] === 0)
                                <x-filament::badge color="success">Fits — no new account needed</x-filament::badge>
                            @else
                                <x-filament::badge color="warning">At least {{ $figures['accounts_needed'] }} more {{ \Illuminate\Support\Str::plural('account', $figures['accounts_needed']) }} to stay under {{ 100 - $capacity['safety_margin_percent'] }}%</x-filament::badge>
                            @endif
                        </div>
                        <div style="font-size:.75rem; opacity:.55; margin-top:.4rem;">{{ $blurb }}</div>
                    </div>
                @endforeach
            </div>

            <div style="display:flex; align-items:center; gap:.75rem; font-size:.85rem; flex-wrap:wrap; margin-top:1.25rem;">
                <span>What if we added</span>
                <select wire:model.live="extraAccounts" style="padding:.3rem .5rem; border-radius:.375rem; border:1px solid rgba(120,120,140,.3); background:transparent;">
                    @foreach (range(0, 6) as $count)
                        <option value="{{ $count }}">{{ $count === 0 ? 'no' : $count }} {{ \Illuminate\Support\Str::plural('account', max(1, $count)) }}</option>
                    @endforeach
                </select>
                <span style="opacity:.6;">
                    assuming each is worth {{ number_format($capacity['assumed_capacity_tokens']) }} tokens/week, like a middling account here today.
                    This is the figure to trust over the badges above: they divide totals, which cannot account for a
                    person being indivisible, so they read low.
                </span>
            </div>

            @if ($capacity['projection'] !== null)
                <div style="margin-top:1rem;">
                    @php($target = 100 - $capacity['safety_margin_percent'])
                    @php($peak = $capacity['projection']['peak_fill_percent'])
                    <p style="font-size:.85rem;">
                        With {{ $capacity['projection']['extra_accounts'] }} more, the worst case puts the fullest
                        account at <strong>{{ number_format($peak, 0) }}%</strong>
                        @if ($capacity['projection']['overflow_tokens'] > 0)
                            and <strong style="color:var(--danger-500, #dc2626);">{{ number_format($capacity['projection']['overflow_tokens']) }}</strong>
                            tokens/week still exceed the fleet outright.
                        @elseif ($peak > $target)
                            — nothing is turned away any more, but it is above the {{ $target }}% planning target,
                            which is the difference between "never blocked" and "comfortable". That gap is why the
                            badge above asks for more accounts than it takes to merely fit.
                        @else
                            — everything fits with the {{ $target }}% slack intact.
                        @endif
                    </p>

                    <div style="overflow-x:auto; margin-top:.5rem;">
                        <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                            <thead>
                                <tr style="text-align:left; opacity:.6;">
                                    <th style="padding:.4rem .6rem;">Account</th>
                                    <th style="padding:.4rem .6rem;">Capacity / week</th>
                                    <th style="padding:.4rem .6rem;">Worst-case load</th>
                                    <th style="padding:.4rem .6rem;">Members</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($capacity['projection']['accounts'] as $row)
                                    <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                        <td style="padding:.4rem .6rem; font-family:monospace;">
                                            {{ $row['label'] }}
                                            @if ($row['is_new'])
                                                <x-filament::badge color="info">new</x-filament::badge>
                                            @endif
                                        </td>
                                        <td style="padding:.4rem .6rem;">{{ number_format($row['capacity_tokens']) }}</td>
                                        <td style="padding:.4rem .6rem;">{{ number_format($row['fill_percent'], 0) }}%</td>
                                        <td style="padding:.4rem .6rem;">{{ $row['members'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if (! empty($capacity['projection']['arrivals']))
                        <p style="font-size:.85rem; margin-top:.75rem;">Who would move onto the new {{ \Illuminate\Support\Str::plural('account', $capacity['projection']['extra_accounts']) }}:</p>
                        <ul style="font-size:.85rem; margin-top:.25rem; padding-left:1.25rem;">
                            @foreach ($capacity['projection']['arrivals'] as $arrival)
                                <li>{{ $arrival['user_label'] }} → {{ $arrival['account_label'] }} ({{ number_format($arrival['weekly_tokens']) }} tokens/week)</li>
                            @endforeach
                        </ul>
                    @endif

                    <p style="font-size:.75rem; opacity:.55; margin-top:.75rem;">
                        A projection, not a plan you can execute: connect the account first, then Recalculate to get
                        real moves with Switch buttons.
                    </p>
                </div>
            @endif
        </x-filament::section>
    @endif

    @if ($computed && ! empty($accounts))
        <x-filament::section heading="Fleet capacity" style="margin-top:1.5rem;">
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">Account</th>
                            <th style="padding:.4rem .6rem;">Measured capacity / week</th>
                            <th style="padding:.4rem .6rem;">Worst-case load</th>
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
