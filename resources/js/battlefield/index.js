import Phaser from 'phaser';
import { BattlefieldScene } from './scene.js';
import { LAYOUTS, BG_COLOR } from './config.js';
import { bus } from './bus.js';
import { snapshotState } from './snapshot.js';

const ECHO_EVENT_MAP = {
  HitDealt: 'hit',
  BossSpawned: 'boss-spawned',
  BossKilled: 'boss-killed',
  FighterJoined: 'fighter-joined',
  FighterCharging: 'fighter-charging',
  FighterIdled: 'fighter-idled',
  FighterMoved: 'fighter-moved',
};

const ECHO_RETRY_INTERVAL_MS = 200;
const ECHO_RETRY_TIMEOUT_MS = 10_000;

let echoChannel = null;
let echoRetryInterval = null;

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

export function detectMode() {
  return window.innerWidth < window.innerHeight ? 'portrait' : 'landscape';
}

function bootGame(mount, state, mode) {
  const layout = LAYOUTS[mode];
  const game = new Phaser.Game({
    type: Phaser.AUTO,
    parent: mount,
    width: layout.logicalWidth,
    height: layout.logicalHeight,
    backgroundColor: BG_COLOR,
    pixelArt: false,
    antialias: true,
    scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_BOTH },
    scene: [BattlefieldScene],
  });
  game.registry.set('initialState', state);
  game.registry.set('mode', mode);

  game.events.once('ready', () => {
    subscribeEcho();
    const scene = game.scene.getScene('battlefield');
    window.__battlefield = {
      bus,
      game,
      scene,
      mode,
      bossHp: () => scene.bossState?.currentHp,
      bossMaxHp: () => scene.bossState?.maxHp,
    };
  });

  return game;
}

// Module-level cleanup — removes the previous bootBattlefield's resize listeners.
let _cleanupResize = null;

export function bootBattlefield(mount, state) {
  _cleanupResize?.();
  _cleanupResize = null;

  let currentMode = detectMode();
  let currentState = state;
  let currentGame = bootGame(mount, currentState, currentMode);
  let pending = null;
  let destroyed = false;

  const applyModeChange = (next) => {
    currentMode = next;
    const layout = LAYOUTS[next];
    const scene = currentGame.scene.getScene('battlefield');
    currentState = snapshotState(currentState, scene);
    currentGame.scale.resize(layout.logicalWidth, layout.logicalHeight);
    currentGame.scale.refresh();
    currentGame.registry.set('mode', next);
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

  currentGame.events.once('destroy', () => { _cleanupResize?.(); });

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
