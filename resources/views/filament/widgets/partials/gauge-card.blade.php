{{--
    One quota gauge card. Expects $g = a QuotaGaugesQuery row:
    ['email', 'util_5h', 'util_7d', 'projected_5h', 'projected_7d',
     'reset_5h_at', 'reset_7d_at', 'near_cap'].
    Optionally $members = a list of the account's contributors, each
    ['handle', 'avatar_url', 'status', 'tokens']; omitted (empty) on the
    single-account gauge, populated on the Fleet Quota dashboard card.
    Layout/colour are inline so the card renders identically inside the
    Filament panel regardless of which utility classes the panel ships.
--}}
@php
    $members = $members ?? [];
    $accountTotal = $accountTotal ?? null;
    $nearCap = $g['near_cap'];
    $cardStyle = $nearCap
        ? 'border:1px solid rgba(220,38,38,.55); background:rgba(220,38,38,.06);'
        : 'border:1px solid rgba(120,120,140,.22);';
    $barColor = fn (?int $pct): string => ($pct ?? 0) >= 90 ? '#dc2626' : (($pct ?? 0) >= 70 ? '#d97706' : '#059669');
    $windows = [
        '5h' => ['util' => $g['util_5h'], 'reset' => $g['reset_5h_at'], 'proj' => $g['projected_5h']],
        '7d' => ['util' => $g['util_7d'], 'reset' => $g['reset_7d_at'], 'proj' => $g['projected_7d']],
    ];
@endphp
<div style="border-radius:.6rem; padding:.85rem 1rem; {{ $cardStyle }}">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
        <span style="font-weight:600; font-size:.875rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $g['email'] }}</span>
        @if ($nearCap)
            <span style="font-size:.65rem; font-weight:700; letter-spacing:.03em; color:#dc2626; white-space:nowrap;">NEAR CAP</span>
        @endif
    </div>

    @if ($accountTotal !== null)
        <div style="margin-top:.3rem; font-size:.72rem; opacity:.7;">
            usage <span style="font-weight:600; font-variant-numeric:tabular-nums; font-family:ui-monospace,monospace; opacity:1;">{{ number_format($accountTotal) }}</span>
        </div>
    @endif

    <div style="margin-top:.7rem; display:flex; flex-direction:column; gap:.7rem;">
        @foreach ($windows as $label => $w)
            @php($pct = $w['util'])
            <div>
                <div style="display:flex; justify-content:space-between; align-items:baseline; font-size:.72rem; opacity:.75; margin-bottom:.25rem;">
                    <span style="text-transform:uppercase; letter-spacing:.04em;">{{ $label }}</span>
                    <span style="font-variant-numeric:tabular-nums; font-weight:600;">{{ $pct === null ? '—' : $pct.'%' }}</span>
                </div>
                <div style="height:.45rem; border-radius:999px; background:rgba(120,120,140,.18); overflow:hidden;">
                    <div style="height:100%; border-radius:999px; width:{{ max(0, min(100, $pct ?? 0)) }}%; background:{{ $barColor($pct) }}; transition:width .2s;"></div>
                </div>
                <div style="margin-top:.25rem; font-size:.66rem; opacity:.55; font-variant-numeric:tabular-nums;">
                    @if ($w['reset'])
                        resets {{ $w['reset']->diffForHumans(['short' => true]) }}@if ($w['proj'] !== null) · proj {{ $w['proj'] }}%@endif
                    @else
                        not probed
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if (! empty($members))
        <div style="margin-top:.75rem; border-top:1px solid rgba(120,120,140,.16); padding-top:.55rem; display:flex; flex-direction:column; gap:.4rem;">
            <div style="font-size:.62rem; text-transform:uppercase; letter-spacing:.05em; opacity:.55;">Members</div>
            @foreach ($members as $m)
                <div style="display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
                    <span style="display:inline-flex; align-items:center; gap:.4rem; min-width:0;">
                        @if ($m['avatar_url'])
                            <img src="{{ $m['avatar_url'] }}" alt="" style="width:18px; height:18px; border-radius:50%; flex:none;">
                        @else
                            <span style="width:18px; height:18px; border-radius:50%; flex:none; background:rgba(120,120,140,.25);"></span>
                        @endif
                        <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:.74rem;">{{ $m['handle'] }}</span>
                        @if ($m['status'] !== 'tracked')
                            <span title="Unverified" style="flex:none; font-size:.55rem; color:#d97706;">●</span>
                        @endif
                    </span>
                    <span style="flex:none; font-size:.68rem; font-variant-numeric:tabular-nums; font-family:ui-monospace,monospace; opacity:.8;">{{ number_format($m['tokens']) }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
