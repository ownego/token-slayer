import Phaser from 'phaser';
import { BattlefieldScene } from './scene.js';
import { LAYOUTS, BG_COLOR } from './config.js';
import { bus } from './bus.js';

const ECHO_EVENT_MAP = {
  HitDealt: 'hit',
  BossSpawned: 'boss-spawned',
  BossKilled: 'boss-killed',
  FighterJoined: 'fighter-joined',
  FighterCharging: 'fighter-charging',
  FighterIdled: 'fighter-idled',
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

function snapshotState(currentState, scene) {
  if (!scene) {
    return currentState;
  }
  const next = { ...currentState };
  if (scene.bossState) {
    next.boss = {
      number: scene.bossState.number,
      name: scene.bossState.name,
      currentHp: scene.bossState.currentHp,
      maxHp: scene.bossState.maxHp,
    };
  }
  if (scene.leaderboard) {
    next.leaderboard = scene.leaderboard.getRanked().map(([userId, damage, handle]) => ({
      userId,
      damage,
      handle,
    }));
  }
  if (scene.fighters?.size > 0) {
    next.fighters = [...scene.fighters.values()].map(f => {
      const charge = scene.charges.get(f.id);
      return {
        id: f.id,
        handle: f.handleText,
        avatarUrl: f.avatarUrl,
        charging: charge ? { activity: charge.activity ?? '' } : null,
      };
    });
  }
  return next;
}

function bootGame(mount, state, mode) {
  const layout = LAYOUTS[mode];
  const game = new Phaser.Game({
    type: Phaser.AUTO,
    parent: mount,
    width: layout.logicalWidth,
    height: layout.logicalHeight,
    backgroundColor: BG_COLOR,
    pixelArt: true,
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

export function bootBattlefield(mount, state) {
  let currentMode = detectMode();
  let currentState = state;
  let currentGame = bootGame(mount, currentState, currentMode);
  let pending = null;

  const onResize = () => {
    clearTimeout(pending);
    pending = setTimeout(() => {
      const next = detectMode();
      if (next === currentMode) {
        return;
      }
      currentMode = next;
      const oldScene = currentGame.scene.getScene('battlefield');
      currentState = snapshotState(currentState, oldScene);
      currentGame.destroy(true);
      currentGame = bootGame(mount, currentState, currentMode);
    }, 200);
  };

  window.addEventListener('resize', onResize);
  window.addEventListener('orientationchange', onResize);

  return currentGame;
}

window.bootBattlefield = bootBattlefield;
