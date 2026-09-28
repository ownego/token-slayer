import Phaser from 'phaser';
import { BattlefieldScene } from './scene.js';
import { LAYOUTS, BG_COLOR } from './config.js';
import { bus } from './bus.js';
import { snapshotState } from './snapshot.js';
import { computeHudTop } from './hud-position.js';
import { canvasSizeFor } from './render-scale.js';
import { formatHp } from './format.js';
import { drawFighterPreview, drawFighterFrame } from './fighter/preview.js';
import { createPreviewGame, destroyPreviewGame } from './character-preview/game.js';
import { loadoutLayout, fitScale } from './character-preview/modal-fit.js';
import { thumbGeometry, scrollTopForThumb } from './character-preview/scroll-thumb.js';
import { BusEvent, SCENE_KEY, WORLD_ZOOM } from './constants.js';
import { bindBus } from './shared/bus-bindings.js';
import { keepHudOnCanvas } from './hud/sync.js';
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
 * Returns the canvas size for a layout against the mount's current width.
 *
 * @param {HTMLElement} mount
 * @param {{logicalWidth: number, logicalHeight: number}} layout
 * @return {{width: number, height: number, renderScale: number}}
 */
function canvasSizeForMount(mount, layout) {
  return canvasSizeFor(mount?.clientWidth || window.innerWidth, window.devicePixelRatio, layout);
}

function bootGame(mount, state, mode) {
  const { width, height, renderScale } = canvasSizeForMount(mount, LAYOUTS[mode]);
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
  game.registry.set('mode', mode);
  game.registry.set('renderScale', renderScale);

  game.events.once('ready', () => {
    subscribeEcho();
    const scene = game.scene.getScene(SCENE_KEY);
    window.__battlefield = {
      bus,
      bindBus,
      game,
      scene,
      get mode() { return game.registry.get('mode'); },
      // The authored coordinate space, NOT game.scale.gameSize (which is now
      // the higher-resolution canvas). Anything positioning DOM overlays
      // against the canvas -- the Damage HUD in battlefield.blade.php --
      // must scale against this, or it silently shrinks by renderScale.
      // Getters: an orientation flip swaps the layout and re-derives the
      // render scale on this same game (see applyModeChange).
      get logicalWidth() { return LAYOUTS[game.registry.get('mode')].logicalWidth; },
      get logicalHeight() { return LAYOUTS[game.registry.get('mode')].logicalHeight; },
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
        const worldWidth = LAYOUTS[game.registry.get('mode')].logicalWidth;
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
        ? { env: { setClock: minutes => scene.environment.setClock(minutes), flock: () => scene.environment.debug.flock(), shoot: () => scene.environment.debug.shoot() } }
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

  let currentMode = detectMode();
  let currentState = state;
  let currentGame = bootGame(mount, currentState, currentMode);
  let pending = null;
  let destroyed = false;

  // Once per game: applyModeChange() below restarts the scene on this same
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
    avatar: id => `<img src="/avatars/${id}" alt="" loading="lazy" onerror="this.remove()">`,
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

  const applyModeChange = (next) => {
    currentMode = next;
    const { width, height, renderScale } = canvasSizeForMount(mount, LAYOUTS[next]);
    const scene = currentGame.scene.getScene('battlefield');
    currentState = snapshotState(currentState, scene);
    currentGame.scale.setGameSize(width, height);
    currentGame.registry.set('mode', next);
    currentGame.registry.set('renderScale', renderScale);
    currentGame.registry.set('initialState', currentState);
    scene.scene.restart();
  };

  // Desktop resize: immediately refresh FIT scale so the canvas tracks the
  // new viewport size in real-time, then check for a mode flip after 300ms.
  const onResize = () => {
    if (destroyed) return;
    currentGame.scale.refresh(); // keep canvas CSS in sync immediately
    clearTimeout(pending);
    pending = setTimeout(() => {
      if (destroyed) return;
      const next = detectMode();
      if (next === currentMode) return;
      showBfLoader();
      applyModeChange(next);
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
      const next = detectMode();
      if (next === currentMode) {
        // Spurious event — restore the game, hide loader.
        hideBfLoader();
        return;
      }
      applyModeChange(next);
    }, 300);
  };

  window.addEventListener('resize', onResize);
  window.addEventListener('orientationchange', onOrientationChange);

  _cleanupResize = () => {
    destroyed = true;
    clearTimeout(pending);
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
