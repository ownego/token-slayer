import Phaser from 'phaser';
import { BattlefieldScene } from './scene.js';
import { BG_COLOR, layoutFor, needsRelayout } from './config.js';
import { bus } from './bus.js';
import { snapshotState } from './snapshot.js';
import { computeHudTop } from './hud-position.js';
import { canvasSizeFor } from './render-scale.js';
import { detectLite } from './render-mode.js';
import { formatHp } from './format.js';
import { drawFighterPreview, drawFighterFrame } from './fighter/preview.js';
import { avatarSrc } from './fighter/avatar-stack.js';
import { createPreviewGame, destroyPreviewGame } from './character-preview/game.js';
import { loadoutLayout, fitScale } from './character-preview/modal-fit.js';
import { thumbGeometry, scrollTopForThumb } from './character-preview/scroll-thumb.js';
import { BusEvent, SCENE_KEY, WORLD_ZOOM } from './constants.js';
import { bindBus } from './shared/bus-bindings.js';
import { keepHudOnCanvas, measureHudBand } from './hud/sync.js';
import { createBossPlate } from './hud/boss-plate.js';
import { createFeedView } from './hud/feed-view.js';
import { domToWorld } from './shared/hud-zones.js';
import { boardHitHandler, createBoardRenderer, createBoardView } from './hud/board-view.js';
import { onSceneReady } from './shared/scene-ready.js';
import { revealHud } from './hud/reveal.js';

// A small fixed palette for the TOP DAMAGE board's avatar swatch, shown
// behind the real /avatars/{id} image (and left visible if that 404s).
const BOARD_AVATAR_COLORS = ['#f97316', '#0ea5e9', '#22c55e', '#a855f7', '#ec4899', '#eab308'];

const ECHO_EVENT_MAP = {
  HitDealt:        BusEvent.HIT,
  BossSpawned:     BusEvent.BOSS_SPAWNED,
  BossKilled:      BusEvent.BOSS_KILLED,
  FighterJoined:   BusEvent.FIGHTER_JOINED,
  FighterCharging: BusEvent.FIGHTER_CHARGING,
  FighterIdled:    BusEvent.FIGHTER_IDLED,
  FighterMoved:    BusEvent.FIGHTER_MOVED,
  FighterChargeCleared: BusEvent.FIGHTER_CHARGE_CLEARED,
  FighterCharacterChanged: BusEvent.CHARACTER_CHANGED,
  FighterAgentCountChanged: BusEvent.FIGHTER_AGENT_COUNT_CHANGED,
  FighterAgentToolUsed: BusEvent.FIGHTER_AGENT_TOOL_USED,
};

const ECHO_RETRY_INTERVAL_MS = 200;
const ECHO_RETRY_TIMEOUT_MS = 10_000;

let echoChannel = null;
let echoRetryInterval = null;
let resyncBound = false;

function attachEchoListeners() {
  if (echoChannel) {
    for (const evt of Object.keys(ECHO_EVENT_MAP)) {
      echoChannel.stopListening('.' + evt);
    }
  }
  echoChannel = window.Echo.channel('battlefield');
  for (const [evt, key] of Object.entries(ECHO_EVENT_MAP)) {
    echoChannel.listen('.' + evt, payload => bus.emit(key, payload));
  }
  bindResyncOnReconnect();
}

/**
 * Wires a one-time listener so that whenever the Echo/Reverb connection
 * (re)establishes, the Battlefield Livewire component is asked for the
 * current authoritative fighter positions. Reverb broadcasts have no replay,
 * so a `FighterMoved` that fired while this tab's WebSocket was disconnected
 * is otherwise lost forever for this one client; requesting a resync repairs
 * whatever drifted instead of leaving it wrong until the next full reload.
 *
 * @return {void}
 */
function bindResyncOnReconnect() {
  if (resyncBound || !window.Echo?.connector?.pusher?.connection) {
    return;
  }
  resyncBound = true;

  window.Livewire.on('battlefield-resynced', ({ positions }) => {
    bus.emit(BusEvent.POSITIONS_RESYNCED, { positions });
  });

  window.Echo.connector.pusher.connection.bind('connected', () => {
    window.Livewire.dispatch('request-resync');
  });
}

function subscribeEcho() {
  if (window.Echo) {
    attachEchoListeners();
    return;
  }
  if (echoRetryInterval) {
    return;
  }
  const start = Date.now();
  echoRetryInterval = setInterval(() => {
    if (window.Echo) {
      clearInterval(echoRetryInterval);
      echoRetryInterval = null;
      attachEchoListeners();
    } else if (Date.now() - start > ECHO_RETRY_TIMEOUT_MS) {
      clearInterval(echoRetryInterval);
      echoRetryInterval = null;
      console.warn('[battlefield] window.Echo not available after retries; events will not be received');
    }
  }, ECHO_RETRY_INTERVAL_MS);
}

/**
 * Returns 'portrait' or 'landscape' based on current viewport dimensions.
 *
 * @return {string}
 */
export function detectMode() {
  return window.innerWidth < window.innerHeight ? 'portrait' : 'landscape';
}

/**
 * Top-band HUD panels the boss must stay below, per mode: in portrait the
 * nav column and the boss plate stacked over the top of the canvas, in
 * landscape only the plate (the side columns never cover the boss).
 *
 * @type {{portrait: string, landscape: string}}
 */
const HUD_BAND_PANELS = { portrait: '.bf-nav, .bf-plate', landscape: '.bf-plate' };

/**
 * Returns the layout for the mount's current box: the world takes the
 * screen's own aspect (config/layouts.js's layoutFor), and the boss is placed
 * below the HUD panels actually drawn over the top of the canvas.
 *
 * @param {HTMLElement} mount
 * @return {object}
 */
function screenLayout(mount) {
  const width = mount?.clientWidth || window.innerWidth;
  const height = mount?.clientHeight || window.innerHeight;
  const bare = layoutFor({ width, height });
  // Scale.FIT: the canvas fills the mount unless the world was clamped
  const scale = Math.min(width / bare.logicalWidth, height / bare.logicalHeight);
  const box = { width: bare.logicalWidth * scale, height: bare.logicalHeight * scale };
  const hudBand = measureHudBand(document.getElementById('bf-hud'), box, bare.logicalWidth, HUD_BAND_PANELS[bare.mode]);

  return layoutFor({ width, height, hudBand });
}

/**
 * Returns the canvas size for a layout against the mount's current width.
 *
 * @param {HTMLElement} mount
 * @param {{logicalWidth: number, logicalHeight: number}} layout
 * @return {{width: number, height: number, renderScale: number}}
 */
function canvasSizeForMount(mount, layout) {
  return canvasSizeFor(mount?.clientWidth || window.innerWidth, window.devicePixelRatio, layout, { maxScale: isLite() ? 1 : 2.5 });
}

/**
 * Whether this page runs the battlefield in lite mode (render-mode.js),
 * probed once per page.
 *
 * @type {?boolean}
 */
let liteMode = null;

/**
 * Lite mode for this page, probing the browser the first time it's asked.
 *
 * @return {boolean}
 */
function isLite() {
  if (liteMode === null) {
    liteMode = detectLite();
  }

  return liteMode;
}

function bootGame(mount, state, layout) {
  const { width, height, renderScale } = canvasSizeForMount(mount, layout);
  const game = new Phaser.Game({
    type: Phaser.AUTO,
    parent: mount,
    width,
    height,
    backgroundColor: BG_COLOR,
    pixelArt: false,
    antialias: true,
    scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_BOTH },
    scene: [BattlefieldScene],
  });
  game.registry.set('initialState', state);
  game.registry.set('mode', layout.mode);
  game.registry.set('layout', layout);
  game.registry.set('renderScale', renderScale);
  game.registry.set('lite', isLite());
  // lite renders at the layout's own size: the browser scales it up, crisp
  mount?.classList.toggle('bf-lite', isLite());

  game.events.once('ready', () => {
    subscribeEcho();
    const scene = game.scene.getScene(SCENE_KEY);
    window.__battlefield = {
      bus,
      lite: isLite(),
      bindBus,
      game,
      scene,
      get mode() { return game.registry.get('mode'); },
      // The authored coordinate space, NOT game.scale.gameSize (which is now
      // the higher-resolution canvas). Anything positioning DOM overlays
      // against the canvas -- the Damage HUD in battlefield.blade.php --
      // must scale against this, or it silently shrinks by renderScale.
      // Getters: a relayout (rotation, or a real aspect change) swaps the
      // layout and re-derives the render scale on this same game (see
      // applyLayout).
      get logicalWidth() { return game.registry.get('layout').logicalWidth; },
      get logicalHeight() { return game.registry.get('layout').logicalHeight; },
      get renderScale() { return game.registry.get('renderScale'); },
      // See constants.js's own docblock — the DOM Damage HUD (battlefield.
      // blade.php's fitToCanvas()) needs this to keep mirroring the
      // in-canvas TOP DAMAGE panel now that the camera shows more of the
      // world than the canvas's own pixel dimensions alone would suggest.
      worldZoom: WORLD_ZOOM,
      bossHp: () => scene.bossState?.currentHp,
      bossMaxHp: () => scene.bossState?.maxHp,
      computeHudTop,
      formatHp,
      drawFighterPreview,
      drawFighterFrame,
      createCharacterPreview: createPreviewGame,
      loadoutLayout,
      fitScale,
      thumbGeometry,
      scrollTopForThumb,
      destroyCharacterPreview: destroyPreviewGame,
      // Called by battlefieldHud (hud/index.js) every 500ms and after the
      // portrait board sheet's .open toggle, with getBoundingClientRect()
      // rects for .bf-team/.bf-plate/.bf-board/.bf-herald (the feed is
      // excluded — its lines are pointer-events:none and never obstruct a
      // click). Converts to world space and hands them to the scene, which
      // move-geometry.js's isValidMoveTarget/planRoute read so click-to-move
      // rejects a target under a HUD panel; an already-standing fighter is
      // never auto-repositioned when a zone appears over it.
      setHudZones(rects) {
        const canvasRect = game.canvas.getBoundingClientRect();
        const worldWidth = game.registry.get('layout').logicalWidth;
        scene._zones = rects.map(r => domToWorld(r, canvasRect, worldWidth));
      },
      // The fighter sheet's equip transition: pans/zooms the camera to the
      // viewer's own fighter, then eases back. No-ops when the fighter
      // isn't currently on the field (see camera-focus.js's focusPlan).
      focusFighter: userId => scene.focusFighter(userId),
      // Staging-only manual clock override for verifying the living sky at
      // dawn/dusk/night without waiting for the real time of day — never
      // exposed without the explicit ?sky-debug=1 query param.
      ...(new URLSearchParams(window.location.search).get('sky-debug') === '1'
        ? { env: { setClock: minutes => scene.environment.setClock(minutes), flock: () => scene.environment.debug.flock(), shoot: () => scene.environment.debug.shoot(), clawd: kind => scene.environment.debug.clawd(kind) } }
        : {}),
    };
  });

  return game;
}

// Module-level cleanup — removes the previous bootBattlefield's resize listeners.
let _cleanupResize = null;

/**
 * Boots the Phaser battlefield game and wires up resize/orientation listeners.
 *
 * @param {HTMLElement} mount
 * @param {object} state
 * @return {Phaser.Game}
 */
export function bootBattlefield(mount, state) {
  _cleanupResize?.();
  _cleanupResize = null;

  let currentLayout = screenLayout(mount);
  let currentState = state;
  let currentGame = bootGame(mount, currentState, currentLayout);
  let pending = null;
  let destroyed = false;

  // Once per game: applyLayout() below restarts the scene on this same
  // game rather than rebooting a new one, and keepHudOnCanvas's own listener
  // on game.scale already re-syncs across that restart — a second call here
  // per rotation would stack listeners instead of replacing one.
  const hudEl = document.getElementById('bf-hud');
  const unsubscribeHud = hudEl ? keepHudOnCanvas(currentGame, mount, hudEl) : null;

  // Boss plate: created once per game, seeded from the boot payload's boss
  // (currentHp may be below max — a reload mid-fight — so it renders that
  // reading directly, no crack cascade), then kept in sync by BOSS_HP_TICK
  // (impact.js's counter render) and BOSS_SPAWNED; hit() only reads its
  // damage to decide whether to shake.
  const plateEl = document.querySelector('.bf-plate');
  const bossPlate = plateEl ? createBossPlate(plateEl) : null;
  if (bossPlate && currentState.boss) {
    bossPlate.spawn(currentState.boss.name, currentState.boss.number, currentState.boss.maxHp, currentState.boss.currentHp);
  }
  // Boss-spawned visuals (plate refill, board reset) are gated the same
  // way ceremony.js gates the boss swap itself — EventController dispatches
  // BossKilled and BossSpawned in the same request, so without this the
  // plate/board would jump to the new boss while the kill ceremony is still
  // showing the old one's death.
  const holdForCeremony = fn => {
    const scene = currentGame.scene.getScene(SCENE_KEY);
    if (scene?.spawnGate) {
      scene.spawnGate.hold(fn);
    } else {
      fn();
    }
  };
  const unbindPlate = bossPlate
    ? bindBus(bus, {
        [BusEvent.BOSS_SPAWNED]: p => holdForCeremony(() => bossPlate.spawn(p.boss_name, p.boss_number, p.max_hp)),
        [BusEvent.BOSS_HP_TICK]: p => bossPlate.set(p.hp, p.max),
        [BusEvent.HIT]: p => bossPlate.hit(Number(p?.damage) || 0),
      })
    : null;

  // TOP DAMAGE board: rankBoard over scene.damageTotals (the single
  // per-boss source, now that the Phaser leaderboard is gone) drives the
  // keyed DOM view on every hit and on a fresh spawn.
  const boardEl = document.querySelector('.bf-board');
  const boardView = boardEl ? createBoardView(boardEl, {
    avatar: id => `<img src="${avatarSrc(currentGame.scene.getScene(SCENE_KEY)?.fighters?.get(id) ?? { id })}" alt="" loading="lazy" onerror="this.remove()">`,
    color: id => BOARD_AVATAR_COLORS[Math.abs(Number(id) || 0) % BOARD_AVATAR_COLORS.length],
    name: id => {
      const scene = currentGame.scene.getScene(SCENE_KEY);
      return scene?.fighters?.get(id)?.handleText || `#${id}`;
    },
    isYou: id => Number(id) === Number(currentState.currentUserId),
    handles: currentState.leaderboard ?? [],
  }) : null;
  const renderBoard = boardView
    ? createBoardRenderer(() => currentGame.scene.getScene(SCENE_KEY), boardView)
    : null;
  // the scene is created after this returns (Phaser preloads first): render
  // once it exists, and after every rotate-restart, not just now
  const stopBoardSeed = boardView ? onSceneReady(currentGame, SCENE_KEY, renderBoard) : () => {};

  // The HUD waits for the field: hidden until the scene first exists, then
  // it slides in panel by panel while the boss's HP bar fills from empty and
  // the team's numbers count up.
  let introduced = false;
  const stopIntro = onSceneReady(currentGame, SCENE_KEY, () => {
    if (introduced || !hudEl) {
      return;
    }
    introduced = true;
    revealHud(hudEl);
    bossPlate?.intro();
    hudEl.dispatchEvent(new CustomEvent('bf-hud-intro'));
  });
  const unbindBoard = boardView
    ? bindBus(bus, {
        [BusEvent.HIT]: boardHitHandler(boardView, renderBoard),
        [BusEvent.BOSS_SPAWNED]: () => holdForCeremony(() => { renderBoard.reset(); renderBoard(); }),
      })
    : null;

  // Activity feed: "join" and "subagent" lines only — kills/spawns go to the
  // herald instead (wired in hud/index.js's battlefieldHud, which owns the
  // reactive `herald` state the blade binds to).
  const feedEl = document.querySelector('.bf-feed');
  const feedView = feedEl ? createFeedView(feedEl) : null;
  const lastAgentCount = new Map(); // user_id -> last known subagent count
  const unbindFeed = feedView
    ? bindBus(bus, {
        [BusEvent.FIGHTER_JOINED]: p => feedView.push('join', p.slack_handle ?? p.display_name ?? 'Someone'),
        [BusEvent.FIGHTER_AGENT_COUNT_CHANGED]: p => {
          const prev = lastAgentCount.get(p.user_id) ?? 0;
          lastAgentCount.set(p.user_id, p.count);
          if (p.count > prev) {
            const scene = currentGame.scene.getScene(SCENE_KEY);
            feedView.push('subagent', scene?.fighters?.get(p.user_id)?.handleText ?? 'Someone');
          }
        },
      })
    : null;

  const applyLayout = (next) => {
    currentLayout = next;
    const { width, height, renderScale } = canvasSizeForMount(mount, next);
    const scene = currentGame.scene.getScene('battlefield');
    currentState = snapshotState(currentState, scene);
    currentGame.scale.setGameSize(width, height);
    currentGame.registry.set('mode', next.mode);
    currentGame.registry.set('layout', next);
    currentGame.registry.set('renderScale', renderScale);
    currentGame.registry.set('initialState', currentState);
    scene.scene.restart();
  };

  // Resize: immediately refresh FIT scale so the canvas tracks the new
  // viewport size in real-time, then after 300ms restart on a new layout only
  // when the screen moved far enough from the current one (needsRelayout) —
  // a phone's URL bar showing/hiding never reboots the scene.
  const onResize = () => {
    if (destroyed) return;
    currentGame.scale.refresh(); // keep canvas CSS in sync immediately
    clearTimeout(pending);
    pending = setTimeout(() => {
      if (destroyed) return;
      const next = screenLayout(mount);
      if (!needsRelayout(currentLayout, next)) return;
      showBfLoader();
      applyLayout(next);
    }, 300);
  };

  // Orientation change: immediately cover the screen so the 300 ms where
  // Phaser auto-rescales to the wrong aspect ratio is hidden behind the loader.
  const onOrientationChange = () => {
    if (destroyed) return;
    showBfLoader();
    clearTimeout(pending);
    pending = setTimeout(() => {
      if (destroyed) return;
      const next = screenLayout(mount);
      if (!needsRelayout(currentLayout, next)) {
        // Spurious event — restore the game, hide loader.
        hideBfLoader();
        return;
      }
      applyLayout(next);
    }, 300);
  };

  // The boss sits under the HUD band measured at boot, but the panels keep
  // settling after it — webfonts, a boss script's extra plate row
  // (ThaNode's stones), a longer boss name on the next spawn. Whenever they
  // grow into the boss (or shrink well clear of it), relayout.
  let bandTimer = null;
  const bandObserver = hudEl && typeof ResizeObserver !== 'undefined'
    ? new ResizeObserver(() => {
        clearTimeout(bandTimer);
        // a boss spawn grows the plate (a longer name, ThaNode's stones)
        // mid kill ceremony: relayout only once the ceremony has played out
        bandTimer = setTimeout(() => holdForCeremony(() => {
          if (destroyed) return;
          const next = screenLayout(mount);
          if (!needsRelayout(currentLayout, next)) return;
          showBfLoader();
          applyLayout(next);
        }), 300);
      })
    : null;
  hudEl?.querySelectorAll('.bf-nav, .bf-plate').forEach(el => bandObserver?.observe(el));

  window.addEventListener('resize', onResize);
  window.addEventListener('orientationchange', onOrientationChange);

  _cleanupResize = () => {
    destroyed = true;
    clearTimeout(pending);
    clearTimeout(bandTimer);
    bandObserver?.disconnect();
    window.removeEventListener('resize', onResize);
    window.removeEventListener('orientationchange', onOrientationChange);
  };

  currentGame.events.once('destroy', () => {
    _cleanupResize?.();
    unsubscribeHud?.();
    unbindPlate?.();
    unbindBoard?.();
    stopBoardSeed();
    stopIntro();
    boardView?.destroy();
    unbindFeed?.();
    feedView?.destroy();
  });

  return currentGame;
}

function showBfLoader() {
  const el = document.getElementById('bf-loader');
  if (el) el.style.display = 'flex';
}

function hideBfLoader() {
  const el = document.getElementById('bf-loader');
  if (el) el.style.display = 'none';
}

window.bootBattlefield = bootBattlefield;
