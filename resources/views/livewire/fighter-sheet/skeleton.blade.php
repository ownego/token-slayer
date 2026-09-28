{{-- Shown the instant the nav asks for the sheet, until its first render lands
     (later opens show the already-loaded sheet instead): the real sheet's frame
     and size with Clawd bouncing on the start line; once the sheet is ready
     it leaps to the goal (sheet/clawd-hop.js), then the content fills in. --}}
<div class="fs fs-skel" aria-hidden="true">
    <div class="overlay">
        <section class="sheet">
            <header class="sheet-head"><h1>{{ auth()->user()?->displayHandle() }}</h1></header>
            <div class="skel-runner">
                <div class="ts-hop">
                    <div class="ts-hop-lane">
                        <span class="ts-hop-goal"><i></i></span>
                        <span class="ts-hop-mover"><span class="ts-hop-body"><svg class="ts-hop-clawd" viewBox="0 0 18 6" wire:ignore x-data="clawdHop()"></svg></span></span>
                    </div>
                    <span class="ts-hop-label">Loading…</span>
                </div>
            </div>
        </section>
    </div>
</div>
