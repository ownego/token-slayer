<div class="forge-prototype" x-data="posePrototype()">
    <div class="forge-prototype-head">
        <span class="forge-kicker">Action silhouettes</span>
        <span class="forge-prototype-tag">Lancer shadow · reference motion</span>
    </div>

    <p class="forge-prototype-intro">Pick a row to see what its poses should do.</p>

    <div class="forge-actions" role="group" aria-label="Preview pose sheet actions">
        <template x-for="example in rows" x-bind:key="example.key">
            <button type="button" class="forge-action" x-on:click="select(example.key)" x-bind:aria-pressed="action === example.key">
                <span class="forge-action-number" x-text="String(example.row).padStart(2, '0')"></span>
                <span x-text="example.label"></span>
            </button>
        </template>
    </div>

    <div class="forge-motion-frame">
        <span class="forge-motion-grid" aria-hidden="true"></span>
        <button type="button" class="forge-motion-toggle" x-on:click="togglePlayback()" x-bind:disabled="motionReduced" x-bind:aria-label="motionReduced ? 'Motion disabled by system preference' : ended ? 'Replay death motion' : playing ? 'Pause motion' : 'Play motion'" x-text="motionReduced ? 'Still frame' : ended ? '↺ Replay' : playing ? 'Ⅱ Pause' : '▶ Play'"></button>
        <span class="forge-motion-direction">Facing →</span>
        <span class="forge-motion-ground" aria-hidden="true"></span>
        <span class="forge-motion-sprite" role="img" x-bind:aria-label="row.label + ' motion shown as a Lancer silhouette'" x-bind:style="{ '--forge-strip': 'url(&quot;' + row.strip + '&quot;)', '--forge-strip-width': (row.stripFrames * 100) + 'px', '--forge-frame-x': (-frame.spriteFrame * 100) + 'px' }"></span>
        <span class="forge-motion-index" x-text="'Frame ' + poseNumber + ' / ' + row.sequence.length"></span>
    </div>

    <div class="forge-prototype-meta">
        <span x-text="'Row ' + row.row + ' of 4'"></span>
        <strong x-text="row.label"></strong>
        <span x-text="row.sourcePoses + ' poses'">4 poses</span>
    </div>
    <p class="forge-prototype-caption" x-text="row.caption"></p>
    <p class="forge-prototype-note">Movement only; your sheet defines the appearance. Every pose faces right, with Walk in side profile.</p>
</div>
