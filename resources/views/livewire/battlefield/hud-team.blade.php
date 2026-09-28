{{-- Team damage panel: TODAY/MONTH/ALL TIME rolling windows + the viewer's
     own row on the current boss. Numbers animate via countTo() (hud/index.js);
     share and rank are plain text, matching the mockup's own countTo usage. --}}
<div class="t-body">
    <div class="t-title">TEAM DAMAGE <i>● live</i></div>
    <div class="t-row">
        <div class="t-stat today"><span>TODAY</span><b x-ref="today" x-effect="countTo($refs.today, today, fmtOne)">0</b></div>
        <div class="t-stat"><span>MONTH</span><b x-ref="month" x-effect="countTo($refs.month, month, fmtWhole)">0</b></div>
        <div class="t-stat"><span>ALL TIME</span><b x-ref="allTime" x-effect="countTo($refs.allTime, allTime, fmtWhole)">0</b></div>
    </div>
    <div class="me">
        {{-- The real avatar over the initial; a user with no avatar keeps the initial. --}}
        <span class="av">@if (auth()->user()?->avatarProxyUrl())<img src="{{ auth()->user()->avatarProxyUrl() }}" alt="" onerror="this.remove()">@endif{{ substr(auth()->user()?->name ?? '?', 0, 1) }}</span>
        <div>
            <div class="me-l">YOU · ON THIS BOSS</div>
            <div class="me-v">
                <span x-ref="meNum" x-effect="countTo($refs.meNum, you.damage)">0</span>
                <small x-text="Math.round(100 * you.share) + '%'">0%</small>
            </div>
        </div>
        <div class="me-r"><span x-text="you.rankLabel">–</span><small>RANK</small></div>
    </div>
</div>
