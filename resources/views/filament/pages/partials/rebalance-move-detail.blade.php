@php
    $rows = [
        ['Weekly demand', number_format($move['demandWeeklyTokens']) . ' tokens', 'What this move is sized against: the daily rate below, times seven, times their quota weight.'],
        ['Daily rate used', number_format($move['demandPerDayTokens']) . ' tokens/day', $move['demandBasis'] === 'peak_rate'
            ? 'Their best sustained run, not their average — quiet days after a heavy one usually mean they hit a ceiling, not that they wanted less.'
            : 'Their plain average, which here is the higher of the two measures.'],
        ['Trailing average', number_format($move['trailingAvgPerDayTokens']) . ' tokens/day', 'Everything they spent over the range, spread across every day in it.'],
        ['Peak sustained run', number_format($move['peakAvgPerDayTokens']) . ' tokens/day', 'The heaviest consecutive-day stretch on record in the range.'],
        ['Burstiness', number_format($move['burstFactor'], 2) . '×', 'Busiest hour over the mean hour. Above 1, extra room is reserved around them, because a concentrated day is what trips a 5-hour window.'],
        ['Quota weight', number_format($move['quotaWeight'], 2) . '×', 'How fast their tokens burn quota compared with a typical member, measured only from windows where they used an account alone. 1.00 is typical.'],
        ['History', $move['daysOfHistory'] . ' days', 'How much recorded usage in the range sits behind these figures.'],
    ];
@endphp

<div style="font-size:.85rem; display:flex; flex-direction:column; gap:1rem;">
    <p>
        Move <strong>{{ $move['userLabel'] }}</strong> from
        <span style="font-family:monospace;">{{ $move['fromAccountLabel'] }}</span> to
        <span style="font-family:monospace;">{{ $move['toAccountLabel'] }}</span>.
        @if ($move['swapWithLabel'] !== null)
            <strong>{{ $move['swapWithLabel'] }}</strong> crosses the other way to fill the space — apply both, or neither.
        @endif
    </p>

    <div>
        <div style="opacity:.6; margin-bottom:.25rem;">Effect on both accounts</div>
        <table style="width:100%; border-collapse:collapse;">
            <tbody>
                <tr style="border-top:1px solid rgba(120,120,140,.15);">
                    <td style="padding:.35rem .5rem; font-family:monospace;">{{ $move['fromAccountLabel'] }}</td>
                    <td style="padding:.35rem .5rem;">
                        {{ number_format($move['fromFillBeforePercent'], 1) }}% → {{ number_format($move['fromFillAfterPercent'], 1) }}% of its weekly capacity
                    </td>
                </tr>
                <tr style="border-top:1px solid rgba(120,120,140,.15);">
                    <td style="padding:.35rem .5rem; font-family:monospace;">{{ $move['toAccountLabel'] }}</td>
                    <td style="padding:.35rem .5rem;">
                        {{ number_format($move['toFillBeforePercent'], 1) }}% → {{ number_format($move['toFillAfterPercent'], 1) }}% of its weekly capacity
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div>
        <div style="opacity:.6; margin-bottom:.25rem;">How this person was sized ({{ $summary['window_label'] ?? '' }})</div>
        <table style="width:100%; border-collapse:collapse;">
            <tbody>
                @foreach ($rows as [$label, $value, $why])
                    <tr style="border-top:1px solid rgba(120,120,140,.15);">
                        <td style="padding:.35rem .5rem; white-space:nowrap; opacity:.8;">{{ $label }}</td>
                        <td style="padding:.35rem .5rem; white-space:nowrap; font-weight:600;">{{ $value }}</td>
                        <td style="padding:.35rem .5rem; opacity:.6;">{{ $why }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @unless ($move['confident'])
        <p style="color:var(--warning-600, #ca8a04);">
            Less than {{ config('token_slayer.rebalance.min_history_days') }} days of recorded history behind this person
            or one of the two accounts — treat the numbers as a first guess rather than a measurement.
        </p>
    @endunless
</div>
