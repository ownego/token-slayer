@php
    use App\Enums\ModelFamily;
    use App\Support\CompactNumber;
    use App\Support\ModelName;

    $modelMax = max([1, ...array_column($byModel, 'damage')]);
    $modelTotal = max(1, array_sum(array_column($byModel, 'damage')));
    $modelPeriods = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'all' => 'All time'];
@endphp
<aside class="fighter">
    <div>
        <div wire:ignore x-data="miniStage(@js($equipped), {{ $minions['busy'] }}, {{ auth()->id() }})">
            <div class="mini-stage" id="mini-stage" x-ref="stage">
                <div class="mini-floor"><span class="mf-ring"></span><span class="mf-ticks"></span><span class="mf-pad"></span></div>
                <span class="mini-glow"></span>
                <template x-for="m in minionSpots" :key="m.i">
                    <span class="mini-minion" :class="[m.kind, m.busy ? 'busy' : '']" :style="`--mx:${m.x}px;--my:${m.y}px`" :title="m.busy ? 'working' : 'idle'"></span>
                </template>
                <span class="mini-fighter" id="mini-fighter" x-ref="fighter"></span>
            </div>
            <div class="stage-foot" style="margin-top:8px">
                <b id="mini-name" x-text="name"></b>
                <button class="link" type="button" id="change-character" @click="$dispatch('sheet-open-tab', { tab: 'character' })">Change character</button>
            </div>
            <div class="mini-stats has-data">
                <div class="stat crew" :title="`${counts.busy} ${counts.busy === 1 ? 'minion' : 'minions'} working, ${counts.idle} idle`">
                    <span class="stat-icon crew-icons" x-ref="crew"></span>
                    <b><span x-text="counts.busy"></span><small x-text="`/${counts.total}`"></small></b><span class="stat-label">minions working</span>
                </div>
            </div>
        </div>
    </div>

    <div class="side-wrap has-data" style="display:flex;flex-direction:column;gap:18px">

        <div class="side" wire:ignore x-data="sparkChart(@js($hourly))">
            <h3 class="spark-h"><span>@include('livewire.fighter-sheet.icon', ['name' => 'spark'])Last 24 hours</span> <span class="spark-read" id="spark-read" aria-live="polite" x-html="readoutHtml"></span></h3>
            <div class="spark" id="spark24" role="group" aria-label="Damage per hour, last 24 hours" @mouseleave="hover = null">
                <template x-for="(b, i) in bars" :key="i">
                    <span
                        role="button"
                        :style="`height:${b.height}%`"
                        :class="{ on: shown === i }"
                        :tabindex="i === bars.length - 1 ? 0 : -1"
                        :aria-label="`${b.hour}, ${b.label} damage`"
                        @mouseenter="hover = i"
                        @click="pinned = pinned === i ? null : i"
                        @keydown="key($event, i)"
                    ></span>
                </template>
            </div>
            <div class="spark-axis"><span>yesterday</span><span>now</span></div>
        </div>

        <div class="side bymodel" wire:loading.class="is-loading" wire:target="setModelPeriod">
            <h3>@include('livewire.fighter-sheet.icon', ['name' => 'model'])By model <span class="mpick" id="mpick" x-data="modelPicker(@js(array_keys($modelPeriods)), @js($modelPeriod))" :class="{ open }" @click.outside="open = false">
                <button type="button" class="mpick-btn" id="mpick-btn" aria-haspopup="listbox" :aria-expanded="open.toString()" aria-label="By model period" @click="toggle(!open)" @keydown="keys($event)"><span id="model-meta">{{ $modelPeriods[$modelPeriod] ?? ucfirst($modelPeriod) }}</span><span class="chev">▼</span></button>
                <span class="mpick-menu" role="listbox" id="mpick-menu" tabindex="-1">
                    @foreach (array_keys($modelPeriods) as $i => $value)
                        <span role="option" data-p="{{ $value }}" aria-selected="{{ $modelPeriod === $value ? 'true' : 'false' }}" :class="{ active: active === {{ $i }} }" @click="choose('{{ $value }}')">{{ $modelPeriods[$value] }}</span>
                    @endforeach
                </span>
            </span></h3>
            <div class="mrows" id="mrows">
                @foreach ($byModel as $row)
                    @php
                        $name = match ($row['model']) { 'unknown' => 'Unknown', 'other' => 'Other models', default => ModelName::for($row['model']) };
                        $color = $row['family'] ? ModelFamily::from($row['family'])->hex() : '#9ca3af';
                        $share = round(100 * $row['damage'] / $modelTotal);
                    @endphp
                    <div class="mrow" title="{{ $name }}: {{ $share }}% of your damage"><i style="background:{{ $color }}"></i><span class="mname">{{ $name }}</span><b>{{ CompactNumber::format($row['damage']) }}</b><span class="mp">{{ $share }}%</span><span class="mbar"><span style="width:{{ number_format(100 * $row['damage'] / $modelMax, 1, '.', '') }}%;background:{{ $color }}"></span></span></div>
                @endforeach
            </div>
        </div>

        <div class="side buddy" id="buddy" wire:ignore x-data="clawdBuddyPanel({{ auth()->id() }})">
            <div class="combo" id="combo" aria-live="polite" :class="{ on: combo >= 2 }"><span class="combo-n">×<b id="combo-n" x-text="combo">0</b></span> combo<span class="combo-bar"><span id="combo-bar" x-ref="comboBar"></span></span></div>
            <div class="buddy-stage" x-ref="stage">
                <span class="clawd big busy" id="buddy-clawd" role="button" tabindex="0" aria-label="Clawd, cheers your hits"><svg viewBox="0 0 42 8" preserveAspectRatio="none" shape-rendering="crispEdges"></svg></span>
                <div class="buddy-floor"></div>
            </div>
            <div class="buddy-best">Best combo today <b id="combo-best" x-text="`×${bestToday}`">×0</b></div>
        </div>
    </div>
</aside>
