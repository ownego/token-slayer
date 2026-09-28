{{-- Ported verbatim from the approved mockup's Character tab; character-stage.js
     fills the roster, hotbar and stage exactly as the mockup's own script does. --}}
<div class="char-body" wire:ignore x-data="characterStage(@js($equipped), @js($roommates), @js(auth()->user()->displayHandle()), {{ auth()->id() }})">
        <aside class="roster">
            <div class="roster-head">
                <h3><svg class="px-ic" viewBox="0 0 8 8" aria-hidden="true" shape-rendering="crispEdges"><rect x="0" y="0" width="1" height="1"/><rect x="1" y="0" width="1" height="1"/><rect x="2" y="0" width="1" height="1"/><rect x="3" y="0" width="1" height="1"/><rect x="4" y="0" width="1" height="1"/><rect x="5" y="0" width="1" height="1"/><rect x="6" y="0" width="1" height="1"/><rect x="7" y="0" width="1" height="1"/><rect x="0" y="1" width="1" height="1"/><rect x="7" y="1" width="1" height="1"/><rect x="0" y="2" width="1" height="1"/><rect x="2" y="2" width="1" height="1"/><rect x="3" y="2" width="1" height="1"/><rect x="4" y="2" width="1" height="1"/><rect x="5" y="2" width="1" height="1"/><rect x="7" y="2" width="1" height="1"/><rect x="0" y="3" width="1" height="1"/><rect x="2" y="3" width="1" height="1"/><rect x="3" y="3" width="1" height="1"/><rect x="4" y="3" width="1" height="1"/><rect x="5" y="3" width="1" height="1"/><rect x="7" y="3" width="1" height="1"/><rect x="1" y="4" width="1" height="1"/><rect x="3" y="4" width="1" height="1"/><rect x="4" y="4" width="1" height="1"/><rect x="6" y="4" width="1" height="1"/><rect x="1" y="5" width="1" height="1"/><rect x="6" y="5" width="1" height="1"/><rect x="2" y="6" width="1" height="1"/><rect x="5" y="6" width="1" height="1"/><rect x="3" y="7" width="1" height="1"/><rect x="4" y="7" width="1" height="1"/></svg>Roster</h3>
                <span class="meta" id="roster-count"></span>
            </div>
            <div class="chips" role="group" aria-label="Filter by fighting style">
                <button class="chip" aria-pressed="true" data-type="">All</button>
                <button class="chip" aria-pressed="false" data-type="SLASH">Slash</button>
                <button class="chip" aria-pressed="false" data-type="BLADE">Blade</button>
                <button class="chip" aria-pressed="false" data-type="ARROW">Arrow</button>
                <button class="chip" aria-pressed="false" data-type="BLAST">Blast</button>
                <button class="chip" aria-pressed="false" data-type="SHURIKEN">Shuriken</button>
            </div>
            <div class="roster-scroll" id="roster-scroll">
                <div class="roster-grid" id="roster-grid" role="listbox" aria-label="Characters"></div>
            </div>
        </aside>

        <section class="showcase">
            <div class="show-stage" id="show-stage">
                <div class="backdrop" aria-hidden="true">
                    <div class="bd-stars"><i style="left:83.1%;top:47.1%;--d:3.4s"></i><i style="left:14.9%;top:20.5%;--d:1.0s"></i><i style="left:93.8%;top:9.4%;--d:3.2s"></i><i style="left:24.8%;top:12.4%;--d:1.9s"></i><i style="left:24.7%;top:9.8%;--d:1.9s"></i><i style="left:61.8%;top:32.0%;--d:3.9s"></i><i style="left:78.2%;top:36.6%;--d:0.7s"></i><i style="left:33.1%;top:7.4%;--d:0.7s"></i><i style="left:23.8%;top:53.3%;--d:2.8s"></i><i style="left:46.4%;top:26.7%;--d:2.6s"></i><i style="left:56.9%;top:50.4%;--d:2.2s"></i><i style="left:45.4%;top:44.8%;--d:2.3s"></i><i style="left:47.6%;top:41.3%;--d:1.3s"></i><i style="left:26.9%;top:8.3%;--d:0.8s"></i><i style="left:12.3%;top:22.2%;--d:2.6s"></i><i style="left:94.7%;top:48.8%;--d:1.1s"></i><i style="left:36.7%;top:28.5%;--d:2.2s"></i><i style="left:52.1%;top:51.2%;--d:1.0s"></i><i style="left:19.4%;top:33.2%;--d:3.7s"></i><i style="left:67.0%;top:29.2%;--d:0.2s"></i><i style="left:88.9%;top:43.4%;--d:1.2s"></i><i style="left:17.8%;top:34.9%;--d:1.8s"></i><i style="left:94.0%;top:14.4%;--d:0.3s"></i><i style="left:90.8%;top:30.4%;--d:2.7s"></i><i style="left:70.4%;top:42.0%;--d:0.3s"></i><i style="left:38.2%;top:26.6%;--d:1.8s"></i><i style="left:73.7%;top:27.1%;--d:3.3s"></i><i style="left:64.3%;top:28.7%;--d:2.0s"></i></div>
                    <span class="bd-moon"></span>
                    <div class="bd-far"><svg class="bd-ridge" viewBox="0 0 400 60" preserveAspectRatio="none" shape-rendering="crispEdges"><polygon points="0,60 0,15 4,15 4,15 8,15 8,11 12,11 12,11 16,11 16,11 20,11 20,11 24,11 24,15 28,15 28,15 32,15 32,11 36,11 36,11 40,11 40,8 44,8 44,12 48,12 48,12 52,12 52,12 56,12 56,12 60,12 60,8 64,8 64,8 68,8 68,12 72,12 72,12 76,12 76,12 80,12 80,16 84,16 84,16 88,16 88,16 92,16 92,16 96,16 96,20 100,20 100,24 104,24 104,20 108,20 108,16 112,16 112,20 116,20 116,16 120,16 120,20 124,20 124,20 128,20 128,20 132,20 132,24 136,24 136,20 140,20 140,24 144,24 144,28 148,28 148,24 152,24 152,20 156,20 156,24 160,24 160,24 164,24 164,20 168,20 168,20 172,20 172,24 176,24 176,20 180,20 180,24 184,24 184,28 188,28 188,28 192,28 192,28 196,28 196,28 200,28 200,32 204,32 204,32 208,32 208,34 212,34 212,34 216,34 216,34 220,34 220,34 224,34 224,34 228,34 228,34 232,34 232,34 236,34 236,34 240,34 240,30 244,30 244,30 248,30 248,26 252,26 252,22 256,22 256,18 260,18 260,18 264,18 264,14 268,14 268,14 272,14 272,18 276,18 276,18 280,18 280,22 284,22 284,26 288,26 288,30 292,30 292,30 296,30 296,30 300,30 300,30 304,30 304,34 308,34 308,34 312,34 312,34 316,34 316,34 320,34 320,34 324,34 324,34 328,34 328,34 332,34 332,34 336,34 336,30 340,30 340,30 344,30 344,34 348,34 348,30 352,30 352,34 356,34 356,34 360,34 360,34 364,34 364,34 368,34 368,34 372,34 372,30 376,30 376,34 380,34 380,34 384,34 384,34 388,34 388,34 392,34 392,34 396,34 396,34 400,34 400,30 404,30 400,60"/></svg><svg class="bd-ridge" viewBox="0 0 400 60" preserveAspectRatio="none" shape-rendering="crispEdges"><polygon points="0,60 0,15 4,15 4,15 8,15 8,11 12,11 12,11 16,11 16,11 20,11 20,11 24,11 24,15 28,15 28,15 32,15 32,11 36,11 36,11 40,11 40,8 44,8 44,12 48,12 48,12 52,12 52,12 56,12 56,12 60,12 60,8 64,8 64,8 68,8 68,12 72,12 72,12 76,12 76,12 80,12 80,16 84,16 84,16 88,16 88,16 92,16 92,16 96,16 96,20 100,20 100,24 104,24 104,20 108,20 108,16 112,16 112,20 116,20 116,16 120,16 120,20 124,20 124,20 128,20 128,20 132,20 132,24 136,24 136,20 140,20 140,24 144,24 144,28 148,28 148,24 152,24 152,20 156,20 156,24 160,24 160,24 164,24 164,20 168,20 168,20 172,20 172,24 176,24 176,20 180,20 180,24 184,24 184,28 188,28 188,28 192,28 192,28 196,28 196,28 200,28 200,32 204,32 204,32 208,32 208,34 212,34 212,34 216,34 216,34 220,34 220,34 224,34 224,34 228,34 228,34 232,34 232,34 236,34 236,34 240,34 240,30 244,30 244,30 248,30 248,26 252,26 252,22 256,22 256,18 260,18 260,18 264,18 264,14 268,14 268,14 272,14 272,18 276,18 276,18 280,18 280,22 284,22 284,26 288,26 288,30 292,30 292,30 296,30 296,30 300,30 300,30 304,30 304,34 308,34 308,34 312,34 312,34 316,34 316,34 320,34 320,34 324,34 324,34 328,34 328,34 332,34 332,34 336,34 336,30 340,30 340,30 344,30 344,34 348,34 348,30 352,30 352,34 356,34 356,34 360,34 360,34 364,34 364,34 368,34 368,34 372,34 372,30 376,30 376,34 380,34 380,34 384,34 384,34 388,34 388,34 392,34 392,34 396,34 396,34 400,34 400,30 404,30 400,60"/></svg></div>
                    <div class="bd-near"><svg class="bd-ridge" viewBox="0 0 400 60" preserveAspectRatio="none" shape-rendering="crispEdges"><polygon points="0,60 0,40 4,40 4,40 8,40 8,44 12,44 12,46 16,46 16,46 20,46 20,46 24,46 24,46 28,46 28,46 32,46 32,46 36,46 36,42 40,42 40,38 44,38 44,42 48,42 48,42 52,42 52,42 56,42 56,46 60,46 60,46 64,46 64,46 68,46 68,42 72,42 72,38 76,38 76,38 80,38 80,38 84,38 84,34 88,34 88,30 92,30 92,30 96,30 96,34 100,34 100,38 104,38 104,42 108,42 108,38 112,38 112,38 116,38 116,38 120,38 120,38 124,38 124,42 128,42 128,46 132,46 132,46 136,46 136,46 140,46 140,42 144,42 144,42 148,42 148,38 152,38 152,42 156,42 156,42 160,42 160,38 164,38 164,34 168,34 168,30 172,30 172,26 176,26 176,22 180,22 180,22 184,22 184,22 188,22 188,26 192,26 192,26 196,26 196,26 200,26 200,26 204,26 204,26 208,26 208,30 212,30 212,26 216,26 216,26 220,26 220,22 224,22 224,26 228,26 228,26 232,26 232,26 236,26 236,22 240,22 240,26 244,26 244,22 248,22 248,22 252,22 252,26 256,26 256,26 260,26 260,26 264,26 264,26 268,26 268,30 272,30 272,26 276,26 276,30 280,30 280,30 284,30 284,30 288,30 288,34 292,34 292,30 296,30 296,30 300,30 300,30 304,30 304,26 308,26 308,22 312,22 312,22 316,22 316,26 320,26 320,22 324,22 324,22 328,22 328,22 332,22 332,26 336,26 336,26 340,26 340,26 344,26 344,22 348,22 348,22 352,22 352,26 356,26 356,30 360,30 360,26 364,26 364,22 368,22 368,22 372,22 372,22 376,22 376,22 380,22 380,22 384,22 384,26 388,26 388,26 392,26 392,26 396,26 396,22 400,22 400,22 404,22 400,60"/></svg><svg class="bd-ridge" viewBox="0 0 400 60" preserveAspectRatio="none" shape-rendering="crispEdges"><polygon points="0,60 0,40 4,40 4,40 8,40 8,44 12,44 12,46 16,46 16,46 20,46 20,46 24,46 24,46 28,46 28,46 32,46 32,46 36,46 36,42 40,42 40,38 44,38 44,42 48,42 48,42 52,42 52,42 56,42 56,46 60,46 60,46 64,46 64,46 68,46 68,42 72,42 72,38 76,38 76,38 80,38 80,38 84,38 84,34 88,34 88,30 92,30 92,30 96,30 96,34 100,34 100,38 104,38 104,42 108,42 108,38 112,38 112,38 116,38 116,38 120,38 120,38 124,38 124,42 128,42 128,46 132,46 132,46 136,46 136,46 140,46 140,42 144,42 144,42 148,42 148,38 152,38 152,42 156,42 156,42 160,42 160,38 164,38 164,34 168,34 168,30 172,30 172,26 176,26 176,22 180,22 180,22 184,22 184,22 188,22 188,26 192,26 192,26 196,26 196,26 200,26 200,26 204,26 204,26 208,26 208,30 212,30 212,26 216,26 216,26 220,26 220,22 224,22 224,26 228,26 228,26 232,26 232,26 236,26 236,22 240,22 240,26 244,26 244,22 248,22 248,22 252,22 252,26 256,26 256,26 260,26 260,26 264,26 264,26 268,26 268,30 272,30 272,26 276,26 276,30 280,30 280,30 284,30 284,30 288,30 288,34 292,34 292,30 296,30 296,30 300,30 300,30 304,30 304,26 308,26 308,22 312,22 312,22 316,22 316,26 320,26 320,22 324,22 324,22 328,22 328,22 332,22 332,26 336,26 336,26 340,26 340,26 344,26 344,22 348,22 348,22 352,22 352,26 356,26 356,30 360,30 360,26 364,26 364,22 368,22 368,22 372,22 372,22 376,22 376,22 380,22 380,22 384,22 384,26 388,26 388,26 392,26 392,26 396,26 396,22 400,22 400,22 404,22 400,60"/></svg></div>
                    <span class="bd-ground"></span>
                </div>
                <span class="ember" style="--x:12%;--d:0s"></span><span class="ember" style="--x:34%;--d:1.7s"></span><span class="ember" style="--x:58%;--d:3.1s"></span><span class="ember" style="--x:81%;--d:.9s"></span><span class="ember" style="--x:47%;--d:4.2s"></span>
                <div class="aura" aria-hidden="true"><span class="aura-glow"></span></div>
                <div class="flames" id="flames" aria-hidden="true"><span class="wisp" style="--side:-1;--sx:-8px;--d:0.00s;--h:1.00;--w:26px"></span><span class="wisp" style="--side:1;--sx:8px;--d:0.28s;--h:0.95;--w:24px"></span><span class="wisp" style="--side:-1;--sx:-22px;--d:0.55s;--h:0.80;--w:20px"></span><span class="wisp" style="--side:1;--sx:22px;--d:0.83s;--h:0.85;--w:22px"></span><span class="wisp" style="--side:-1;--sx:-36px;--d:1.10s;--h:0.62;--w:16px"></span><span class="wisp" style="--side:1;--sx:36px;--d:1.38s;--h:0.66;--w:17px"></span><span class="wisp" style="--side:-1;--sx:-14px;--d:1.65s;--h:1.05;--w:22px"></span><span class="wisp" style="--side:1;--sx:14px;--d:1.93s;--h:1.00;--w:24px"></span><span class="wisp" style="--side:-1;--sx:-30px;--d:2.20s;--h:0.72;--w:18px"></span><span class="wisp" style="--side:1;--sx:30px;--d:2.48s;--h:0.70;--w:18px"></span><span class="mote" style="--side:-1;--sx:-40px;--d:0.00s"></span><span class="mote" style="--side:1;--sx:44px;--d:0.45s"></span><span class="mote" style="--side:-1;--sx:-58px;--d:0.90s"></span><span class="mote" style="--side:1;--sx:60px;--d:1.35s"></span><span class="mote" style="--side:-1;--sx:-28px;--d:1.80s"></span><span class="mote" style="--side:1;--sx:30px;--d:2.25s"></span><span class="mote" style="--side:-1;--sx:-50px;--d:2.70s"></span><span class="mote" style="--side:1;--sx:52px;--d:3.10s"></span></div>
                <div class="portal" aria-hidden="true">
                    <div class="floor">
                        <span class="halo-outer"></span>
                        <span class="spin-ring"></span>
                        <span class="tick-groove"></span>
                        <div class="tick-ring" id="tick-ring"></div>
                        <span class="energy-ping"></span><span class="energy-ping delay"></span>
                        <span class="portal-pad"></span>
                        <span class="halo-core"></span>
                        <span class="shock" id="shock"></span>
                    </div>
                    <span class="holo-beam"></span>
                </div>
                <span class="fx-circle" id="fx-circle"></span>
                <span class="fx-burst" id="fx-burst"></span>
                <span class="show-sprite" id="show-sprite"></span>
                <span class="stage-avatar" id="stage-avatar" title="{{ auth()->user()->displayHandle() }}"><img class="av-img" src="{{ route('avatar', auth()->user()) }}" alt="" onerror="this.remove()"><span class="av-face">{{ Str::upper(Str::substr(auth()->user()->displayHandle(), 0, 1)) }}</span></span>
                <span class="show-effect" id="show-effect"></span>
                <span class="boss" id="boss"></span>
                <span class="hit-spark" id="hit-spark"></span>
                <span class="flashbang" id="flashbang"></span>
                <span class="boss-shadow"></span>
                <div class="dmg-layer" id="dmg-layer"></div>
                <div class="banner" id="banner"></div>
                <div class="show-info">
                <div>
                    <h2 id="show-name"></h2>
                    <p class="show-type" id="show-type"></p>
                    <div class="also" id="also"></div>
                </div>
                <span class="show-state" id="show-state">Equipped</span>
            </div>

            </div>
            <div class="moves-head"><h3><svg class="px-ic" viewBox="0 0 8 8" aria-hidden="true" shape-rendering="crispEdges"><rect x="4" y="0" width="1" height="1"/><rect x="5" y="0" width="1" height="1"/><rect x="3" y="1" width="1" height="1"/><rect x="4" y="1" width="1" height="1"/><rect x="2" y="2" width="1" height="1"/><rect x="3" y="2" width="1" height="1"/><rect x="1" y="3" width="1" height="1"/><rect x="2" y="3" width="1" height="1"/><rect x="3" y="3" width="1" height="1"/><rect x="4" y="3" width="1" height="1"/><rect x="5" y="3" width="1" height="1"/><rect x="3" y="4" width="1" height="1"/><rect x="4" y="4" width="1" height="1"/><rect x="2" y="5" width="1" height="1"/><rect x="3" y="5" width="1" height="1"/><rect x="1" y="6" width="1" height="1"/><rect x="2" y="6" width="1" height="1"/><rect x="0" y="7" width="1" height="1"/></svg>Moves</h3><span id="move-max" hidden></span></div>
            <div class="hotbar" id="hotbar"></div>
            <div class="move-name" id="move-name" aria-live="polite"></div>

            <div class="equip-row">
                <span class="equip-note" id="equip-note"></span>
                <button class="btn" type="button" id="back-equipped" hidden>Back to mine</button>
                <button class="btn primary btn-equip" type="button" id="equip-btn" disabled>
                    <span class="core"></span><span class="sheen"></span>
                    <span class="label"><span class="icon-cluster"><span class="sword-fx sword-left">🗡️</span><span class="icon-wiggle">🛡</span><span class="sword-fx sword-right">🗡️</span></span><span id="equip-label">Equipped</span></span>
                </button>
            </div>
        </section>
    </div>
