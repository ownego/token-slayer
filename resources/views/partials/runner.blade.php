{{-- The game-style loader: the viewer's own fighter (painted by runnerSprite,
     resources/js/battlefield/sheet/runner.js) running along a striped bar.
     Progress is the `--p` variable: the cover slides off the fill and the
     runner slides along with it, both by transform. With $coverId the arena's
     preload progress drives it (setRunnerProgress); without it the bar creeps
     on its own while the sheet's first render loads. --}}
<div class="ts-runner {{ empty($coverId) ? 'is-lapping' : '' }}" style="--p: 0">
    <div class="ts-runner-lane">
        <div class="ts-runner-track"><div class="ts-runner-fill"></div><div class="ts-runner-cover" @if (! empty($coverId)) id="{{ $coverId }}" @endif></div></div>
        <span class="ts-runner-body" wire:ignore x-data="runnerSprite()" data-char="{{ $char ?? '' }}"></span>
    </div>
    <span class="ts-runner-label">{{ $label ?? 'Loading…' }}</span>
</div>
