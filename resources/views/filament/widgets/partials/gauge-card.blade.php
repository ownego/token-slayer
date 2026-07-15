{{--
    One quota gauge card. Expects $g = a QuotaGaugesQuery row:
    ['email', 'util_5h', 'util_7d', 'projected_5h', 'projected_7d',
     'reset_5h_at', 'reset_7d_at', 'near_cap'].
    Layout/colour are inline so the card renders identically inside the
    Filament panel regardless of which utility classes the panel ships.
--}}
@php
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
</div>
