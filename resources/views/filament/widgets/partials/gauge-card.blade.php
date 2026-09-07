{{--
    One quota gauge card. Expects $g = a QuotaGaugesQuery row:
    ['provider', 'email', 'plan', 'util_5h', 'util_7d', 'projected_5h', 'projected_7d',
     'reset_5h_at', 'reset_7d_at', 'near_cap', 'model_limits', 'codex_windows'].
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
    // Codex reports its own windows, and they are not the 5h/7d pair: a
    // free-tier account has a single 30-day cap. Rendering those instead of
    // the two fixed rows keeps every figure under the duration it actually
    // measures.
    $windows = collect($g['codex_windows'] ?? [])
        ->mapWithKeys(fn (array $w): array => [
            $w['label'] => ['util' => $w['percent'], 'reset' => $w['resets_at'], 'proj' => null],
        ])
        ->all();
    $windows = $windows ?: [
        '5h' => ['util' => $g['util_5h'], 'reset' => $g['reset_5h_at'], 'proj' => $g['projected_5h']],
        '7d' => ['util' => $g['util_7d'], 'reset' => $g['reset_7d_at'], 'proj' => $g['projected_7d']],
    ];
    $plan = $g['plan'] ?? null;
    $badgeColors = ['gray' => '#6b7280', 'info' => '#2563eb', 'warning' => '#d97706', 'success' => '#059669', 'primary' => '#6366f1'];
    $planColor = $plan ? ($badgeColors[$plan->getColor()] ?? '#6b7280') : '#6b7280';
    $provider = $g['provider'];
    $providerColor = $badgeColors[$provider->getColor()] ?? '#6b7280';
@endphp
<div style="border-radius:.6rem; padding:.85rem 1rem; {{ $cardStyle }}">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
        <span style="display:inline-flex; align-items:center; gap:.4rem; min-width:0;">
            <span style="font-weight:600; font-size:.875rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $g['email'] }}</span>
            <span style="font-size:.62rem; font-weight:700; letter-spacing:.03em; padding:.1rem .4rem; border-radius:.35rem; color:{{ $providerColor }}; background:{{ $providerColor }}1a; white-space:nowrap;">{{ $provider->getLabel() }}</span>
            @if ($plan)
                <span style="font-size:.62rem; font-weight:700; letter-spacing:.03em; padding:.1rem .4rem; border-radius:.35rem; color:{{ $planColor }}; background:{{ $planColor }}1a; white-space:nowrap;">{{ $plan->getLabel() }}</span>
            @endif
        </span>
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

    {{-- The per-model limits the last probe carried, each naming its own
         model. Laid out as the same label/percent/bar row as the windows
         above rather than as chips: a bare "Fable 28%" reads as one run-on
         string, and the eye has nothing to compare one model against
         another with. Never a fixed list -- a model released after this
         shipped shows up under the name the API gives it. --}}
    @if (! empty($g['model_limits'] ?? []))
        <div style="margin-top:.7rem; border-top:1px solid rgba(120,120,140,.16); padding-top:.6rem;">
            <div style="font-size:.62rem; text-transform:uppercase; letter-spacing:.05em; opacity:.55; margin-bottom:.45rem;">Per model</div>
            <div style="display:flex; flex-direction:column; gap:.5rem;">
                @foreach ($g['model_limits'] as $limit)
                    <div title="{{ $limit['resets_at'] ? 'resets '.$limit['resets_at']->diffForHumans(['short' => true]) : 'no reset reported' }}">
                        <div style="display:flex; justify-content:space-between; align-items:baseline; font-size:.72rem; opacity:.75; margin-bottom:.2rem; gap:.5rem;">
                            <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $limit['model'] }}</span>
                            <span style="font-variant-numeric:tabular-nums; font-weight:600; white-space:nowrap;">{{ $limit['percent'] }}%</span>
                        </div>
                        <div style="height:.3rem; border-radius:999px; background:rgba(120,120,140,.18); overflow:hidden;">
                            <div style="height:100%; border-radius:999px; width:{{ max(0, min(100, $limit['percent'])) }}%; background:{{ $barColor($limit['percent']) }}; transition:width .2s;"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

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
