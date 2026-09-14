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

        <div style="display:flex; align-items:center; gap:.75rem; font-size:.85rem; flex-wrap:wrap; margin-top:.5rem;">
            <span style="opacity:.6;">Plan as if we had bought</span>
            <select wire:model.live="extraAccounts" style="padding:.3rem .5rem; border-radius:.375rem; border:1px solid rgba(120,120,140,.3); background:transparent;">
                @foreach (range(0, 6) as $count)
                    <option value="{{ $count }}">{{ $count === 0 ? 'no new accounts' : $count.' more '.\Illuminate\Support\Str::plural('account', $count) }}</option>
                @endforeach
            </select>
        </div>

        @if ($computed)
            <ul style="margin-top:.75rem; font-size:.8rem; opacity:.7; list-style:none; padding-left:0; display:flex; flex-direction:column; gap:.35rem;">
                <li>
                    <strong>Every percentage is worst case</strong> — what an account would carry if all of its members
                    hit their heaviest week at once.
                    @if (! empty($capacity))
                        Normally well above 100%; the heaviest week this fleet really had was
                        {{ number_format($capacity['observed']['fleet_peak_percent'], 0) }}%.
                    @endif
                </li>
                <li>
                    <strong>Planned to {{ 100 - ($summary['safety_margin_percent'] ?? 0) }}% of capacity</strong> —
                    the rest is left as slack.
                </li>
                <li>
                    <strong>No more than {{ config('token_slayer.rebalance.members_per_account') }} members per
                    account</strong> — a crowded account can have everyone working the same hour, which is what trips a
                    5-hour window whatever the weekly total says.
                </li>
            </ul>
        @endif

        @if (! $computed)
            <p style="opacity:.6; margin-top:.75rem;">Click "Recalculate" to see recommendations.</p>
        @elseif (empty($moves))
            <p style="opacity:.6; margin-top:.75rem;">
                No move would meaningfully relieve the fullest account — the fleet is already as balanced as
                reassignment can make it (fullest account: {{ number_format($summary['peak_fill_before_percent'], 1) }}% worst case).
            </p>
        @else
            @php($before = $summary['peak_fill_before_percent'])
            @php($after = $summary['peak_fill_after_percent'])
            <p style="margin-top:.75rem; font-size:.85rem;">
                Apply every move below and the fullest account goes from
                <strong>{{ number_format($before, 1) }}%</strong> to
                <strong>{{ number_format($after, 1) }}%</strong>
                @if ($before > 100)
                    — from running out after <strong>{{ number_format(700 / $before, 1) }} days</strong>
                    @if ($after > 100)
                        to running out after <strong>{{ number_format(700 / $after, 1) }} days</strong>.
                    @else
                        to lasting the whole week.
                    @endif
                @endif
            </p>
            <p style="margin-top:.35rem; font-size:.8rem; opacity:.6;">
                Accounts converge rather than all drop: the load has to go somewhere, and levelling it is what stops
                one account burning out days before the others.
            </p>

            @if ($planId === null)
                <p style="margin-top:.5rem; font-size:.85rem; border-left:3px solid var(--warning-500, #f59e0b); padding-left:.75rem;">
                    <strong>This is a draft.</strong> Nothing is recorded and no move can be carried out until you
                    press <strong>Apply this plan</strong>. Recalculating as often as you like will not disturb a plan
                    already being worked through.
                </p>
            @endif

            @if ($planId !== null)
                <p style="margin-top:.5rem; font-size:.85rem;">
                    <strong>{{ count($applied) }} of {{ count($moves) }} done.</strong>
                    @if ($adoptedAt)
                        <span style="opacity:.6;">Applied {{ \Illuminate\Support\Carbon::parse($adoptedAt)->diffForHumans() }}.</span>
                    @endif
                    @if (count($applied) >= count($moves))
                        This plan is finished — Recalculate to see whether anything further is worth doing.
                    @elseif (count($applied) === 0)
                        Work through them one at a time; the list stays exactly as it is until you Recalculate and
                        apply a new one.
                    @else
                        The remaining {{ count($moves) - count($applied) }} still lead to the same arrangement, so the
                        list stays put until you Recalculate. Finishing them is what reaches
                        {{ number_format($after, 1) }}%; stopping half way through a swap leaves the fleet worse than
                        before it started.
                    @endif
                </p>
            @endif

            @if (($summary['simulated_accounts'] ?? 0) > 0 && ! empty($capacity))
                @php($extra = $summary['simulated_accounts'])
                @php($need = $capacity['unconstrained']['tokens'])
                @php($grown = $summary['capacity_tokens'])
                @php($short = max(0, $need - $grown))
                @php($without = $summary['peak_without_extra_percent'])

                <div style="margin-top:.75rem; font-size:.85rem; border-left:3px solid rgba(120,120,140,.35); padding-left:.85rem;">
                    <div style="font-weight:600; margin-bottom:.4rem;">
                        What buying {{ $extra }} more {{ \Illuminate\Support\Str::plural('account', $extra) }} would change
                    </div>
                    <ul style="list-style:none; padding-left:0; display:flex; flex-direction:column; gap:.3rem;">
                        <li>
                            <strong>Fullest account {{ number_format($after, 0) }}%</strong>, against
                            {{ number_format($without, 0) }}% from rebalancing alone
                            @if ($without > 100 && $after > 100)
                                — {{ number_format(700 / $after, 1) }} days before it runs out instead of
                                {{ number_format(700 / $without, 1) }}.
                            @elseif ($after <= 100)
                                — it would last the whole week.
                            @endif
                        </li>
                        <li>
                            <strong>{{ $summary['arrivals'] }}
                            {{ \Illuminate\Support\Str::plural('person', $summary['arrivals']) }}</strong> would move
                            onto {{ $extra === 1 ? 'it' : 'them' }}, marked below.
                        </li>
                        <li>
                            <strong>Capacity {{ number_format($capacity['capacity_tokens']) }} →
                            {{ number_format($grown) }}</strong> tokens/week, against the
                            {{ number_format($need) }} the fleet actually needed unthrottled —
                            @if ($short > 0)
                                <strong style="color:var(--danger-500, #dc2626);">still {{ number_format($short) }} short</strong>,
                                so some people would keep hitting a ceiling.
                            @else
                                <strong>enough that nobody would be throttled.</strong>
                            @endif
                        </li>
                        <li style="opacity:.7;">
                            {{ $capacity['unconstrained']['accounts_to_fit'] }} in total covers the need;
                            {{ $capacity['unconstrained']['accounts_needed'] }} covers it with the
                            {{ $capacity['safety_margin_percent'] }}% slack intact.
                        </li>
                    </ul>
                </div>
            @endif

            <div style="overflow-x:auto; margin-top:.75rem;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">User</th>
                            <th style="padding:.4rem .6rem;">From</th>
                            <th style="padding:.4rem .6rem;">To</th>
                            <th style="padding:.4rem .6rem;">Heaviest week</th>
                            <th style="padding:.4rem .6rem;">Swap with</th>
                            <th style="padding:.4rem .6rem;">Confidence</th>
                            <th style="padding:.4rem .6rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($moves as $index => $move)
                            <tr style="border-top:1px solid rgba(120,120,140,.15); {{ in_array($index, $applied, true) ? 'opacity:.45;' : '' }}">
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
                                    {{ number_format($move['peakWeekTokens']) }}
                                    @if (abs($move['quotaWeight'] - 1.0) > 0.005)
                                        <span style="opacity:.6;">
                                            × {{ number_format($move['quotaWeight'], 2) }} quota weight
                                            = {{ number_format($move['demandWeeklyTokens']) }}
                                        </span>
                                    @endif
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ $move['swapWithLabel'] ?? '—' }}
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    @if ($move['confident'])
                                        <x-filament::badge color="success">Confident</x-filament::badge>
                                    @else
                                        <span title="{{ $move['confidenceReason'] ?? '' }}">
                                            <x-filament::badge color="warning">Not enough data</x-filament::badge>
                                        </span>
                                        <div style="font-size:.7rem; opacity:.6; max-width:18rem;">{{ $move['confidenceReason'] ?? '' }}</div>
                                    @endif
                                </td>
                                <td style="padding:.4rem .6rem; white-space:nowrap;">
                                    {{ ($this->explainMoveAction)(['index' => $index]) }}
                                    @if (in_array($index, $applied, true))
                                        <x-filament::badge color="success">done</x-filament::badge>
                                    @elseif ($planId === null)
                                        <span style="opacity:.6; font-size:.8rem;">apply the plan first</span>
                                    @elseif ($move['toAccountIsNew'])
                                        <span style="opacity:.6; font-size:.8rem;">connect the account first</span>
                                    @else
                                        {{ ($this->switchUserAction)(['userId' => $move['userId'], 'fromAccountId' => $move['fromAccountId'], 'toAccountId' => $move['toAccountId'], 'index' => $index]) }}
                                    @endif
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
            @php($seen = $capacity['observed'])
            @php($free = $capacity['unconstrained'])

            <p style="font-size:.85rem;">
                @if ($free['accounts_saturated'] > 0)
                    <strong>{{ $free['accounts_saturated'] }} of {{ $seen['accounts_measured'] }} accounts ran out
                    mid-week and rationed their own users.</strong>
                    <br>
                    The fleet got through {{ number_format($seen['fleet_peak_percent'], 0) }}% of its capacity — but
                    that is what people were <em>allowed</em> to spend, not what they wanted. Before those accounts hit
                    the ceiling they were burning at a rate heading for
                    <strong>{{ number_format($free['percent'], 0) }}%</strong>.
                    <br>
                    @if ($free['accounts_needed'] > 0)
                        Covering that needs about <strong>{{ $free['accounts_needed'] }} more
                        {{ \Illuminate\Support\Str::plural('account', $free['accounts_needed']) }}</strong> —
                        rebalancing alone will not create tokens that were never there.
                    @else
                        The fleet can still cover that once the load is spread properly, so rebalancing is the fix.
                    @endif
                @elseif ($seen['accounts_over_capacity'] > 0 && $seen['fleet_peak_percent'] <= 100)
                    <strong>The fleet is not short of tokens — they are in the wrong accounts.</strong>
                    At its busiest the whole fleet reached
                    <strong>{{ number_format($seen['fleet_peak_percent'], 0) }}%</strong> of its capacity, yet
                    <strong>{{ $seen['accounts_over_capacity'] }} of {{ $seen['accounts_measured'] }}</strong>
                    accounts individually blew past 100% — that is what makes an account die mid-week while another
                    idles. Rebalancing is the fix for that; buying accounts is not.
                @else
                    The fleet peaked at <strong>{{ number_format($seen['fleet_peak_percent'], 0) }}%</strong> of its
                    capacity, no account ran out, and no account exceeded its own. Nothing here needs buying.
                @endif
            </p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:1rem; margin-top:1rem;">
                <div style="border:1px solid rgba(120,120,140,.2); border-radius:.5rem; padding:.75rem 1rem;">
                    <div style="opacity:.6; font-size:.75rem;">Busiest week that actually happened</div>
                    <div style="font-size:1.75rem; font-weight:600; line-height:1.2; color:{{ $seen['fleet_peak_percent'] > 100 ? 'var(--danger-500, #dc2626)' : 'inherit' }};">
                        {{ number_format($seen['fleet_peak_percent'], 0) }}%
                    </div>
                    <div style="font-size:.8rem; opacity:.7;">
                        {{ number_format($seen['fleet_peak_tokens']) }} tokens/week of {{ number_format($capacity['capacity_tokens']) }} capacity
                        · median week {{ number_format($seen['fleet_median_percent'], 0) }}%
                    </div>
                    <div style="font-size:.85rem; margin-top:.5rem;">
                        @if ($seen['accounts_needed'] === 0)
                            <x-filament::badge color="success">Enough capacity — no new account needed</x-filament::badge>
                        @else
                            <x-filament::badge color="warning">At least {{ $seen['accounts_needed'] }} more {{ \Illuminate\Support\Str::plural('account', $seen['accounts_needed']) }} to stay under {{ 100 - $capacity['safety_margin_percent'] }}%</x-filament::badge>
                        @endif
                    </div>
                    <div style="font-size:.75rem; opacity:.55; margin-top:.4rem;">
                        Read off the event ledger over {{ $capacity['window_label'] }}. No model — this is the week
                        the team lived through.
                    </div>
                </div>

                <div style="border:2px solid rgba(120,120,140,.35); border-radius:.5rem; padding:.75rem 1rem;">
                    <div style="opacity:.6; font-size:.75rem;">What it would have needed unthrottled</div>
                    <div style="font-size:1.75rem; font-weight:600; line-height:1.2; color:{{ $free['percent'] > 100 ? 'var(--danger-500, #dc2626)' : 'inherit' }};">
                        {{ number_format($free['percent'], 0) }}%
                    </div>
                    <div style="font-size:.8rem; opacity:.7;">
                        {{ number_format($free['tokens']) }} tokens/week
                        · {{ $free['accounts_saturated'] }} {{ \Illuminate\Support\Str::plural('account', $free['accounts_saturated']) }} hit the ceiling
                    </div>
                    <div style="font-size:.85rem; margin-top:.5rem;">
                        @if ($free['accounts_needed'] === 0)
                            <x-filament::badge color="success">Enough capacity — no new account needed</x-filament::badge>
                        @else
                            <x-filament::badge color="warning">At least {{ $free['accounts_needed'] }} more {{ \Illuminate\Support\Str::plural('account', $free['accounts_needed']) }} to stay under {{ 100 - $capacity['safety_margin_percent'] }}%</x-filament::badge>
                        @endif
                    </div>
                    <div style="font-size:.75rem; opacity:.55; margin-top:.4rem;">
                        An account that burned 80% of its week in three days did not meet demand, it capped it. This
                        projects the rate it was running at before the ceiling across the full week. <strong>Size the
                        fleet on this one</strong> — the other two are a floor and a ceiling.
                    </div>
                </div>

                <div style="border:1px solid rgba(120,120,140,.2); border-radius:.5rem; padding:.75rem 1rem;">
                    <div style="opacity:.6; font-size:.75rem;">If every person peaked in the same week</div>
                    <div style="font-size:1.75rem; font-weight:600; line-height:1.2; opacity:.75;">
                        {{ number_format($capacity['worst_case']['percent'], 0) }}%
                    </div>
                    <div style="font-size:.8rem; opacity:.7;">
                        {{ number_format($capacity['worst_case']['tokens']) }} tokens/week
                    </div>
                    <div style="font-size:.85rem; margin-top:.5rem;">
                        <x-filament::badge color="gray">Upper bound, not a forecast</x-filament::badge>
                    </div>
                    <div style="font-size:.75rem; opacity:.55; margin-top:.4rem;">
                        Everyone's own heaviest week added together. Those peaks have never all landed at once — the
                        busiest real week was {{ number_format($seen['fleet_peak_percent'], 0) }}% — so this over-reads
                        the fleet. It is what the arrangement above is planned against, deliberately.
                    </div>
                </div>
            </div>


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
                            <th style="padding:.4rem .6rem;">Actually peaked at</th>
                            <th style="padding:.4rem .6rem;">Worst-case load</th>
                            <th style="padding:.4rem .6rem;">Members</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            @php($peak = $capacity['observed']['per_account'][$account['id']]['peak_percent'] ?? null)
                            <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                <td style="padding:.4rem .6rem; font-family:monospace;">
                                    {{ $account['email'] }}
                                    @if ($account['is_new'] ?? false)
                                        <x-filament::badge color="info">not bought yet</x-filament::badge>
                                    @endif
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ number_format($account['capacity_tokens']) }} tokens
                                    @if (($account['capacity_basis'] ?? '') === 'measured')
                                        <span style="opacity:.55;" title="Read off a week this account ran its quota out — what its users managed to spend is exactly a week's worth.">measured</span>
                                    @elseif (($account['capacity_basis'] ?? '') === 'extrapolated')
                                        <span style="opacity:.55; color:var(--warning-600, #ca8a04);" title="It has never run out in this range, so this scales a quieter week up to a full one — and scales the probe's error up with it.">estimated</span>
                                    @elseif (($account['capacity_basis'] ?? '') === 'contested')
                                        <span style="opacity:.55; color:var(--warning-600, #ca8a04);" title="The week it ran out of says something very different from the weeks it did not — the quota buys far fewer recorded tokens in a context-heavy week. This is the middle of every week on record rather than the exhausted one alone.">disputed</span>
                                    @elseif (($account['capacity_basis'] ?? '') === 'inherited')
                                        <span style="opacity:.55; color:var(--warning-600, #ca8a04);" title="Nothing usable of its own; borrowed from the accounts on its plan.">borrowed</span>
                                    @endif
                                </td>
                                <td style="padding:.4rem .6rem; {{ $peak !== null && $peak > 100 ? 'color:var(--danger-500, #dc2626); font-weight:600;' : '' }}">
                                    {{ $peak === null ? '—' : number_format($peak, 0) . '%' }}
                                </td>
                                <td style="padding:.4rem .6rem; opacity:.75;">
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
            <ul style="opacity:.6; font-size:.75rem; margin-top:.75rem; list-style:none; padding-left:0; display:flex; flex-direction:column; gap:.35rem;">
                <li>
                    <strong>Measured capacity</strong> — from closed quota windows over
                    {{ $summary['window_label'] ?? '' }}. A week the account ran out of measures it directly: its users
                    were cut off, so what they spent is exactly a week's worth. A week it never filled has to be scaled
                    up instead, which scales the probe's error up too, so those are marked <em>estimated</em> and
                    trusted only when no saturated week exists.
                </li>
                <li>
                    <strong>Actually peaked at</strong> — the heaviest seven days this account really carried.
                </li>
                <li>
                    <strong>Why the worst case is higher</strong> — its members' own heaviest weeks fell in different
                    weeks, so adding them up describes a week that has not happened. A plan still has to assume it
                    could, which is why the moves are decided on the higher figure.
                </li>
            </ul>
        </x-filament::section>
    @endif

    @php($stale = $this->staleMemberships())
    @if (! empty($stale))
        <x-filament::section heading="Seats nobody is using" style="margin-top:1.5rem;">
            <p style="font-size:.85rem; opacity:.75;">
                These members work somewhere else now but still hold a tracked seat here — either they moved on, or
                they were given the seat and never started. It counts against the account's roster and holds a
                provisioning slot either way. Releasing one costs nobody a re-authentication, which makes it the
                cheapest capacity on this page.
                <br>
                A seat is only listed once it has had a week to be used, so a switch you have just made will never
                appear here.
            </p>

            <div style="overflow-x:auto; margin-top:.75rem;">
                <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                    <thead>
                        <tr style="text-align:left; opacity:.6;">
                            <th style="padding:.4rem .6rem;">Member</th>
                            <th style="padding:.4rem .6rem;">Still holds a seat on</th>
                            <th style="padding:.4rem .6rem;">Last used it</th>
                            <th style="padding:.4rem .6rem;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stale as $seat)
                            <tr style="border-top:1px solid rgba(120,120,140,.15);">
                                <td style="padding:.4rem .6rem;">{{ $seat['user_label'] }}</td>
                                <td style="padding:.4rem .6rem; font-family:monospace;">{{ $seat['account_label'] }}</td>
                                <td style="padding:.4rem .6rem; opacity:.7;">
                                    {{ $seat['last_used_at'] === null ? 'never' : \Illuminate\Support\Carbon::parse($seat['last_used_at'])->diffForHumans() }}
                                </td>
                                <td style="padding:.4rem .6rem;">
                                    {{ ($this->releaseSeatAction)(['userId' => $seat['user_id'], 'accountId' => $seat['account_id']]) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Members" style="margin-top:1.5rem;">
        <div style="display:flex; gap:1rem; align-items:center; font-size:.75rem; opacity:.65; margin-bottom:1rem;">
            <span style="display:flex; align-items:center; gap:.35rem;">
                <span style="display:inline-block; width:.45rem; height:.45rem; border-radius:9999px; background:#22c55e;"></span>
                Tracked
            </span>
            <span style="display:flex; align-items:center; gap:.35rem;">
                <span style="display:inline-block; width:.45rem; height:.45rem; border-radius:9999px; background:#3b82f6;"></span>
                Pending — granted, not claimed yet
            </span>
            @if ($computed)
                <span style="margin-left:auto;">Bar shows worst-case load; the line marks {{ 100 - ($summary['safety_margin_percent'] ?? 20) }}%.</span>
            @endif
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:.85rem;">
            @foreach ($this->memberRowsByAccount() as $accountId => $rows)
                @php($card = collect($accounts)->firstWhere('id', $accountId))
                @php($load = $card['fill_before_percent'] ?? null)
                @php($target = 100 - ($summary['safety_margin_percent'] ?? 20))
                <div style="border:1px solid rgba(120,120,140,.2); border-radius:.6rem; padding:.75rem .85rem;">
                    <div style="display:flex; align-items:baseline; justify-content:space-between; gap:.5rem;">
                        <span style="font-family:monospace; font-size:.8rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            {{ \App\Models\Account::find($accountId)?->email }}
                        </span>
                        @if ($load !== null)
                            <span style="font-size:1.1rem; font-weight:600; line-height:1; color:{{ $load > 100 ? 'var(--danger-500, #dc2626)' : 'inherit' }};">
                                {{ number_format($load, 0) }}%
                            </span>
                        @endif
                    </div>

                    @if ($load !== null)
                        <div style="position:relative; height:.4rem; border-radius:9999px; background:rgba(120,120,140,.18); margin-top:.5rem; overflow:hidden;">
                            <div style="height:100%; width:{{ min(100, $load) }}%; background:{{ $load > 100 ? '#dc2626' : ($load > $target ? '#f59e0b' : '#22c55e') }};"></div>
                        </div>
                        <div style="position:relative; height:0;">
                            <span style="position:absolute; left:{{ $target }}%; top:-.55rem; width:1px; height:.7rem; background:rgba(120,120,140,.75);"></span>
                        </div>
                    @endif

                    <div style="display:flex; flex-wrap:wrap; gap:.3rem; margin-top:.75rem;">
                        @forelse ($rows as $row)
                            <span style="display:inline-flex; align-items:center; gap:.3rem; font-size:.75rem; padding:.15rem .45rem; border-radius:9999px; background:rgba(120,120,140,.12);">
                                <span style="display:inline-block; width:.4rem; height:.4rem; border-radius:9999px; background:{{ $row['status'] === 'pending' ? '#3b82f6' : '#22c55e' }};"
                                      title="{{ ucfirst($row['status']) }}"></span>
                                {{ $row['handle'] }}
                            </span>
                        @empty
                            <span style="font-size:.75rem; opacity:.5;">no members</span>
                        @endforelse
                    </div>

                    <div style="font-size:.7rem; opacity:.5; margin-top:.5rem;">
                        {{ count($rows) }} of {{ config('token_slayer.rebalance.members_per_account') }} seats
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
