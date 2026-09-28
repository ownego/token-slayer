@php
    use App\Support\CompactNumber;
    use App\Support\SheetFormat;

    $faceColors = ['#fb923c', '#f472b6', '#60a5fa', '#facc15', '#2dd4bf', '#34d399', '#a78bfa', '#f87171'];
    $faceColor = fn (int $userId): string => $faceColors[$userId % count($faceColors)];
    $refillTitle = fn ($at): string => 'At '.($at->isToday() ? $at->format('H:i') : $at->format('D H:i'));
@endphp
<div class="party" x-data="{ openAcct: null }">
    <h3>@include('livewire.fighter-sheet.icon', ['name' => 'accounts'])Your accounts</h3>

    <p class="newbie-only" style="margin:0;color:var(--dim)">You aren't on a tracked account yet. An admin adds you to one from the dashboard; it shows up here with its quota.</p>
    @if (count($accounts) === 0)
        <p style="margin:0;color:var(--dim)">You aren't on a tracked account yet. An admin adds you to one from the dashboard; it shows up here with its quota.</p>
    @endif

    @foreach ($accounts as $card)
        @php
            $account = $card;
            $id = $account['account_id'];
            $me = collect($account['members'])->firstWhere('user_id', auth()->id());
            $badge = $card['worst'] ? SheetFormat::statusBadge($card['worst']) : null;
        @endphp
        <div
            class="acct has-data {{ $account['probe_error'] ? 'error' : '' }}"
            id="acct-{{ $id }}"
            @if ($card['worst']) data-worst="{{ $card['worst'] }}" @endif
            @if ($card['summary']) title="{{ $card['summary'] }}" @endif
            x-data="accountCard({{ $id }})"
            :class="{ open: openAcct === {{ $id }}, error: failed || @js((bool) $account['probe_error']) }"
        >
            <div class="acct-head">
                <span class="email">{{ $account['email'] }}</span>
                @if ($badge)
                    <span class="worst" aria-label="{{ $card['summary'] }}">{{ $badge['icon'] }}</span>
                @endif
                @if ($account['plan'])
                    <span class="plan">{{ $account['plan']->getLabel() }}</span>
                @endif
                <span class="use"><b>{{ CompactNumber::format($me['damage_today'] ?? 0) }}</b> today</span>
                @if ($card['roomiest'])
                    <span class="room">Most room</span>
                @endif
            </div>

            @if ($card['summary'])
                <div class="acct-summary" data-status="{{ $card['worst'] }}"><i aria-hidden="true">{{ $badge['icon'] }}</i> {{ $card['summary'] }}</div>
            @endif

            @if (empty($card['windows']))
                <p class="not-probed" style="margin:0;color:var(--dim)">Not probed yet.</p>
            @else
                <div class="meters">
                    @foreach ($card['windows'] as $w)
                        @php
                            $status = SheetFormat::quotaStatus($w['util']);
                            $state = SheetFormat::statusBadge($status);
                            $used = min($w['util'], 100);
                            $projected = $w['projected'] !== null ? min($w['projected'], 100) : null;
                        @endphp
                        <div class="meter" data-status="{{ $status }}">
                            <div class="m-top"><span class="term m-name" tabindex="0">{{ $w['name'] }}<span class="tip">{{ $w['tip'] }}</span></span><span class="m-pct"><b>{{ $w['util'] }}</b>%</span></div>
                            <div class="m-track" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $w['util'] }}" aria-label="{{ $w['name'] }} quota used" title="Hatched: where it lands by refill at this pace"><span class="m-used" style="width:{{ $used }}%"></span>@if ($projected !== null && $projected > $used)<span class="m-proj" style="left:{{ $used }}%;width:{{ $projected - $used }}%"></span>@endif @if (($w['projected'] ?? 0) > 100)<span class="m-over" title="Projected past 100% before it refills">▶</span>@endif</div>
                            <div class="m-status"><i aria-hidden="true">{{ $state['icon'] }}</i> {{ $state['label'] }}</div>
                            @if ($w['reset'] && $w['reset']->isPast())
                                <div class="m-refill">Stale — hasn't refreshed since it last reset</div>
                            @elseif ($w['reset'])
                                <div class="m-refill" title="{{ $refillTitle($w['reset']) }}">Refills in <b>{{ SheetFormat::duration($w['reset']) }}</b></div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if (! empty($account['model_limits']))
                    <div class="mq" aria-label="Per-model quota"><span class="mq-hd">Per model</span>@foreach ($account['model_limits'] as $limit)@php($status = SheetFormat::quotaStatus($limit['percent']))@php($state = SheetFormat::statusBadge($status))<div class="mq-row" data-status="{{ $status }}"><span class="mq-name">{{ $limit['model'] }}</span><span class="mq-track" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $limit['percent'] }}" aria-label="{{ $limit['model'] }} quota used"><span style="width:{{ min($limit['percent'], 100) }}%"></span></span><span class="mq-pct" title="{{ $state['label'] }}"><i aria-hidden="true">{{ $state['icon'] }}</i> <b>{{ $limit['percent'] }}</b>%<span class="sr">{{ $state['label'] }}</span></span>@if ($limit['resets_at'] && ! $limit['resets_at']->isPast())<span class="mq-refill" title="{{ $refillTitle($limit['resets_at']) }}">Refills in <b>{{ SheetFormat::duration($limit['resets_at']) }}</b></span>@endif</div>@endforeach</div>
                @endif
            @endif

            <div class="acct-foot">
                <button class="fold" type="button" :aria-expanded="(openAcct === {{ $id }}).toString()" @click="openAcct = openAcct === {{ $id }} ? null : {{ $id }}"><span class="tri">▶</span>
                    <span class="faces">@foreach (array_slice($account['members'], 0, 4) as $member)<span class="face" style="background:{{ $faceColor($member['user_id']) }}">{{ Str::upper(Str::substr($member['handle'], 0, 1)) }}</span>@endforeach</span> {{ count($account['members']) }} {{ Str::plural('member', count($account['members'])) }}
                </button>
                <span class="probe" :class="stampClass" title="Last checked {{ ($account['probed_at'] ?? null) ? SheetFormat::ago($account['probed_at']) : 'never' }}" x-text="stamp ?? $el.dataset.ago" data-ago="{{ ($account['probed_at'] ?? null) ? SheetFormat::ago($account['probed_at']) : 'never' }}">{{ ($account['probed_at'] ?? null) ? SheetFormat::ago($account['probed_at']) : 'never' }}</span>
                <button class="refresh" type="button" :title="left ? `You can check it again in ${left}s` : 'Check this account\'s usage now'" title="Check this account's usage now" aria-label="Refresh" :class="{ busy }" :disabled="busy || left > 0" @click="refresh()"><span class="spin">↻</span><span x-show="left > 0" x-text="` ${left}s`"></span></button>
            </div>
            @if ($account['probe_error'])
                <div class="probe-error">✕ Couldn't check: {{ $account['probe_error'] }}.@if ($account['probed_at'] ?? null) Showing usage from {{ SheetFormat::ago($account['probed_at']) }}; an admin needs to reconnect it.@endif</div>
            @endif
            <div class="members-wrap"><div class="members">
                <span class="hd">Member</span><span class="hd" style="text-align:right">Damage today</span><span class="hd" style="text-align:right">Events</span><span class="hd" style="text-align:right">Last seen</span>
                @foreach ($account['members'] as $member)
                    <span class="who"><span class="face" style="background:{{ $faceColor($member['user_id']) }}">{{ Str::upper(Str::substr($member['handle'], 0, 1)) }}</span>{{ $member['handle'] }}@if ($member['user_id'] === auth()->id()) <span class="you">you</span>@endif @if (($member['status'] ?? null) === 'pending')<span class="pend" title="Waiting to be verified"></span>@endif</span><span class="num dmgc">{{ CompactNumber::format($member['damage_today']) }}</span><span class="num">{{ number_format($member['events_today']) }}</span><span class="ago {{ $member['last_seen_at'] && $member['last_seen_at']->diffInMinutes(now()) < 60 ? 'fresh' : '' }}">{{ SheetFormat::ago($member['last_seen_at']) }}</span>
                @endforeach
            </div></div>
        </div>
    @endforeach
</div>
