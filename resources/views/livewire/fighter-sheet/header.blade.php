@php
    use App\Support\SheetFormat;

    $lastProbe = collect($accounts)->pluck('probed_at')->filter()->max();
@endphp
<header class="sheet-head">
    <h1>{{ auth()->user()->displayHandle() }}</h1><span
        class="kills-chip"
        @if ($kills['latest'])
            title="Last hit on {{ $kills['count'] }} {{ Str::plural('boss', $kills['count']) }}. Latest: {{ $kills['latest']['name'] }}, {{ $kills['latest']['at']->diffForHumans() }}"
        @endif
    >@include('livewire.fighter-sheet.icon', ['name' => 'skull'])<b>{{ $kills['count'] }}</b> boss kills</span>

    <nav class="sheet-tabs" role="tablist" aria-label="Sheet">
        <button class="sheet-tab" role="tab" type="button" data-tab="profile" aria-selected="{{ $tab === 'profile' ? 'true' : 'false' }}">@include('livewire.fighter-sheet.icon', ['name' => 'profile'])Profile</button>
        <button class="sheet-tab" role="tab" type="button" data-tab="character" aria-selected="{{ $tab === 'character' ? 'true' : 'false' }}">@include('livewire.fighter-sheet.icon', ['name' => 'character'])Character</button>
    </nav>

    @if ($attribution['event'])
        <span class="live has-data" title="{{ $attribution['event']->account_email }} · {{ $attribution['event']->account_source }}">● last hit <span x-data="agoTicker('coarse')" data-since="{{ $attribution['event']->created_at->timestamp }}" x-text="label"></span></span>
    @else
        <span class="live newbie-only" style="color:var(--dim)">no hits yet</span>
    @endif

    <button
        class="grefresh"
        id="grefresh"
        type="button"
        @click="$wire.refresh().then(() => $dispatch('sheet-refreshed'))"
        wire:loading.class="busy"
        wire:target="refresh"
        :disabled="cooldown.disabled"
        :title="cooldown.text || 'Refresh quotas and stats'"
        title="Refresh quotas and stats"
    ><span class="spin">↻</span><span id="g-ago" x-data="agoTicker" data-since="{{ $lastProbe?->timestamp ?? 0 }}" x-text="label" @sheet-refreshed.window="tick()" wire:loading.remove wire:target="refresh">{{ $lastProbe ? SheetFormat::ago($lastProbe) : 'never' }}</span><span wire:loading wire:target="refresh">refreshing</span></button>@if ($attribution['outdated'])<a class="cli-pill" href="{{ route('update') }}" data-cmd="tok update" aria-label="Your CLI is {{ $attribution['clientVersion'] }}. Run tok update to get {{ $attribution['latestVersion'] }}.">CLI {{ $attribution['latestVersion'] }} ↑</a>@elseif ($attribution['clientVersion'])<a class="cli-pill current" href="{{ route('update') }}" data-cmd="tok update" aria-label="Your CLI is {{ $attribution['clientVersion'] }}, up to date. tok update checks for a newer one.">CLI {{ $attribution['clientVersion'] }}</a>@endif<nav class="head-links"><a href="{{ route('setup') }}">@include('livewire.fighter-sheet.icon', ['name' => 'setup'])Setup</a><a href="{{ route('guide') }}">@include('livewire.fighter-sheet.icon', ['name' => 'guide'])Guide</a></nav>

    <button class="close" type="button" aria-label="Close" @click="shell.close()">×</button>
</header>
