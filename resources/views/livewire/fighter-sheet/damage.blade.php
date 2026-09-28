@php
    use App\Support\CompactNumber;
    use App\Support\SheetFormat;

    $inMore = in_array($period, ['year', 'custom'], true);
    $rankLabel = SheetFormat::rankLabel($stats['rank'], $period);
    $delta = SheetFormat::deltaLabel($stats['delta'], $period);
    $share = round(min($damage['share'], 1) * 100);
    $moreLabel = match ($period) {
        'year' => 'This year',
        'custom' => $from && $to ? \Carbon\CarbonImmutable::parse($from)->format('j M').'–'.\Carbon\CarbonImmutable::parse($to)->format('j M') : 'Custom range',
        default => 'More',
    };
@endphp
<div
    class="has-data"
    x-data="periodTabs(@js($period))"
    @click.outside="more = false"
    @keydown.escape="if (more) { $event.stopPropagation(); escape() }"
>
    <div class="periods" role="tablist">
        @foreach (['hour' => 'This hour', 'today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'all' => 'All time'] as $value => $label)
            <button class="period" role="tab" type="button" data-p="{{ $value }}" aria-selected="{{ $period === $value ? 'true' : 'false' }}" tabindex="{{ $period === $value ? '0' : '-1' }}" @click="pick('{{ $value }}')" @keydown="nav($event)">{{ $label }}</button>
        @endforeach
        <div class="period" role="tab" data-p="{{ $inMore ? $period : 'year' }}" aria-selected="{{ $inMore ? 'true' : 'false' }}" id="dd" tabindex="0" :class="{ open: more }" @click="more = !more; custom = false" @keydown="nav($event)">
            <span id="dd-label">{{ $moreLabel }}</span><span class="chev">▼</span>
            <div class="menu" id="menu" :class="{ custom }" @click.stop>
                <button type="button" data-p="year" class="{{ $period === 'year' ? 'on' : '' }}" @click="pick('year')">This year <small>since Jan 1</small></button>
                <button type="button" data-p="custom" class="{{ $period === 'custom' ? 'on' : '' }}" @click="custom = true">Custom range <small>pick dates</small></button>
                <form class="range" wire:submit="applyRange" @submit="more = false; try { localStorage.setItem('ts:profile-period', 'custom'); } catch (e) {}"><input type="date" wire:model="from"> to <input type="date" wire:model="to"><button class="apply" type="submit">Apply</button></form>
            </div>
        </div>
    </div>
    @error('to')
        <p class="range-error" aria-live="polite" style="margin:6px 0 0;color:var(--blood);font-size:12px">{{ $message }}</p>
    @enderror
    <div class="meter-panel" wire:loading.class="is-loading" wire:target="setPeriod,applyRange">
        <div class="meter-top">
            <span class="dmg-wrap"><span class="dmg" id="dmg" data-value="{{ $damage['mine'] }}">{{ CompactNumber::format($damage['mine']) }}</span></span>
            <span class="dmg-label"><span class="live-dot"></span>damage dealt</span>
            <span class="rank" id="rank" data-rank="{{ $stats['rank'] !== null && $stats['rank'] <= 3 ? $stats['rank'] : '' }}">{{ $rankLabel }}</span>
        </div>
        <div class="hpbar"><span class="fill" id="share-fill" style="width:{{ $share }}%"></span></div>
        <div class="hpbar-cap"><span><b id="share">{{ $share }}%</b> of the team's damage · team <span id="team">{{ CompactNumber::format($damage['team']) }}</span></span><span></span></div>
        <div class="delta {{ str_contains($delta, '▼') ? 'down' : '' }}" id="delta">{{ $delta }}</div>
    </div>
</div>
