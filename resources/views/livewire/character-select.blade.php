<div
    x-data="characterSelectModal(@js($this->characters()), @js($equipped), @entangle('equippedKey'))"
    @open-character-select.window="open()"
>
    <div
        x-show="isOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click.self="close()"
        class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm"
    >
        <div class="cs-outer" id="cardRoot" x-bind:style="`--accent:${accent}`">
            <div class="cs-grain"></div>

            <!-- Only Character ships for now — future tabs (Item, ...) are a
                 matter of adding another .tab-btn, not a layout rework. -->
            <div class="tab-bar">
                <button class="tab-btn is-active" type="button">⚔ Character</button>
            </div>
            <div class="tab-bar-divider"></div>

            <div class="cs-root">
                <div class="roster">
                    <div class="roster-label"><span class="flourish"></span>Roster · <span x-text="characters.length"></span><span class="flourish"></span></div>
                    <div class="roster-scroll">
                        <div class="roster-grid">
                            <template x-for="(character, index) in characters" :key="character">
                                <div
                                    class="orb"
                                    x-bind:class="{ 'is-preview': character === previewKey, 'is-equipped': character === equippedKey }"
                                    tabindex="0"
                                    x-bind:style="`--accent:${accentFor(character)}; --d:${index}`"
                                    @click="selectCharacter(character)"
                                    @keydown.enter="selectCharacter(character)"
                                >
                                    <span class="halo"></span>
                                    <div class="orb-crop" x-bind:data-character="character"></div>
                                    <span class="orb-label" x-text="character"></span>
                                    <span class="check" x-show="character === equippedKey">✓</span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="preview">
                    <div class="preview-label"><span class="flourish"></span>Previewing<span class="flourish"></span></div>

                    <div class="preview-frame">
                        <span class="halo-outer"></span>
                        <span class="halo-ripple"></span>
                        <span class="halo-ripple delay"></span>
                        <span class="portal-pad"></span>
                        <span class="halo-core"></span>
                        <span class="energy-ping"></span>
                        <span class="energy-ping delay"></span>
                        <span class="tick-groove"></span>
                        <div class="tick-ring">
                            <template x-for="i in 16" :key="i"><span x-bind:style="`--i:${i - 1}`"></span></template>
                        </div>
                        <span class="spin-ring"></span>
                        <div class="preview-crop">
                            <span class="sunburst"></span>
                            <span class="holo-beam"></span>
                            <div class="preview-mount" x-ref="previewMount"></div>
                        </div>
                        <div class="nameplate"><span x-text="previewKey"></span></div>
                    </div>

                    <div class="actions">
                        <template x-for="skill in skills" :key="skill.id">
                            <button
                                type="button"
                                class="btn-move"
                                x-bind:class="{ 'is-active': skill.id === activeSkillId, 'btn-move-death': skill.id === 'death' }"
                                @click="selectSkill(skill.id)"
                            >
                                <div class="thumb-crop" x-bind:data-skill="skill.id"></div>
                                <span x-text="skill.label"></span>
                            </button>
                        </template>
                    </div>

                    <button
                        type="button"
                        class="btn-equip"
                        @click="equip()"
                        x-bind:disabled="previewKey === equippedKey"
                    >
                        <span class="core"></span>
                        <span class="sheen"></span>
                        <span class="label">
                            <span class="icon-cluster">
                                <span class="sword-fx sword-left">🗡️</span>
                                <span class="icon-wiggle">🛡</span>
                                <span class="sword-fx sword-right">🗡️</span>
                            </span>
                            <span x-text="previewKey === equippedKey ? 'Equipped' : 'Equip'"></span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* --- Card shell --- */
.cs-outer {
    position: relative;
    width: clamp(288px, 82vw, 1440px);
    max-height: 92vh;
    box-sizing: border-box;
    padding: 3px;
    background: linear-gradient(135deg, rgba(249,158,11,0.5), rgba(249,115,22,0.15) 30%, rgba(20,184,166,0.15) 70%, rgba(45,212,191,0.4));
    border-radius: 18px;
    overflow-x: hidden;
    overflow-y: auto;
    box-shadow: 0 25px 70px -15px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,255,255,0.04), 0 0 90px -20px rgba(249,115,22,0.25);
}
.cs-grain {
    position: absolute; inset: 0; z-index: 1; pointer-events: none; opacity: 0.05; mix-blend-mode: overlay;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}

/* --- Tab bar (Character today; Item lands later as another .tab-btn) --- */
.tab-bar { position: relative; z-index: 2; display: flex; gap: 6px; padding: 14px 20px 0; }
.tab-btn {
    display: flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 10px 10px 0 0;
    font-size: 12px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
    color: #78716c; background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.08); border-bottom: none;
    cursor: pointer; transition: all 0.2s ease; font-family: inherit;
}
.tab-btn.is-active {
    color: #fff;
    background: color-mix(in srgb, var(--accent, #fbbf24) 16%, transparent);
    border-color: color-mix(in srgb, var(--accent, #fbbf24) 55%, transparent);
    box-shadow: 0 -6px 18px color-mix(in srgb, var(--accent, #fbbf24) 22%, transparent);
}
.tab-bar-divider { position: relative; z-index: 2; height: 1px; margin: 0 20px; background: rgba(255,255,255,0.08); }

/* --- Roster + preview split --- */
.cs-root {
    position: relative; z-index: 2;
    display: flex; min-height: 540px;
    background:
        radial-gradient(ellipse 700px 400px at 25% 10%, color-mix(in srgb, var(--accent, #f97316) 14%, transparent), transparent 60%),
        radial-gradient(ellipse 600px 400px at 90% 90%, color-mix(in srgb, var(--accent, #f97316) 8%, transparent), transparent 60%),
        linear-gradient(160deg, #0a0e1a 0%, #05060d 100%);
    transition: background 0.5s ease;
    color: #e7e5e4;
    font-family: -apple-system, "Instrument Sans", system-ui, sans-serif;
    border-radius: 15px;
}
/* Left padding is larger than the other sides — an orb's hover
   scale(1.1) and its halo glow both bleed a few px past the 64px orb-crop,
   and the first grid column sat close enough to this edge for .cs-outer's
   overflow-x:hidden to clip that bleed. Width is grown by the same +20px
   the padding gained, so .roster-grid's available content width — and
   therefore each 64px-orb column's fit — stays exactly what it was before
   this change; growing padding alone would have squeezed the 3-column
   grid below the fixed 64px orb width instead. */
.roster { position: relative; width: 300px; padding: 20px 20px 20px 40px; }
.roster::after {
    content: ''; position: absolute; top: 0; right: 0; bottom: 0; width: 1px;
    background: linear-gradient(180deg, transparent, color-mix(in srgb, var(--accent, #fbbf24) 35%, transparent) 45%, color-mix(in srgb, var(--accent, #fbbf24) 35%, transparent) 55%, transparent);
    transition: background 0.4s ease;
}
.roster::before {
    content: '◆'; position: absolute; top: 50%; right: -6px; translate: 0 -50%;
    font-size: 12px; color: var(--accent, #fbbf24); text-shadow: 0 0 8px color-mix(in srgb, var(--accent, #fbbf24) 80%, transparent); z-index: 1;
    transition: color 0.4s ease, text-shadow 0.4s ease;
}
.roster-label {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    font-size: 11px; letter-spacing: 0.06em; color: #78716c; text-transform: uppercase; margin-bottom: 14px;
}
.flourish { flex: 1; max-width: 30px; height: 1px; background: linear-gradient(90deg, transparent, rgba(120,113,108,0.6)); }
.roster-label .flourish:last-child { background: linear-gradient(90deg, rgba(120,113,108,0.6), transparent); }
/* top padding clears the .is-preview halo's negative offset (-8px top,
   6px overhang past orb-crop, plus blur) so the first roster row's ring
   isn't clipped by this container's own scroll boundary; right padding is
   wider than the 6px scrollbar itself so content doesn't butt against it. */
.roster-scroll { max-height: 380px; overflow-y: auto; padding: 16px 14px 6px 0; scrollbar-width: thin; scrollbar-color: rgba(251,191,36,0.4) rgba(255,255,255,0.03); }
.roster-scroll::-webkit-scrollbar { width: 6px; }
.roster-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); border-radius: 3px; }
.roster-scroll::-webkit-scrollbar-thumb { background: rgba(251,191,36,0.4); border-radius: 3px; }
/* Firefox has no arbitrary-width scrollbar-css, only the thin/auto/none
   keywords — 'auto' is the closest available "bigger" state on hover. */
.roster-scroll:hover { scrollbar-width: auto; }
.roster-scroll:hover::-webkit-scrollbar { width: 10px; }
.roster-scroll:hover::-webkit-scrollbar-thumb { background: rgba(251,191,36,0.65); }
.roster-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }

.orb {
    position: relative; display: flex; flex-direction: column; align-items: center; gap: 6px; cursor: pointer;
    animation: orb-enter 0.4s cubic-bezier(.34,1.56,.64,1) backwards;
    animation-delay: calc(var(--d, 0) * 0.05s);
}
@keyframes orb-enter { from { opacity: 0; transform: scale(0.5) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.orb-crop { position: relative; width: 64px; height: 64px; border-radius: 50%; overflow: hidden; background: rgba(0,0,0,0.45); transition: transform 0.2s cubic-bezier(.34,1.56,.64,1); }
.orb:hover .orb-crop, .orb:focus .orb-crop { transform: scale(1.1); }
/* The animated tiles' Phaser games render at 260x260 internally — larger
   than this 64x64 circle on purpose, matching the design mockup's approach
   of rendering the character bigger than its window and letting
   overflow:hidden crop it into a close-up, instead of shrinking the whole
   canvas down to fit (which left the character looking small with dead
   space around it). Absolutely positioned + centered per-canvas (rather
   than flex-centering the container) because _mountAnimatedTile keeps the
   outgoing static canvas mounted alongside the incoming Phaser canvas
   until the new one has actually drawn a frame — flex would lay two
   simultaneous canvases out side by side and squash both; absolute
   positioning lets them overlap in place so the swap is invisible. */
.orb-crop canvas { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); image-rendering: pixelated; }
.orb-label { font-size: 9px; color: #78716c; }
.orb:hover .orb-label { color: #fdba74; }
.halo {
    position: absolute; top: -8px; left: 50%; translate: -50% 0; width: 76px; height: 76px; border-radius: 50%;
    background: radial-gradient(circle, color-mix(in srgb, var(--accent, #fbbf24) 65%, transparent), color-mix(in srgb, var(--accent, #fbbf24) 30%, transparent) 55%, transparent 75%);
    filter: blur(5px); animation: halo-pulse 1.6s ease-in-out infinite; z-index: -1;
    visibility: hidden;
}
.orb.is-preview .halo { visibility: visible; }
@keyframes halo-pulse { 0%,100% { opacity: 0.5; } 50% { opacity: 0.8; } }
.orb.is-preview .orb-crop { box-shadow: 0 0 0 2px #fff, 0 0 12px rgba(255,255,255,0.6); }
.orb:not(.is-preview):not(.is-equipped) .orb-crop { opacity: 0.72; }
.orb:not(.is-preview):not(.is-equipped):hover .orb-crop,
.orb:not(.is-preview):not(.is-equipped):focus .orb-crop { opacity: 1; }
.check {
    position: absolute; top: -4px; right: 10px; width: 16px; height: 16px; border-radius: 50%;
    background: #2dd4bf; color: #042f2e; font-size: 9px; font-weight: bold;
    display: flex; align-items: center; justify-content: center; box-shadow: 0 0 8px rgba(45,212,191,0.6);
}

/* --- Preview panel --- */
.preview { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px; padding: 30px; }
.preview-label { display: flex; align-items: center; gap: 8px; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #78716c; }
.preview-frame { position: relative; width: 210px; height: 210px; border-radius: 50%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; margin-bottom: 10px; }
.tick-groove {
    position: absolute; inset: -18px; border-radius: 50%;
    background: radial-gradient(circle closest-side, transparent 88%, color-mix(in srgb, var(--accent, #fbbf24) 45%, transparent) 95%, transparent 100%);
    filter: blur(2px); transition: background 0.4s ease;
}
.tick-ring { position: absolute; inset: -24px; pointer-events: none; }
.tick-ring span {
    position: absolute; top: 50%; left: 50%; width: 3px; height: 7px; border-radius: 3px;
    background: color-mix(in srgb, var(--accent, #fbbf24) 55%, #fff 10%);
    box-shadow: 0 0 4px color-mix(in srgb, var(--accent, #fbbf24) 60%, transparent);
    transform: translate(-50%, -124px) rotate(calc(var(--i) * 22.5deg));
    transform-origin: 50% 124px;
    animation: tick-sweep 3.2s linear infinite;
    animation-delay: calc(var(--i) * 0.2s);
    transition: background 0.4s ease, box-shadow 0.4s ease;
}
.tick-ring span:nth-child(4n+1) {
    width: 7px; height: 7px; border-radius: 0;
    clip-path: polygon(50% 0, 100% 50%, 50% 100%, 0 50%);
    background: linear-gradient(135deg, #fff, var(--accent, #f97316));
    box-shadow: 0 0 8px color-mix(in srgb, var(--accent, #fbbf24) 70%, transparent);
}
@keyframes tick-sweep { 0%, 92%, 100% { filter: brightness(1); } 4% { filter: brightness(1.8); } }
.sunburst {
    position: absolute; inset: -20%; z-index: 0;
    background: repeating-conic-gradient(from 0deg, color-mix(in srgb, var(--accent, #fbbf24) 12%, transparent) 0deg 8deg, transparent 8deg 20deg);
    animation: sunburst-breathe 4s ease-in-out infinite;
    transition: background 0.4s ease;
}
@keyframes sunburst-breathe { 0%,100% { opacity: 0.6; } 50% { opacity: 1; } }
.energy-ping {
    position: absolute; inset: 0; border-radius: 50%; z-index: 2;
    background: radial-gradient(circle closest-side, transparent 78%, color-mix(in srgb, #fff 45%, var(--accent, #fbbf24)) 90%, transparent 100%);
    filter: blur(2px); animation: energy-out 3s ease-out infinite; transition: background 0.4s ease;
}
.energy-ping.delay { animation-delay: 1.5s; }
@keyframes energy-out { 0% { transform: scale(0.97); opacity: 0; } 18% { transform: scale(1.08); opacity: 0.95; } 100% { transform: scale(1.45); opacity: 0; } }
.nameplate {
    position: absolute; left: 50%; bottom: -14px; translate: -50% 0; z-index: 4;
    padding: 5px 22px; border-radius: 999px;
    background: linear-gradient(180deg, #1c1917, #0a0908);
    border: 1px solid color-mix(in srgb, var(--accent, #fbbf24) 60%, transparent);
    box-shadow: 0 4px 14px rgba(0,0,0,0.6), 0 0 16px color-mix(in srgb, var(--accent, #fbbf24) 30%, transparent);
    white-space: nowrap; overflow: hidden;
    transition: border-color 0.4s ease, box-shadow 0.4s ease;
}
.nameplate::before {
    content: ''; position: absolute; top: 0; left: -60%; width: 40%; height: 100%;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,0.35), transparent);
    transform: skewX(-20deg); animation: sheen-move 3.5s ease-in-out infinite;
}
.nameplate span {
    font-size: 15px; font-weight: 600; letter-spacing: 0.03em;
    background: linear-gradient(135deg, #fff, var(--accent, #f97316));
    -webkit-background-clip: text; background-clip: text; color: transparent;
}
.holo-beam {
    position: absolute; left: 50%; bottom: 55px; translate: -50% 0;
    width: 220px; height: 260px;
    clip-path: polygon(39% 100%, 61% 100%, 95% 8%, 5% 8%);
    background: linear-gradient(0deg, color-mix(in srgb, var(--accent, #fbbf24) 45%, transparent) 0%, color-mix(in srgb, var(--accent, #fbbf24) 15%, transparent) 25%, transparent 48%);
    -webkit-mask-image: linear-gradient(to right, transparent, #000 35%, #000 65%, transparent);
    mask-image: linear-gradient(to right, transparent, #000 35%, #000 65%, transparent);
    filter: blur(2px); animation: beam-breathe 3.6s ease-in-out infinite;
    transition: background 0.4s ease;
}
@keyframes beam-breathe { 0%,100% { opacity: 0.55; } 50% { opacity: 0.95; } }
.halo-outer {
    position: absolute; left: 50%; bottom: 32px; translate: -50% 0; z-index: 0;
    width: 220px; height: 80px; border-radius: 50%;
    background: radial-gradient(ellipse, color-mix(in srgb, var(--accent, #fbbf24) 30%, transparent) 0%, color-mix(in srgb, var(--accent, #fbbf24) 10%, transparent) 45%, transparent 75%);
    filter: blur(4px); animation: portal-glow 2.6s ease-in-out infinite; transition: background 0.4s ease;
}
.portal-pad {
    position: absolute; left: 50%; bottom: 46px; translate: -50% 0; z-index: 0;
    width: 140px; height: 34px; border-radius: 50%;
    box-shadow: 0 0 20px 4px color-mix(in srgb, var(--accent, #fbbf24) 45%, transparent);
    background: radial-gradient(ellipse, color-mix(in srgb, var(--accent, #fbbf24) 55%, transparent) 0%, color-mix(in srgb, var(--accent, #fbbf24) 25%, transparent) 45%, transparent 80%);
    filter: blur(3px); animation: portal-glow 2.6s ease-in-out infinite; transition: background 0.4s ease;
}
@keyframes portal-glow { 0%,100% { opacity: 0.8; } 50% { opacity: 1; } }
.halo-core {
    position: absolute; left: 50%; bottom: 54px; translate: -50% 0; z-index: 0;
    width: 50px; height: 14px; border-radius: 50%;
    background: radial-gradient(ellipse, #fff, color-mix(in srgb, var(--accent, #fbbf24) 70%, #fff) 60%, transparent 100%);
    filter: blur(1px); animation: portal-glow 2.6s ease-in-out infinite;
}
.halo-ripple {
    position: absolute; left: 50%; bottom: 46px; translate: -50% 0; z-index: 0;
    width: 140px; height: 34px; border-radius: 50%;
    background: radial-gradient(ellipse, transparent 55%, color-mix(in srgb, var(--accent, #fbbf24) 50%, transparent) 70%, transparent 88%);
    filter: blur(2px); animation: halo-ripple-out 2.6s ease-out infinite;
}
.halo-ripple.delay { animation-delay: 1.3s; }
@keyframes halo-ripple-out { 0% { scale: 1 1; opacity: 0.7; } 100% { scale: 1.7 1.4; opacity: 0; } }
.spin-ring {
    position: absolute; inset: -6px; border-radius: 50%; z-index: -1;
    background: conic-gradient(from -45deg, #fff, var(--accent, #fbbf24) 40%, color-mix(in srgb, var(--accent, #fbbf24) 60%, #7f1d1d) 70%, #fff);
    filter: blur(3px); animation: ring-breathe 2.4s ease-in-out infinite; transition: background 0.4s ease;
}
@keyframes ring-breathe { 0%,100% { opacity: 0.75; filter: blur(3px); } 50% { opacity: 1; filter: blur(4px); } }
.spin-ring::after { content: ''; position: absolute; inset: 6px; border-radius: 50%; background: #05060d; }
.preview-crop { position: relative; width: 190px; height: 190px; border-radius: 50%; overflow: hidden; z-index: 1; }
.preview-mount { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
/* The Phaser game's internal resolution (260x260, game.js PREVIEW_SIZE) is
   larger than this 190x190 circular window on purpose — it gives the
   projectile's flight target room past the character's silhouette, AND
   (matching the design mockup) lets the character render at its natural
   close-up size with .preview-crop's overflow:hidden cropping the excess,
   instead of shrinking the whole canvas down to fit (which left the
   character looking small with dead space around it inside the ring). The
   canvas is left at its native 260x260 size and centered by this
   container's flex centering. */
/* The v52 mockup's own .preview-sprite renders each frame at 300px,
   cropped by this same 190px .preview-crop circle — a 300/190 = 1.579
   fill ratio. Our sprite renders at PREVIEW_SPRITE_SCALE (2.4) x its
   native 100px frame = 240px effective size, a 240/190 = 1.263 ratio —
   under the mockup's, which is why the character read as too small next
   to the design. Rather than re-tuning the scene's sprite scale itself
   (which would also need re-tuning the projectile's target offset and the
   effect overlay to match), this CSS transform closes the remaining gap
   (1.579 / 1.263 = 1.25) on top of the already-correctly-positioned canvas
   output — cropped the same way by .preview-crop's overflow:hidden. */
.preview-mount canvas {
    image-rendering: pixelated;
    transform: scale(1.25);
    filter: drop-shadow(0 6px 18px color-mix(in srgb, var(--accent, #fbbf24) 40%, transparent));
    transition: filter 0.4s ease;
}

/* --- Skill row --- */
.actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; }
.btn-move { display: flex; flex-direction: column; align-items: center; gap: 6px; width: 80px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 8px 8px 6px; cursor: pointer; transition: all 0.2s ease; }
.btn-move:hover {
    border-color: color-mix(in srgb, var(--accent, #fbbf24) 55%, transparent);
    background: color-mix(in srgb, var(--accent, #fbbf24) 8%, transparent);
    box-shadow: 0 6px 20px color-mix(in srgb, var(--accent, #fbbf24) 25%, transparent);
    transform: translateY(-3px);
}
.btn-move:active { transform: translateY(0) scale(0.96); }
.btn-move span { font-size: 11px; color: #a8a29e; text-align: center; }
.btn-move:hover span { color: color-mix(in srgb, var(--accent, #fbbf24) 70%, #fff 20%); }
.btn-move.is-active {
    border-color: var(--accent, #fbbf24);
    background: color-mix(in srgb, var(--accent, #fbbf24) 14%, transparent);
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent, #fbbf24) 45%, transparent), 0 6px 20px color-mix(in srgb, var(--accent, #fbbf24) 30%, transparent);
}
.btn-move.is-active span { color: color-mix(in srgb, var(--accent, #fbbf24) 75%, #fff 20%); }
/* Static (non-animated) icon per skill — a single representative frame
   drawn onto a 2D canvas via drawFighterFrame, the same technique as the
   roster's static tiles. The design mockup animates these; a static frame
   keeps the same "iconed" visual identity per skill without adding 5-6 more
   live Phaser.Game instances per open modal. */
.thumb-crop { position: relative; width: 56px; height: 56px; border-radius: 10px; overflow: hidden; background: rgba(0,0,0,0.35); }
.thumb-crop canvas { image-rendering: pixelated; }

/* --- Equip button --- */
.btn-equip {
    position: relative; overflow: hidden; font-weight: 800; font-size: 19px; letter-spacing: 0.04em; color: #fff;
    -webkit-text-stroke: 0.5px rgba(28,25,23,0.4);
    background: linear-gradient(135deg, #fff8e1, var(--accent, #f97316));
    border: none; border-radius: 999px; padding: 14px 46px; cursor: pointer;
    box-shadow: 0 0 0 2px rgba(0,0,0,0.5), 0 0 0 4px color-mix(in srgb, var(--accent, #fbbf24) 55%, transparent), 0 6px 26px color-mix(in srgb, var(--accent, #fbbf24) 45%, transparent), inset 0 1px 0 rgba(255,255,255,0.5);
    transition: box-shadow 0.2s ease, transform 0.2s ease, background 0.4s ease, filter 0.2s ease;
    animation: equip-breathe 2.2s ease-in-out infinite;
}
.btn-equip:disabled {
    cursor: default;
    opacity: 0.6;
    filter: grayscale(0.4);
    animation: none;
    box-shadow: 0 0 0 2px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.2);
}
.btn-equip:disabled .core,
.btn-equip:disabled .sheen,
.btn-equip:disabled .sword-fx { animation: none; opacity: 0; }
.btn-equip .core { position: absolute; inset: 0; border-radius: 999px; background: radial-gradient(circle, rgba(255,255,255,0.55), transparent 70%); animation: core-pulse 2.2s ease-in-out infinite; }
@keyframes core-pulse { 0%,100% { opacity: 0; transform: scale(0.6); } 50% { opacity: 0.55; transform: scale(1.15); } }
@keyframes equip-breathe {
    0%,100% { box-shadow: 0 0 0 2px rgba(0,0,0,0.5), 0 0 0 4px color-mix(in srgb, var(--accent, #fbbf24) 55%, transparent), 0 6px 26px color-mix(in srgb, var(--accent, #fbbf24) 45%, transparent), inset 0 1px 0 rgba(255,255,255,0.5); }
    50% { box-shadow: 0 0 0 2px rgba(0,0,0,0.5), 0 0 0 5px color-mix(in srgb, var(--accent, #fbbf24) 75%, transparent), 0 8px 34px color-mix(in srgb, var(--accent, #fbbf24) 65%, transparent), inset 0 1px 0 rgba(255,255,255,0.5); }
}
.btn-equip:hover { transform: translateY(-3px) scale(1.05); filter: brightness(1.1); animation-duration: 0.9s; }
.btn-equip:hover .core { animation-duration: 0.9s; }
.btn-equip:hover .sheen { animation-duration: 1.1s; }
.btn-equip:active { transform: translateY(0) scale(0.98); }
.btn-equip .sheen { position: absolute; top:0; left:-60%; width:40%; height:100%; background: linear-gradient(120deg, transparent, rgba(255,255,255,0.65), transparent); transform: skewX(-20deg); animation: sheen-move 2.8s ease-in-out infinite; z-index: 1; }
.btn-equip .label { position: relative; z-index: 2; text-shadow: 0 1px 0 rgba(255,255,255,0.35), 0 1px 3px rgba(0,0,0,0.35); }
.icon-wiggle { display: inline-block; animation: icon-wiggle 2.2s ease-in-out infinite; }
.icon-cluster { position: relative; display: inline-grid; place-items: center; width: 1em; }
.sword-fx { position: absolute; top: 50%; left: 50%; font-size: 14px; opacity: 0; z-index: 2; filter: drop-shadow(0 0 4px color-mix(in srgb, var(--accent, #fbbf24) 70%, transparent)); transition: transform 0.35s cubic-bezier(.34,1.56,.64,1), opacity 0.25s ease; pointer-events: none; }
.sword-left { transform: translate(calc(-50% - 20px), -50%) rotate(-40deg); }
.sword-right { transform: translate(calc(-50% + 20px), -50%) rotate(40deg) scaleX(-1); }
.btn-equip:hover .sword-left { opacity: 1; transform: translate(-50%, -50%) rotate(-12deg); }
.btn-equip:hover .sword-right { opacity: 1; transform: translate(-50%, -50%) rotate(12deg) scaleX(-1); }
@keyframes icon-wiggle { 0%, 100% { transform: rotate(0deg) scale(1); } 10% { transform: rotate(-12deg) scale(1.1); } 20% { transform: rotate(10deg) scale(1.1); } 30% { transform: rotate(-6deg) scale(1.05); } 40%, 85% { transform: rotate(0deg) scale(1); } }
@keyframes sheen-move { 0% { left: -60%; } 50% { left: 130%; } 100% { left: 130%; } }

/* --- Responsive --- */
@media (max-width: 480px) {
    .cs-root { flex-direction: column; min-height: 0; }
    .roster { width: 100%; padding: 16px; box-sizing: border-box; }
    .roster::after, .roster::before { display: none; }
    .roster-scroll { max-height: none; overflow-x: auto; overflow-y: hidden; padding-bottom: 6px; }
    .roster-grid { display: flex; gap: 14px; grid-template-columns: none; }
    .roster-grid .orb { flex: 0 0 auto; }
    .preview { padding: 20px 16px 24px; }
    .actions { gap: 8px; }
    .btn-move { width: 78px; padding: 6px 6px 5px; }
    .btn-equip { padding: 12px 32px; font-size: 17px; }
}
@media (max-width: 380px) {
    .preview-frame { transform: scale(0.85); }
    .preview-label { transform: scale(0.9); }
}
@media (max-height: 640px) {
    .cs-root { min-height: 0; }
}
</style>

<script>
    window.characterSelectModal = function (characters, startingCharacter, equippedKey) {
        return {
            characters,
            equippedKey,
            previewKey: startingCharacter,
            accent: '#fbbf24',
            skills: [],
            activeSkillId: 'idle',
            isOpen: false,
            previewGame: null,
            previewScene: null,
            equippedTileGame: null,
            previewTileGame: null,
            _previewTileKey: null,
            _equippedTileKey: null,
            _skillThumbTimers: [],

            init() {
                // no-op until open() — the preview game only exists while the modal is open.
            },

            open() {
                this.previewKey = this.equippedKey ?? this.previewKey;
                this.isOpen = true;
                this.$nextTick(() => this._bootPreview());
            },

            _bootPreview() {
                if (!this.isOpen) {
                    return;
                }
                const bf = window.__battlefield;
                if (!bf?.createCharacterPreview) {
                    setTimeout(() => this._bootPreview(), 50);
                    return;
                }
                if (this.previewGame) {
                    return;
                }
                this.previewGame = bf.createCharacterPreview(this.$refs.previewMount);
                this.previewGame.events.once('preview-ready', () => {
                    if (!this.isOpen) {
                        return;
                    }
                    this.previewScene = this.previewGame.scene.getScene('character-preview');
                    this._applyCharacter(this.previewKey);
                });
                this._drawStaticThumbnails();
            },

            close() {
                window.__battlefield?.destroyCharacterPreview?.(this.previewGame);
                window.__battlefield?.destroyCharacterPreview?.(this.previewTileGame);
                window.__battlefield?.destroyCharacterPreview?.(this.equippedTileGame);
                this._skillThumbTimers.forEach(clearInterval);
                this._skillThumbTimers = [];
                this.previewGame = null;
                this.previewScene = null;
                this.previewTileGame = null;
                this.equippedTileGame = null;
                this._previewTileKey = null;
                this._equippedTileKey = null;
                this.isOpen = false;
            },

            selectCharacter(key) {
                if (key === this.previewKey) {
                    return;
                }
                this.previewKey = key;
                this._applyCharacter(key);
            },

            _applyCharacter(key) {
                this.accent = this.accentFor(key);
                this.previewScene?.setCharacter(key);
                this.skills = this.previewScene?.getMoveset()?.skills ?? [];
                this.activeSkillId = 'idle';
                this._refreshAnimatedTiles();
                this.$nextTick(() => this._drawSkillThumbnails());
            },

            selectSkill(skillId) {
                this.activeSkillId = skillId;
                this.previewScene?.selectSkill(skillId);
            },

            accentFor(key) {
                const ACCENTS = {
                    soldier: '#ffbb00', knight: '#ffbb00', swordsman: '#ffbb00', axeman: '#ffbb00',
                    orc: '#88ee44', 'armored-orc': '#88ee44', 'elite-orc': '#88ee44',
                    skeleton: '#aaddff', 'armored-skeleton': '#aaddff', 'greatsword-skeleton': '#aaddff',
                    slime: '#ddff44', archer: '#ffee44', werewolf: '#cc88ff', werebear: '#cc88ff',
                    'orc-rider': '#88ee44',
                };
                return ACCENTS[key] ?? '#fbbf24';
            },

            // The other 13 (non-animated) tiles use the existing static-frame
            // renderer — unchanged from the shipped modal.
            _drawStaticThumbnails() {
                if (!this.isOpen) {
                    return;
                }
                const bf = window.__battlefield;
                if (!bf?.game) {
                    setTimeout(() => this._drawStaticThumbnails(), 50);
                    return;
                }
                this.$root.querySelectorAll('.orb-crop').forEach(el => {
                    const key = el.dataset.character;
                    if (key === this.previewKey || key === this.equippedKey) {
                        return; // these two get an animated tile instead
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = 64;
                    canvas.height = 64;
                    el.replaceChildren(canvas);
                    bf.drawFighterPreview(bf.game, canvas, key);
                });
            },

            // A small looping thumbnail per skill button — same static-frame
            // drawFighterFrame technique as the roster's static tiles, but
            // cycled on a plain setInterval across that skill's real frame
            // count (skill.frames) instead of a live Phaser.Game per button,
            // which would multiply the tile-churn cost the final review
            // already flagged for the roster's two animated tiles.
            _drawSkillThumbnails() {
                this._skillThumbTimers.forEach(clearInterval);
                this._skillThumbTimers = [];
                const bf = window.__battlefield;
                if (!bf?.drawFighterFrame) {
                    return;
                }
                this.$root.querySelectorAll('.thumb-crop').forEach(el => {
                    const skill = this.skills.find(s => s.id === el.dataset.skill);
                    if (!skill) {
                        return;
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = 56;
                    canvas.height = 56;
                    el.replaceChildren(canvas);
                    let frameIndex = 0;
                    const drawFrame = () => {
                        bf.drawFighterFrame(bf.game, canvas, `${skill.animKey}-${frameIndex}`);
                        frameIndex = (frameIndex + 1) % skill.frames;
                    };
                    drawFrame();
                    // skill.rate is the animation's authored frames-per-second
                    // (config/fighters.js) — a flat interval here made every
                    // thumbnail loop at the same speed regardless of the real
                    // animation's rate (idle=8fps, walk=10, attack=12,
                    // death=6), reading as too fast for the slower ones.
                    this._skillThumbTimers.push(setInterval(drawFrame, 1000 / skill.rate));
                });
            },

            // Previewed + equipped tiles each get their own tiny idle-looping
            // Phaser.Game — a plan-time decision (see spec's Technical
            // architecture section) favoring two small decoupled games over
            // sharing coordinate space with the main preview panel.
            _refreshAnimatedTiles() {
                const bf = window.__battlefield;
                if (!bf?.createCharacterPreview) {
                    return;
                }
                if (this._previewTileKey !== this.previewKey) {
                    bf.destroyCharacterPreview(this.previewTileGame);
                    this.previewTileGame = this._mountAnimatedTile(this.previewKey);
                    this._previewTileKey = this.previewKey;
                }
                const wantEquippedTile = this.equippedKey && this.equippedKey !== this.previewKey;
                if (wantEquippedTile) {
                    if (this._equippedTileKey !== this.equippedKey) {
                        bf.destroyCharacterPreview(this.equippedTileGame);
                        this.equippedTileGame = this._mountAnimatedTile(this.equippedKey);
                        this._equippedTileKey = this.equippedKey;
                    }
                } else if (this.equippedTileGame) {
                    bf.destroyCharacterPreview(this.equippedTileGame);
                    this.equippedTileGame = null;
                    this._equippedTileKey = null;
                }
                this._drawStaticThumbnails();
            },

            // Keeps the outgoing static canvas mounted until the new Phaser
            // game has actually drawn a frame — clearing it upfront left the
            // orb blank (just its own dark background) for the one tick
            // between game creation and 'preview-ready', a visible flash
            // every time a character was selected.
            _mountAnimatedTile(key) {
                const bf = window.__battlefield;
                const el = this.$root.querySelector(`.orb-crop[data-character="${key}"]`);
                if (!el) {
                    return null;
                }
                const outgoing = [...el.children];
                const game = bf.createCharacterPreview(el);
                game.events.once('preview-ready', () => {
                    game.scene.getScene('character-preview').setCharacter(key);
                    outgoing.forEach(child => child.remove());
                });
                return game;
            },

            equip() {
                if (this.previewKey === this.equippedKey) {
                    return;
                }
                this.$wire.equip(this.previewKey);
                this.equippedKey = this.previewKey;
                this.close();
            },
        };
    };
</script>
