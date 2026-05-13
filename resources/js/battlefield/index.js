import Phaser from 'phaser';
import { BattlefieldScene } from './scene.js';
import { LOGICAL_WIDTH, LOGICAL_HEIGHT, BG_COLOR } from './config.js';
import { bus } from './bus.js';

const ECHO_EVENT_MAP = {
  HitDealt: 'hit',
  BossSpawned: 'boss-spawned',
  BossKilled: 'boss-killed',
  FighterJoined: 'fighter-joined',
  FighterCharging: 'fighter-charging',
  FighterIdled: 'fighter-idled',
};

function subscribeEcho() {
  if (!window.Echo) {
    console.warn('[battlefield] window.Echo not available; events will not be received');
    return;
  }
  const channel = window.Echo.channel('battlefield');
  for (const [evt, key] of Object.entries(ECHO_EVENT_MAP)) {
    channel.listen('.' + evt, payload => bus.emit(key, payload));
  }
}

export function bootBattlefield(mount, state) {
  const game = new Phaser.Game({
    type: Phaser.AUTO,
    parent: mount,
    width: LOGICAL_WIDTH,
    height: LOGICAL_HEIGHT,
    backgroundColor: BG_COLOR,
    pixelArt: true,
    scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_BOTH },
    scene: [BattlefieldScene],
  });
  game.registry.set('initialState', state);

  const onReady = () => {
    subscribeEcho();
    const scene = game.scene.getScene('battlefield');
    window.__battlefield = {
      bus,
      game,
      scene,
      bossHp: () => scene.bossState?.currentHp,
      bossMaxHp: () => scene.bossState?.maxHp,
    };
  };
  game.events.once('ready', onReady);

  return game;
}

window.bootBattlefield = bootBattlefield;
