import Phaser from 'phaser';
import {
  BG_COLOR,
  LOGICAL_WIDTH,
  LOGICAL_HEIGHT,
  BOSS_ANCHOR,
  HP_BAR,
  BOSS_NAME,
  FIGHTER_ROW_X_RANGE,
  FIGHTER_ROW_Y,
} from './config.js';
import { computeFighterPositions } from './layout.js';
import { bus } from './bus.js';
import { spawnProjectile } from './projectile.js';

export class BattlefieldScene extends Phaser.Scene {
  constructor() {
    super('battlefield');
  }

  preload() {
    this.load.spritesheet('boss-ghost', '/assets/battlefield/bosses/ghost.png', {
      frameWidth: 32,
      frameHeight: 32,
    });
    this.load.spritesheet('fireball', '/assets/battlefield/fx/fireball.png', {
      frameWidth: 16,
      frameHeight: 16,
    });
  }

  create() {
    this.add.rectangle(LOGICAL_WIDTH / 2, LOGICAL_HEIGHT / 2, LOGICAL_WIDTH, LOGICAL_HEIGHT, BG_COLOR);

    const state = this.game.registry.get('initialState');
    this.bossState = { ...state.boss };

    this.anims.create({
      key: 'boss-idle',
      frames: this.anims.generateFrameNumbers('boss-ghost', { start: 0, end: 3 }),
      frameRate: 6,
      repeat: -1,
    });

    this.bossSprite = this.add
      .sprite(BOSS_ANCHOR.x, BOSS_ANCHOR.y, 'boss-ghost')
      .setScale(3)
      .play('boss-idle');

    this.bossNameText = this.add
      .text(BOSS_NAME.x, BOSS_NAME.y, `BOSS #${state.boss.number}`, {
        fontFamily: 'monospace',
        fontSize: '14px',
        color: '#ffffff',
      })
      .setOrigin(0.5)
      .setResolution(2);

    this.hpBarBg = this.add
      .rectangle(HP_BAR.x, HP_BAR.y, HP_BAR.width, HP_BAR.height, 0x334155)
      .setOrigin(0.5);

    this.hpBarFill = this.add
      .rectangle(
        HP_BAR.x - HP_BAR.width / 2,
        HP_BAR.y,
        HP_BAR.width * (state.boss.currentHp / state.boss.maxHp),
        HP_BAR.height,
        0xef4444
      )
      .setOrigin(0, 0.5);

    this.hpText = this.add
      .text(HP_BAR.x, HP_BAR.y + 10, `${state.boss.currentHp} / ${state.boss.maxHp}`, {
        fontFamily: 'monospace',
        fontSize: '10px',
        color: '#cbd5e1',
      })
      .setOrigin(0.5)
      .setResolution(2);

    this.fighters = new Map();
    const positions = computeFighterPositions(
      state.fighters.length,
      FIGHTER_ROW_X_RANGE,
      FIGHTER_ROW_Y,
    );
    state.fighters.forEach((f, i) => this.addFighter(f, positions[i]));

    bus.on('hit', payload => this.handleHit(payload));

    this.events.emit('ready');
    this.game.events.emit('ready');
  }

  handleHit(payload) {
    const fighter = this.fighters.get(payload.user_id);
    const fromX = fighter ? fighter.pos.x : LOGICAL_WIDTH / 2;
    const fromY = fighter ? fighter.pos.y : LOGICAL_HEIGHT / 2;
    spawnProjectile(this, fromX, fromY, () => {
      this.bossState.currentHp = payload.boss_hp_after;
    });
  }

  async loadAvatarTexture(fighter) {
    const key = `fighter-${fighter.id}`;
    if (this.textures.exists(key)) {
      return key;
    }
    await new Promise((resolve, reject) => {
      this.load.image(key, fighter.avatarUrl);
      this.load.once(`filecomplete-image-${key}`, () => resolve());
      this.load.once('loaderror', file => {
        if (file && file.key === key) {
          reject(new Error(`avatar load failed: ${fighter.avatarUrl}`));
        }
      });
      this.load.start();
    });
    return key;
  }

  async addFighter(fighter, pos) {
    let key;
    try {
      key = await this.loadAvatarTexture(fighter);
    } catch (e) {
      console.warn('[battlefield]', e.message);
      return;
    }
    const sprite = this.add.image(pos.x, pos.y, key).setDisplaySize(24, 24);
    const maskShape = this.make.graphics({ x: pos.x, y: pos.y, add: false });
    maskShape.fillCircle(0, 0, 12);
    sprite.setMask(maskShape.createGeometryMask());
    const handle = this.add
      .text(pos.x, pos.y + 16, fighter.handle, {
        fontFamily: 'monospace',
        fontSize: '8px',
        color: '#fbbf24',
      })
      .setOrigin(0.5)
      .setResolution(2);
    this.fighters.set(fighter.id, { sprite, handle, pos, maskShape });
  }
}
