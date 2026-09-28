import Phaser from 'phaser';
import { BAT_CONFIG, LAYOUTS, BOSS_TYPES, MINION_TYPES, MINION_CLASH_EFFECTS, NECROMANCER_CONFIG } from '@battlefield/config.js';
import { BusEvent, TextureKey, SCENE_KEY, WORLD_ZOOM } from '@battlefield/constants.js';
import { bindBus } from '@battlefield/shared/bus-bindings.js';
import { focusPlan, restoreCenter } from '@battlefield/shared/camera-focus.js';
import { ATLAS_VERSION } from './config/atlas-version.js';
import { bus } from './bus.js';
import { Impact } from './impact.js';
import { Projectile } from './projectile.js';
import { Attacks } from './attacks.js';
import { Boss, queueBossLoad } from './boss.js';
import { Charge } from './charge.js';
import { Bubble } from './bubble.js';
import { MoveInput } from './move-input.js';
import { Fighter } from './fighter.js';
import { Necromancer } from './necromancer.js';
import { Minions } from './minions.js';
import { ensureSparkTexture, ensureSparkStreakTexture, ensurePuffTexture, ensureClawdTextures, ensureSoftGlowTexture, createFlairGlyphCache } from './spark-texture.js';
import { registerAllFighterAnimations } from './fighter/animations.js';
import { SPARK_EMITTER, sparkAngle } from './shared/sparks.js';
import { createSpawnGate } from './ceremony.js';
import { createEnvironment } from './environment/index.js';
import { loadAvatarTexture, makeFallbackAvatarTexture } from './fighter/avatar.js';
import { setRunnerProgress } from '@battlefield/sheet/runner.js';

/** Phaser scene coordinator — wires all battlefield managers and handles the Phaser lifecycle. */
export class BattlefieldScene extends Phaser.Scene {
  constructor() {
    super(SCENE_KEY);
  }

  /**
   * Loads all sprite sheets, atlases, and FX spritesheets needed by the scene.
   *
   * @return {void}
   */
  preload() {
    // Single atlas covers all 138 fighter strips. ?v= busts the host
    // nginx's 7-day must-revalidate cache on this non-hashed filename the
    // moment a roster/animation change actually changes its content — see
    // pack-sprites.js's own docblock for ATLAS_VERSION.
    if (!this.textures.exists(TextureKey.FIGHTERS)) {
      this.load.atlas(
        TextureKey.FIGHTERS,
        `/assets/battlefield/fighters/fighters-atlas.png?v=${ATLAS_VERSION}`,
        `/assets/battlefield/fighters/fighters-atlas.json?v=${ATLAS_VERSION}`,
      );
    }
    // Every boss type preloaded here (rather than just the one about to be
    // shown) blew mobile GPUs' 4096px texture budget once the roster grew;
    // only the active boss type loads up front. Boss.preloadNextType()
    // queues the type after it in the background once create() runs, and
    // handleBossSpawned() keeps that one-ahead window going on every kill.
    queueBossLoad(this, Boss.bossTypeOf(this.game.registry.get('initialState')?.boss));
    for (const companion of [BAT_CONFIG, NECROMANCER_CONFIG, ...MINION_TYPES, ...MINION_CLASH_EFFECTS]) {
      for (const [anim, info] of Object.entries(companion.animFiles)) {
        const texKey = `${companion.key}-${anim}`;
        if (!this.textures.exists(texKey)) {
          this.load.spritesheet(texKey, info.file, { frameWidth: info.frameWidth, frameHeight: info.frameHeight });
        }
      }
    }
    if (!this.textures.exists(TextureKey.FIREBALL))
      this.load.spritesheet(TextureKey.FIREBALL, '/assets/battlefield/fx/fireball.png', { frameWidth: 16, frameHeight: 16 });
    if (!this.textures.exists(TextureKey.EXPLOSION))
      this.load.spritesheet(TextureKey.EXPLOSION, '/assets/battlefield/fx/explosion.png', { frameWidth: 32, frameHeight: 32 });
    const loaderBar = document.getElementById('bf-loader-bar');
    const loader    = document.getElementById('bf-loader');
    // the loader's runner rides the real preload progress (partials/runner.blade.php)
    this.load.on('progress', v => setRunnerProgress(loaderBar, v));
    // `once`, not `on`: Boss.preloadNextType()/_awaitBossTypeReady() call
    // load.start() for a background boss type on every kill, which also
    // fires this loader's 'complete' event. The body below (pixel-art
    // filter pass, registerAllFighterAnimations) is boot-only setup and
    // must not silently re-run on every later boss-type load — that work
    // for a lazily-loaded boss type is already done by Boss's own
    // `_applyBossFilter`/`ensureBossIdleAnim`.
    this.load.once('complete', () => {
      if (loader) loader.style.display = 'none';
      const pixelArtKeys = [
        ...BOSS_TYPES.filter(b => b.pixelArt !== false).flatMap(b =>
          b.animFiles ? Object.keys(b.animFiles).map(anim => `${b.key}-${anim}`) : [b.key]
        ),
        ...[BAT_CONFIG, NECROMANCER_CONFIG, ...MINION_TYPES, ...MINION_CLASH_EFFECTS].flatMap(c => Object.keys(c.animFiles).map(anim => `${c.key}-${anim}`)),
        TextureKey.FIREBALL, TextureKey.EXPLOSION,
      ];
      for (const key of pixelArtKeys) {
        // Only the boot boss type is loaded at this point (see the lazy-load
        // comment above); every other boss type's keys are still unloaded
        // here and must not filter the shared `__MISSING` placeholder.
        if (this.textures.exists(key)) {
          this.textures.get(key).setFilter(Phaser.Textures.FilterMode.NEAREST);
        }
      }
      // Fighter atlas: NEAREST filter + register all animations from named frames
      this.textures.get(TextureKey.FIGHTERS)?.setFilter(Phaser.Textures.FilterMode.NEAREST);
      registerAllFighterAnimations(this);
    });
  }

  /**
   * Creates and wires all battlefield managers, seeds initial state, and registers bus handlers.
   *
   * @return {void}
   */
  create() {
    this.isShuttingDown = false;
    // scene.restart() (mode change, index.js's applyModeChange) reuses this
    // same Scene instance, so a prior teardown must re-arm here.
    this._tornDown = false;
    this.mode = this.game.registry.get('mode') ?? 'landscape';
    this.layout = LAYOUTS[this.mode];
    const L = this.layout;

    // The canvas is created at logical size * renderScale (see render-scale.js's
    // canvasSizeFor) purely for pixel density; zooming the camera by the
    // same factor keeps the whole scene authored in logical coordinates.
    // WORLD_ZOOM stacks an additional, deliberate (small) zoom-out on top of
    // that so the whole battlefield reads a little smaller within the same
    // on-screen area — see its own docblock in constants.js. centerOn is
    // required: with zoom alone the camera's view stays anchored on its own
    // midpoint, which would show the wrong half of the world.
    const renderScale = this.game.registry.get('renderScale') ?? 1;
    this.cameras.main.setZoom(renderScale * WORLD_ZOOM);
    this.cameras.main.centerOn(L.logicalWidth / 2, L.logicalHeight / 2);

    ensureSparkTexture(this);
    ensureSparkStreakTexture(this);
    ensurePuffTexture(this);
    ensureClawdTextures(this);
    ensureSoftGlowTexture(this);
    // The Fable-flair glyph cache is lazy per unique (char, color, size) —
    // no boot-time prewarm of "every configured flair label/colour" (per
    // the plan): the client has no list of those ahead of a live HitDealt
    // carrying one, and adding one would be a boot-payload/backend change
    // outside this task's JS-only scope. The very first flair of each
    // model still pays the bake cost once; every repeat (the common case,
    // ~29% of turns on the configured flair model) is now near-free.
    this.flairGlyphCache = createFlairGlyphCache(this);
    // Replaces the old flat BG_COLOR rectangle + static radial vignette
    // image: a real-astronomy sky (sun/moon, colour-graded gradient,
    // clouds, stars) that also tints the ridges/floor it draws, plus its
    // own vignette baked the same way the old one was.
    this.environment = createEnvironment(this, { layout: L, sky: this.game.registry.get('initialState')?.sky });

    // Read once at boot: heel sparks (Charge.emitFor) are skipped entirely
    // when the viewer asked the OS for reduced motion — the charging ring
    // alone stays the "charging" cue.
    this.reducedMotion = typeof window !== 'undefined' && typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // One scene-wide emitter for every fighter's heel sparks (charge.js's
    // emitFor calls emitParticleAt on it per-fighter, tinted/angled per-emit
    // via the scene-level _sparkFacing/_sparkPower/_sparkTint it sets just
    // before each call) — a shared emitter instead of one per fighter keeps
    // a 15-fighter charge burst well under the display-list cost the old
    // per-fighter fire+ember emitter pair had.
    this.sparks = this.add.particles(0, 0, TextureKey.SPARK_STREAK, {
      ...SPARK_EMITTER,
      angle: { onEmit: () => sparkAngle(this._sparkFacing ?? 1, Math.random) },
      speed: { onEmit: () => (120 + Math.random() * 130) * (this._sparkPower ?? 1) },
      rotate: { onEmit: () => 0, onUpdate: p => Phaser.Math.RadToDeg(Math.atan2(p.velocityY, p.velocityX)) },
      tint: { onEmit: () => this._sparkTint ?? 0xffffff },
    }).setDepth(1.9);

    // Kill ceremony (ceremony.js): holds a BOSS_SPAWNED (and the new boss's
    // first hits' visuals) that arrives mid-countdown until it's over —
    // EventController dispatches BossKilled and BossSpawned in the same
    // request, so this is the common case, not an edge case.
    this.spawnGate = createSpawnGate();
    // Real time, not this.time.delayedCall: only tweens.timeScale/anims.
    // globalTimeScale slow the ceremony's own slow-mo, and the countdown's
    // own pacing must stay steady regardless.
    this.wait = ms => new Promise(resolve => setTimeout(resolve, ms));

    const state = this.game.registry.get('initialState');
    this.boss = new Boss(this);
    this.boss.create(state);

    this.necromancer = new Necromancer(this);
    this.necromancer.create();

    this.bubble = new Bubble(this);
    this.charge = new Charge(this);
    this.impact = new Impact(this);
    this.projectile = new Projectile(this);
    this.attacks = new Attacks(this);

    this.fighters = new Map();
    this.damageTotals = new Map();
    // Live HUD zones (world space), refreshed by window.__battlefield.setHudZones
    // (index.js) — read by move-geometry.js's isValidMoveTarget/planRoute so
    // click-to-move rejects a target under a HUD panel. Fighters already
    // standing somewhere are never auto-repositioned by this (removed —
    // pushing an already-placed/clicked fighter off its spot silently
    // overrides the position the player asked for).
    this._zones = [];
    this.currentUserId = state.currentUserId ?? null;
    this.fighter = new Fighter(this);
    this.fighter.seedInitial(state);
    this.minions = new Minions(this);

    this.charges = new Map();
    // Synthesizes the live `fighter-charging` payload shape — keep in sync with FighterCharging::broadcastWith().
    for (const f of state.fighters) {
      if (f.charging) {
        this.charge.handleCharging({
          user_id: f.id,
          activity: f.charging.activity,
        });
      }
    }
    // Synthesizes the live `fighter-agent-count-changed` payload shape (no
    // seq — boot-time seeding always applies, see handleAgentCountChanged),
    // so a fighter's minion swarm survives a reload instead of the count
    // only ever coming from a live broadcast (agentCount comes from
    // Battlefield::mount()'s SubagentCountCache::many() seed).
    for (const f of state.fighters) {
      if (f.agentCount) {
        this.minions.handleAgentCountChanged({ user_id: f.id, count: f.agentCount });
      }
    }

    this._busHandlers = {
      [BusEvent.HIT]:              payload => this.fighter.handleHit(payload),
      [BusEvent.BOSS_SPAWNED]:     payload => this.spawnGate.hold(() => this.boss.handleBossSpawned(payload)),
      [BusEvent.BOSS_KILLED]:      payload => this.boss.handleBossKilled(payload),
      [BusEvent.FIGHTER_CHARGING]: payload => this.charge.handleCharging(payload),
      [BusEvent.FIGHTER_IDLED]:    payload => { this.fighter.handleIdled(payload); this.minions.despawnAll(payload?.user_id); },
      [BusEvent.FIGHTER_JOINED]:   payload => this.fighter.handleFighterJoined(payload),
      [BusEvent.FIGHTER_MOVED]:    payload => this.fighter.handleFighterMoved(payload),
      [BusEvent.POSITIONS_RESYNCED]: payload => this.fighter.reconcilePositions(payload.positions),
      [BusEvent.FIGHTER_CHARGE_CLEARED]: payload => this.charge.handleChargeCleared(payload),
      [BusEvent.CHARACTER_CHANGED]: payload => this.fighter.updateCharacters([payload], { animate: true }),
      [BusEvent.FIGHTER_AGENT_COUNT_CHANGED]: payload => this.minions.handleAgentCountChanged(payload),
      [BusEvent.FIGHTER_AGENT_TOOL_USED]: payload => this.minions.handleAgentToolUsed(payload),
    };

    this.moveInput = new MoveInput(this);
    this.moveInput.setup();
    this._unbindBus = bindBus(bus, this._busHandlers);
    this.events.once(Phaser.Scenes.Events.SHUTDOWN, () => this._teardown());
    // Registered once per Scene instance, not per create(): game.destroy(true)
    // only ever emits DESTROY once, but scene.restart() (mode change) re-runs
    // create() on this same instance, and re-registering here would leave a
    // stale DESTROY listener stacked from every earlier rotate.
    if (!this._destroyHooked) {
      this._destroyHooked = true;
      this.events.once(Phaser.Scenes.Events.DESTROY, () => this._teardown());
    }

    this.events.emit('ready');
    this.game.events.emit('ready');
  }

  /**
   * Releases bus handlers and manager state on scene shutdown or destroy.
   *
   * SceneManager.destroy() (fired by `game.destroy(true)`, e.g. the blade's
   * re-boot script) only emits DESTROY, never SHUTDOWN — relying on SHUTDOWN
   * alone left every bus handler subscribed forever past a real page
   * teardown. Both events call this, and scene.restart() reuses this same
   * instance, so it guards against running twice per create() (`create()`
   * resets `_tornDown` back to false on the next rotate).
   *
   * @return {void}
   */
  _teardown() {
    if (this._tornDown) {
      return;
    }
    this._tornDown = true;
    this.isShuttingDown = true;
    this._unbindBus?.();
    this.boss?.destroy?.();
    this.minions?.destroy?.();
    this.environment?.destroy?.();
    this.tooltip = null;
    this.hoveredUserId = null;
  }

  /**
   * Syncs world-space activity bubbles to their fighter containers every
   * frame, advances each fighter's minion trail (see minions.js), and
   * ticks the living sky (see environment/index.js).
   *
   * @param {number} time current time (scene.time.now), ms
   * @param {number} delta ms since the last frame
   * @return {void}
   */
  update(time, delta) {
    this.environment.tick(time, delta / 1000);
    for (const [userId, entry] of this.fighters.entries()) {
      if (!entry.sprite?.active) continue;
      const charging = this.charges?.get(userId);
      if (charging) {
        this.charge.emitFor(entry, delta / 1000);
      }
      if (!charging?.bubble) continue;
      charging.bubble.moveTo(entry.sprite.x, this.bubble.activityBubbleY(entry, charging.bubble.height()));
    }
    this.minions.update(time);
  }

  /**
   * Pans/zooms the camera to a fighter's current position for
   * focusPlan()'s duration, then eases back to the layout's own center
   * (restoreCenter — the only framing create() ever sets, since nothing
   * else in the scene pans/scrolls the camera). No-ops when the fighter
   * isn't on the field (see focusPlan) or a focus is already in flight
   * (a second call mid-transition would capture the first one's tweened,
   * not-yet-restored zoom as its own "previous" framing).
   *
   * @param {number} userId
   * @return {void}
   */
  focusFighter(userId) {
    // A focus already mid-transition captured the real pre-focus zoom as
    // its own prevZoom; starting a second one here would instead capture
    // the first one's in-flight (tweened, not yet restored) zoom and
    // restore to that instead of the original framing.
    if (this._focusPending) {
      return;
    }
    const plan = focusPlan(this.fighters.get(userId), this.cameras.main);
    if (!plan) {
      return;
    }
    this._focusPending = true;
    const cam = this.cameras.main;
    const prevZoom = cam.zoom;
    const restore = restoreCenter(this.layout);
    cam.pan(plan.x, plan.y, plan.duration, 'Sine.easeInOut');
    cam.zoomTo(plan.zoom, plan.duration, 'Sine.easeInOut');
    this.time.delayedCall(plan.duration + plan.hold, () => {
      this._focusPending = false;
      if (this.isShuttingDown) {
        return;
      }
      cam.pan(restore.x, restore.y, plan.duration, 'Sine.easeInOut');
      cam.zoomTo(prevZoom, plan.duration, 'Sine.easeInOut');
    });
  }

  /**
   * Adds a Phaser Text object with LINEAR-filtered resolution-doubled rendering.
   *
   * @param {number} x
   * @param {number} y
   * @param {string} content
   * @param {object} style
   * @param {number} [resolution=2]
   * @return {Phaser.GameObjects.Text}
   */
  addSharpText(x, y, content, style, resolution = 2) {
    const text = this.add.text(x, y, content, style).setOrigin(0.5).setResolution(resolution);
    text.texture.setFilter(Phaser.Textures.FilterMode.LINEAR);
    const originalSetText = text.setText.bind(text);
    text.setText = (...args) => {
      const result = originalSetText(...args);
      text.texture.setFilter(Phaser.Textures.FilterMode.LINEAR);
      return result;
    };
    return text;
  }

  /**
   * The kill ceremony's card: a band across the middle with the killer's
   * avatar and "<handle> slew <BOSS> #N", fading in, holding for `cardMs`,
   * then fading out — the DOM board already shows who did how much damage,
   * so this only needs to name the killing blow. Replaces the old Phaser
   * MVP card (`leaderboard/mvp.js`).
   *
   * @param {{killer:{id?:number|string, handle?:string, avatarUrl?:string}, bossName?:string, bossNumber?:number}} kill
   * @param {number} cardMs
   * @return {Promise<void>}
   */
  async showKillCard({ killer, bossName, bossNumber }, cardMs) {
    const cardX = this.layout.logicalWidth / 2;
    const cardY = 160;
    const cardW = 460;
    const cardH = 100;
    const label = (typeof bossName === 'string' && bossName.length > 0 ? bossName.toUpperCase() : `BOSS #${bossNumber}`);
    const handle = killer?.handle || (killer?.id != null ? `#${killer.id}` : 'Someone');

    let avatarKey = null;
    if (killer?.id != null) {
      try {
        avatarKey = await loadAvatarTexture(this, killer.id, killer.avatarUrl);
      } catch {
        avatarKey = makeFallbackAvatarTexture(this, { id: killer.id, handle });
      }
    }

    const bg = this.add.rectangle(cardX, cardY, cardW, cardH, 0x0f172a, 0.96)
      .setOrigin(0.5).setStrokeStyle(2, 0xfbbf24, 1).setDepth(200).setAlpha(0);
    const items = [bg];
    if (avatarKey) {
      const avatar = this.add.image(cardX - cardW / 2 + 40, cardY, avatarKey).setDisplaySize(56, 56).setDepth(201).setAlpha(0);
      items.push(avatar);
    }
    const text = this.addSharpText(cardX + (avatarKey ? 20 : 0), cardY, `${handle} slew ${label} #${bossNumber}`, {
      fontFamily: 'monospace', fontSize: '18px', color: '#fbbf24',
    }).setDepth(201).setAlpha(0);
    items.push(text);

    await new Promise(resolve => {
      this.tweens.add({ targets: items, alpha: 1, duration: 200, ease: 'Quad.easeOut', onComplete: resolve });
    });
    await this.wait(cardMs);
    await new Promise(resolve => {
      this.tweens.add({
        targets: items, alpha: 0, duration: 300, ease: 'Quad.easeIn',
        onComplete: () => { items.forEach(i => i.destroy()); resolve(); },
      });
    });
  }

  /**
   * The kill ceremony's countdown digit, popped over the boss anchor.
   *
   * @param {number} n
   * @param {number} stepMs
   * @return {Promise<void>}
   */
  async countdown(n, stepMs) {
    const anchor = this.layout.boss.anchor;
    const digit = this.addSharpText(anchor.x, anchor.y - 40, String(n), {
      fontFamily: 'monospace', fontSize: '64px', color: '#fde68a', stroke: '#78350f', strokeThickness: 8,
    }).setDepth(210).setScale(0.5).setAlpha(0);
    await new Promise(resolve => {
      this.tweens.add({
        targets: digit, scale: 1.15, alpha: 1, duration: 180, ease: 'Back.easeOut', onComplete: resolve,
      });
    });
    await this.wait(stepMs - 260);
    await new Promise(resolve => {
      this.tweens.add({
        targets: digit, alpha: 0, scale: 0.8, duration: 260, ease: 'Quad.easeIn',
        onComplete: () => { digit.destroy(); resolve(); },
      });
    });
  }

}
