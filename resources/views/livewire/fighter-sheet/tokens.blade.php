@php
    use App\Services\Profile\Period;
    use App\Support\CompactNumber;
    use App\Support\SheetFormat;
    use Carbon\CarbonImmutable;

    $t = $stats['tokens'];
    $total = $t['out'] + $t['in'] + $t['cw'] + $t['cr'];
    $share = fn (int $n): string => $total > 0 ? SheetFormat::percent(100 * $n / $total) : '0%';
    $fromCacheBase = $t['cr'] + $t['cw'] + $t['in'];
    $fromCache = $fromCacheBase > 0 ? round(100 * $t['cr'] / $fromCacheBase) : 0;
    $start = $period === 'custom' && $from ? CarbonImmutable::parse($from) : Period::tryFrom($period)?->start(CarbonImmutable::now());
    $partial = $period !== 'custom' || $from ? SheetFormat::ledgerPartial($start) : false;
@endphp
<div class="ledger has-data">
    <h3>@include('livewire.fighter-sheet.icon', ['name' => 'tokens'])Tokens <span class="meta" id="ledger-meta">{{ $period === 'custom' ? 'in range' : SheetFormat::periodName($period) }}</span></h3>
    <div class="ledger-bar">@foreach (['out', 'in', 'cw', 'cr'] as $k)<span class="c-{{ $k }}" id="b-{{ $k }}" style="flex-grow:{{ $total > 0 ? round($t[$k] / $total, 4) : 0 }}"></span>@endforeach</div>
    <div class="rows">
        <i class="c-out"></i><span title="Output tokens are your damage">Output <span class="dmgnote">counts as damage</span></span><span class="v" id="v-out">{{ CompactNumber::format($t['out']) }}</span><span class="p" id="p-out">{{ $share($t['out']) }}</span>
        <i class="c-in"></i><span>Input</span><span class="v" id="v-in">{{ CompactNumber::format($t['in']) }}</span><span class="p" id="p-in">{{ $share($t['in']) }}</span>
        <i class="c-cw"></i><span><span class="term" tabindex="0">Cache written<span class="tip">Tokens Claude stored in its prompt cache so later turns can re-use them.</span></span></span><span class="v" id="v-cw">{{ CompactNumber::format($t['cw']) }}</span><span class="p" id="p-cw">{{ $share($t['cw']) }}</span>
        <i class="c-cr"></i><span><span class="term" tabindex="0">Cache read<span class="tip">Tokens Claude re-used from its prompt cache instead of reading again. Cheap and fast, and they don't count as damage.</span></span></span><span class="v" id="v-cr">{{ CompactNumber::format($t['cr']) }}</span><span class="p" id="p-cr">{{ $share($t['cr']) }}</span>
        <div class="sum"><span>Total tokens · <span id="hit">{{ $fromCache }}%</span> from cache</span><b id="v-total">{{ CompactNumber::format($total) }}</b></div>
    </div>
    @if ($partial)
        <div class="foot-note" id="v6-note">Input and cache counted since {{ CarbonImmutable::parse(SheetFormat::LEDGER_TRACKED_SINCE)->format('j M') }} (hook v6)</div>
    @endif
</div>
