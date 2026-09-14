@php
    $rows = [
        ['Weekly demand', number_format($move['demandWeeklyTokens']) . ' tokens', $move['demandBasis'] === 'peak_week'
            ? 'Their heaviest week on record, weighted by how fast their tokens burn quota. The heaviest rather than the usual: quiet weeks after a heavy one usually mean they hit a ceiling, not that they wanted less.'
            : 'Their average week, weighted by how fast their tokens burn quota — which here is the larger of the two measures.'],
        ['Heaviest week', number_format($move['peakWeekTokens']) . ' tokens', 'The most they got through in any seven consecutive days in the range, idle days counted as zero.'],
        ['Average day', number_format($move['trailingAvgPerDayTokens']) . ' tokens/day', 'Everything they spent over the range, spread across every day in it.'],
        ['Planned as', number_format($move['demandPerDayTokens']) . ' tokens/day', 'The weekly figure above, spread evenly — the rate an account has to sustain for them.'],
        ['Burstiness', number_format($move['burstFactor'], 2) . '×', 'Busiest hour over the mean hour. Above 1, extra room is reserved around them, because a concentrated day is what trips a 5-hour window.'],
        ['Quota weight', number_format($move['quotaWeight'], 2) . '×', $move['quotaWeightWindows'] === 0
            ? 'Never measured: nobody used an account alone often enough to compare against. They are being planned as a typical member, which is the right default when there is no evidence either way.'
            : 'How fast their tokens burn quota compared with a typical member of the same account, from ' . $move['quotaWeightWindows'] . ' window(s) where they used it alone. 1.00 is typical; below 1.00 their tokens cost the quota less per token.'],
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
            {{ $move['confidenceReason'] ?? '' }}
            Treat the numbers as a first guess rather than a measurement — and note this says nothing about the other
            two, which may be long established.
        </p>
    @endunless
</div>
