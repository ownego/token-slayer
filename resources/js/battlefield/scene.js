import Phaser from 'phaser';
import {
  BG_COLOR,
  LOGICAL_WIDTH,
  LOGICAL_HEIGHT,
  BOSS_ANCHOR,
  BOSS_SCALE,
  HP_BAR,
  BOSS_NAME,
  FIGHTER_ROW_X_RANGE,
  FIGHTER_ROW_Y,
  TIMINGS,
} from './config.js';
import { computeFighterPositions } from './layout.js';
import { bus } from './bus.js';
import { spawnProjectile } from './projectile.js';
import { applyImpact } from './impact.js';

export class BattlefieldScene extends Phaser.Scene {
  constructor() {
    super('battlefield');
  }

  preload() {
    this.load.spritesheet('boss-ghost', '/assets/battlefield/bosses/ghost.png', {
      frameWidth: 32,
      frameHeight: 32,
    });
    this.load.spritesheet('boss-skeleton', '/assets/battlefield/bosses/skeleton.png', {
      frameWidth: 32,
      frameHeight: 32,
    });
    this.load.spritesheet('boss-slime', '/assets/battlefield/bosses/slime.png', {
      frameWidth: 32,
      frameHeight: 32,
    });
    this.load.spritesheet('fireball', '/assets/battlefield/fx/fireball.png', {
      frameWidth: 16,
      frameHeight: 16,
    });
    this.load.spritesheet('explosion', '/assets/battlefield/fx/explosion.png', {
      frameWidth: 32,
      frameHeight: 32,
    });
  }

  bossTextureFor(number) {
    const keys = ['boss-ghost', 'boss-skeleton', 'boss-slime'];
    return keys[number % keys.length];
  }

  ensureBossIdleAnim(textureKey) {
    const animKey = `${textureKey}-idle`;
    if (!this.anims.exists(animKey)) {
      this.anims.create({
        key: animKey,
        frames: this.anims.generateFrameNumbers(textureKey, { start: 0, end: 3 }),
        frameRate: 6,
        repeat: -1,
      });
    }
    return animKey;
  }

  create() {
    this.add.rectangle(LOGICAL_WIDTH / 2, LOGICAL_HEIGHT / 2, LOGICAL_WIDTH, LOGICAL_HEIGHT, BG_COLOR);

    this.makeChargeRingTexture();

    const state = this.game.registry.get('initialState');
    this.bossState = { ...state.boss };

    const initialKey = this.bossTextureFor(state.boss.number);
    const initialAnim = this.ensureBossIdleAnim(initialKey);
    this.bossSprite = this.add
      .sprite(BOSS_ANCHOR.x, BOSS_ANCHOR.y, initialKey)
      .setScale(BOSS_SCALE)
      .play(initialAnim);

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
    bus.on('boss-spawned', payload => this.handleBossSpawned(payload));
    bus.on('boss-killed', () => this.handleBossKilled());

    this.charges = new Map();
    bus.on('fighter-charging', payload => this.handleCharging(payload));
    bus.on('fighter-idled', payload => this.handleIdled(payload));
    bus.on('fighter-joined', payload => this.handleFighterJoined(payload));

    this.events.emit('ready');
    this.game.events.emit('ready');
  }

  makeChargeRingTexture() {
    if (this.textures.exists('charge-ring')) {
      return;
    }
    const g = this.make.graphics({ x: 0, y: 0, add: false });
    g.lineStyle(2, 0x22d3ee, 1);
    g.strokeCircle(12, 12, 10);
    g.generateTexture('charge-ring', 24, 24);
    g.destroy();
  }

  handleHit(payload) {
    this.clearCharge(payload.user_id);
    const fighter = this.fighters.get(payload.user_id);
    const fromX = fighter ? fighter.pos.x : LOGICAL_WIDTH / 2;
    const fromY = fighter ? fighter.pos.y : LOGICAL_HEIGHT / 2;
    spawnProjectile(this, fromX, fromY, () => applyImpact(this, payload.boss_hp_after));
  }

  handleBossSpawned(payload) {
    const oldSprite = this.bossSprite;
    this.tweens.add({
      targets: oldSprite,
      alpha: 0,
      duration: 200,
      onComplete: () => oldSprite.destroy(),
    });

    const textureKey = this.bossTextureFor(payload.boss_number);
    const animKey = this.ensureBossIdleAnim(textureKey);
    this.bossSprite = this.add
      .sprite(BOSS_ANCHOR.x, -40, textureKey)
      .setScale(BOSS_SCALE)
      .play(animKey);
    this.tweens.add({
      targets: this.bossSprite,
      y: BOSS_ANCHOR.y,
      duration: TIMINGS.bossSpawnMs,
      ease: 'Bounce.easeOut',
    });

    this.bossState = {
      currentHp: payload.max_hp,
      maxHp: payload.max_hp,
      number: payload.boss_number,
    };
    this.bossNameText.setText(`BOSS #${payload.boss_number}`);
    this.hpBarFill.width = HP_BAR.width;
    this.hpText.setText(`${payload.max_hp} / ${payload.max_hp}`);
  }

  handleBossKilled() {
    if (!this.bossSprite) {
      return;
    }
    this.tweens.add({
      targets: this.bossSprite,
      scale: 0,
      alpha: 0,
      angle: 360,
      duration: TIMINGS.bossKilledMs,
      ease: 'Quad.easeIn',
    });
    this.cameras.main.flash(400, 255, 255, 255);
  }

  handleCharging(payload) {
    const fighter = this.fighters.get(payload.user_id);
    if (!fighter) {
      return;
    }
    if (this.charges.has(payload.user_id)) {
      return;
    }
    const ring = this.add
      .image(fighter.pos.x, fighter.pos.y, 'charge-ring')
      .setBlendMode(Phaser.BlendModes.ADD)
      .setAlpha(0.4);
    const pulse = this.tweens.add({
      targets: ring,
      alpha: 0.9,
      duration: TIMINGS.chargeRingPulseMs / 2,
      yoyo: true,
      repeat: -1,
      ease: 'Sine.easeInOut',
    });
    const breath = this.tweens.add({
      targets: fighter.sprite,
      scaleY: fighter.sprite.scaleY * 1.05,
      duration: TIMINGS.chargeRingPulseMs / 2,
      yoyo: true,
      repeat: -1,
      ease: 'Sine.easeInOut',
    });
    this.charges.set(payload.user_id, { ring, pulse, breath });
  }

  handleIdled(payload) {
    this.clearCharge(payload.user_id);
  }

  clearCharge(userId) {
    const entry = this.charges.get(userId);
    if (!entry) {
      return;
    }
    entry.pulse.stop();
    entry.breath.stop();
    this.tweens.add({
      targets: entry.ring,
      alpha: 0,
      duration: 200,
      onComplete: () => entry.ring.destroy(),
    });
    this.charges.delete(userId);
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
    this.textures.get(key).setFilter(Phaser.Textures.FilterMode.LINEAR);
    return key;
  }

  async handleFighterJoined(payload) {
    if (this.fighters.has(payload.user_id)) {
      return;
    }
    const fighter = {
      id: payload.user_id,
      handle: payload.slack_handle,
      avatarUrl: payload.avatar_url,
    };

    // Recompute positions for the new count.
    const count = this.fighters.size + 1;
    const positions = computeFighterPositions(count, FIGHTER_ROW_X_RANGE, FIGHTER_ROW_Y);

    // Tween existing fighters to their new slots.
    let i = 0;
    for (const entry of this.fighters.values()) {
      const target = positions[i++];
      this.tweens.add({
        targets: [entry.sprite, entry.handle],
        x: target.x,
        duration: 200,
        ease: 'Quad.easeOut',
      });
      entry.pos = target;
    }

    const newPos = positions[positions.length - 1];
    await this.addFighter(fighter, newPos);
    const entry = this.fighters.get(fighter.id);
    if (!entry) {
      return; // load failed; addFighter already warned
    }
    const finalScale = entry.sprite.scaleX;
    entry.sprite.setScale(0);
    this.tweens.add({
      targets: entry.sprite,
      scale: finalScale,
      duration: TIMINGS.fighterJoinMs,
      ease: 'Back.easeOut',
    });
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
