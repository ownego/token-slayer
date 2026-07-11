import Phaser from 'phaser';
import { BOSS_TYPES, TIMINGS } from '@battlefield/config.js';
import { BossPhase, DreadknightAttack } from '@battlefield/constants.js';
import { formatHp } from '@battlefield/format.js';
import { Leaderboard } from '@battlefield/leaderboard.js';
import { applyStunEffect } from './stun.js';
import { isDreadknight, startDreadknightPatrol } from './dreadknight.js';

/** Manages boss patrol cycle, attacks, HP bar updates, and spawn/kill events. */
export class Boss {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
    this.bossLastAttackAt = 0;
    this.bossPatrolPhase = BossPhase.MOVE;
    this.bossIdleRepeatListener = null;
  }

  /**
   * Returns the boss type config for the given boss number.
   *
   * @param {number} number
   * @return {object}
   */
  static bossTypeFor(number) {
    return BOSS_TYPES[number % BOSS_TYPES.length];
  }

  /**
   * Returns the color integer for the HP bar at the given fill ratio.
   *
   * @param {number} current
   * @param {number} max
   * @return {number}
   */
  static hpBarColor(current, max) {
    const pct = current / max;
    if (pct > 0.5) return 0x22c55e;
    if (pct > 0.25) return 0xf59e0b;
    return 0xef4444;
  }

  /**
   * Returns the display label for a boss.
   *
   * @param {{ name?: string, number?: number }|null} boss
   * @return {string}
   */
  static bossLabel(boss) {
    const name = boss?.name;
    if (typeof name === 'string' && name.length > 0) {
      return name.toUpperCase();
    }
    return `BOSS #${boss?.number ?? '?'}`;
  }

  /**
   * Creates all boss visuals on the scene using initial state.
   *
   * @param {{ boss: { number: number, name?: string, currentHp: number, maxHp: number } }} state
   * @return {void}
   */
  create(state) {
    const L = this.scene.layout;
    this.scene.bossState = { ...state.boss };

    const initialType = Boss.bossTypeFor(state.boss.number);
    const initialKey = initialType.key;
    const initialTexKey = initialType.animFiles ? `${initialKey}-idle` : initialKey;
    const initialAnim = this.ensureBossIdleAnim(initialKey);
    this.scene.bossSprite = this.scene.add
      .sprite(L.boss.anchor.x, L.boss.anchor.y, initialTexKey)
      .setScale(initialType.scale)
      .setDepth(5)
      .setData('bossTypeKey', initialKey)
      .play(initialAnim);
    if (initialType.pixelate) {
      this.scene.bossSprite.preFX?.addPixelate(initialType.pixelate);
    }
    this.startBossPatrol();

    this.scene.bossNameText = this.scene.addSharpText(L.boss.name.x, L.boss.name.y, Boss.bossLabel(state.boss), {
      fontFamily: 'monospace',
      fontSize: '28px',
      color: '#ffffff',
      stroke: '#0f172a',
      strokeThickness: 8,
    }).setDepth(5);

    this.scene.hpBarBg = this.scene.add
      .rectangle(L.hpBar.x, L.hpBar.y, L.hpBar.width, L.hpBar.height, 0x334155)
      .setOrigin(0.5);

    this.scene.hpBarFill = this.scene.add
      .rectangle(
        L.hpBar.x - L.hpBar.width / 2,
        L.hpBar.y,
        Math.round(L.hpBar.width * (state.boss.currentHp / state.boss.maxHp)),
        L.hpBar.height,
        Boss.hpBarColor(state.boss.currentHp, state.boss.maxHp)
      )
      .setOrigin(0, 0.5);

    this.scene.hpBarBorder = this.scene.add
      .rectangle(L.hpBar.x, L.hpBar.y, L.hpBar.width, L.hpBar.height)
      .setOrigin(0.5)
      .setFillStyle()
      .setStrokeStyle(1, 0x94a3b8, 1);

    this.scene.hpText = this.scene.addSharpText(L.hpBar.x, L.hpBar.y + 24, `${formatHp(state.boss.currentHp)} / ${formatHp(state.boss.maxHp)}`, {
      fontFamily: 'monospace',
      fontSize: '22px',
      color: '#ffffff',
      stroke: '#0f172a',
      strokeThickness: 6,
    }, 3);
  }

  /**
   * Returns the texture key for the given boss number.
   *
   * @param {number} number
   * @return {string}
   */
  bossTextureFor(number) {
    return Boss.bossTypeFor(number).key;
  }

  /**
   * Ensures all animations for the given boss texture key are registered,
   * then returns the idle animation key.
   *
   * @param {string} textureKey
   * @return {string}
   */
  ensureBossIdleAnim(textureKey) {
    const bossType = BOSS_TYPES.find(b => b.key === textureKey);
    const idleKey = `${textureKey}-idle`;

    if (bossType?.animFiles) {
      for (const [anim, info] of Object.entries(bossType.animFiles)) {
        const animKey = `${textureKey}-${anim}`;
        const texKey = `${textureKey}-${anim}`;
        if (!this.scene.anims.exists(animKey)) {
          this.scene.anims.create({
            key: animKey,
            frames: this.scene.anims.generateFrameNumbers(texKey, { start: 0, end: info.count - 1 }),
            frameRate: info.rate ?? 8,
            repeat: info.loop ? -1 : 0,
            yoyo: info.yoyo ?? false,
          });
        }
        // For slam, create a slam-from-jump variant that skips the windup frames.
        if (anim === DreadknightAttack.SLAM) {
          const slamKey = `${textureKey}-slam-impact`;
          if (!this.scene.anims.exists(slamKey)) {
            this.scene.anims.create({
              key: slamKey,
              frames: this.scene.anims.generateFrameNumbers(texKey, { frames: [2, 3, 4, 5] }),
              frameRate: info.rate ?? 8,
              repeat: 0,
            });
          }
        }
      }
      // Register fall (getup reversed, skipping death/ghost frames) if the boss has a getup animation.
      const getupInfo = bossType.animFiles.getup;
      if (getupInfo) {
        const fallKey = `${textureKey}-fall`;
        if (!this.scene.anims.exists(fallKey)) {
          // Fall + getup: play getup reversed (standing→floor) then forward (floor→standing).
          const fallDownFrames = Array.from({ length: getupInfo.count }, (_, i) => getupInfo.count - 1 - i);
          const standUpFrames = Array.from({ length: getupInfo.count }, (_, i) => i);
          this.scene.anims.create({
            key: fallKey,
            frames: this.scene.anims.generateFrameNumbers(`${textureKey}-getup`, { frames: [...fallDownFrames, ...standUpFrames] }),
            frameRate: getupInfo.rate ?? 8,
            repeat: 0,
          });
        }
      }
      return idleKey;
    }

    if (!this.scene.anims.exists(idleKey)) {
      this.scene.anims.create({
        key: idleKey,
        frames: this.scene.anims.generateFrameNumbers(textureKey, { start: bossType?.idleStart ?? 0, end: bossType?.idleEnd ?? 3 }),
        frameRate: 6,
        repeat: -1,
      });
    }
    if (bossType?.spawnStart != null && !this.scene.anims.exists(`${textureKey}-spawn`)) {
      this.scene.anims.create({
        key: `${textureKey}-spawn`,
        frames: this.scene.anims.generateFrameNumbers(textureKey, { start: bossType.spawnStart, end: bossType.spawnEnd }),
        frameRate: bossType.spawnFrameRate ?? 8,
        repeat: 0,
      });
    }
    if (bossType?.attackStart != null && !this.scene.anims.exists(`${textureKey}-attack`)) {
      this.scene.anims.create({
        key: `${textureKey}-attack`,
        frames: this.scene.anims.generateFrameNumbers(textureKey, { start: bossType.attackStart, end: bossType.attackEnd }),
        frameRate: bossType.attackFrameRate ?? 8,
        repeat: 0,
      });
    }
    if (bossType?.moveStart != null && !this.scene.anims.exists(`${textureKey}-move`)) {
      this.scene.anims.create({
        key: `${textureKey}-move`,
        frames: this.scene.anims.generateFrameNumbers(textureKey, { start: bossType.moveStart, end: bossType.moveEnd }),
        frameRate: bossType.moveFrameRate ?? 8,
        yoyo: bossType.moveYoyo ?? false,
        repeat: -1,
      });
    }
    if (bossType?.deathStart != null && !this.scene.anims.exists(`${textureKey}-death`)) {
      this.scene.anims.create({
        key: `${textureKey}-death`,
        frames: this.scene.anims.generateFrameNumbers(textureKey, { start: bossType.deathStart, end: bossType.deathEnd }),
        frameRate: bossType.deathFrameRate ?? 8,
        repeat: 0,
      });
    }
    if (bossType?.hurtStart != null && !this.scene.anims.exists(`${textureKey}-hurt`)) {
      this.scene.anims.create({
        key: `${textureKey}-hurt`,
        frames: this.scene.anims.generateFrameNumbers(textureKey, { start: bossType.hurtStart, end: bossType.hurtEnd }),
        frameRate: bossType.hurtFrameRate ?? 10,
        repeat: 0,
      });
    }
    return idleKey;
  }

  /**
   * Plays the boss react (hurt/attack) animation on a per-cooldown basis.
   *
   * @return {void}
   */
  playBossReact() {
    if (!this.scene.bossSprite?.active) return;
    const key = this.scene.bossSprite.getData('bossTypeKey') ?? this.scene.bossSprite.texture?.key;
    const hurtKey = `${key}-hurt`;
    const fallKey = `${key}-fall`;
    const attackKey = `${key}-attack`;
    const hasHurt = this.scene.anims.exists(hurtKey);
    const hasFall = this.scene.anims.exists(fallKey);
    let reactKey;
    if (hasHurt && hasFall && Math.random() < 0.5) {
      reactKey = fallKey;
    } else if (hasHurt) {
      reactKey = hurtKey;
    } else {
      reactKey = attackKey;
    }
    if (!this.scene.anims.exists(reactKey)) return;
    const now = this.scene.time.now;
    const cooldown = hasHurt ? 2000 : 8000;
    if ((now - (this.bossLastAttackAt ?? 0)) < cooldown) return;
    if (this.scene.bossSprite.anims.currentAnim?.key === reactKey) return;
    this.bossLastAttackAt = now;
    this.scene.bossSprite.play(reactKey);
    this.scene.bossSprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
      if (!this.scene.bossSprite?.active) return;
      const resumeKey = (this.bossPatrolPhase === BossPhase.MOVE && this.scene.anims.exists(`${key}-move`))
        ? `${key}-move`
        : `${key}-idle`;
      this.scene.bossSprite.play(resumeKey);
    });
  }

  /**
   * Starts the boss patrol tween cycle between left and right endpoints.
   *
   * @return {void}
   */
  startBossPatrol() {
    const sprite = this.scene.bossSprite;
    const bossTypeKey = sprite.getData('bossTypeKey') ?? sprite.texture?.key;

    if (isDreadknight(bossTypeKey)) {
      startDreadknightPatrol(this.scene, this);
      return;
    }

    const range = 120;
    const anchorX = this.scene.layout.boss.anchor.x;
    const anchorY = this.scene.layout.boss.anchor.y;
    const bossType = BOSS_TYPES.find(b => b.key === bossTypeKey);
    const moveKey = `${bossTypeKey}-move`;
    const idleKey = `${bossTypeKey}-idle`;
    const attackKey = `${bossTypeKey}-attack`;
    let goingRight = true;

    const startIdleBreath = (breathCount, onDone) => {
      if (!sprite?.active) return;
      sprite.play(idleKey);
      let count = 0;
      const onRepeat = () => {
        if (sprite.anims.currentAnim?.key !== idleKey) return;
        count++;
        if (count >= breathCount) {
          sprite.off(Phaser.Animations.Events.ANIMATION_REPEAT, onRepeat);
          this.bossIdleRepeatListener = null;
          onDone();
        }
      };
      this.bossIdleRepeatListener = onRepeat;
      sprite.on(Phaser.Animations.Events.ANIMATION_REPEAT, onRepeat);
    };

    const idleAtEndpoint = (onDone) => {
      this.bossPatrolPhase = BossPhase.IDLE;
      const canAttack = this.scene.anims.exists(attackKey)
        && !sprite.anims.currentAnim?.key.includes('-attack')
        && Math.random() < 0.25;
      if (canAttack) {
        startIdleBreath(2, () => {
          if (!sprite?.active || this.bossPatrolPhase !== BossPhase.IDLE) return;
          sprite.play(attackKey);
          sprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
            if (!sprite?.active || this.bossPatrolPhase !== BossPhase.IDLE) return;
            this._bossSwipeHit(sprite.x, bossType);
            onDone();
          });
        });
      } else {
        startIdleBreath(2, onDone);
      }
    };

    const doStep = () => {
      if (!sprite?.active) return;
      if (this.bossIdleRepeatListener) {
        sprite.off(Phaser.Animations.Events.ANIMATION_REPEAT, this.bossIdleRepeatListener);
        this.bossIdleRepeatListener = null;
      }
      const targetX = goingRight ? anchorX + range / 2 : anchorX - range / 2;
      sprite.setFlipX(goingRight);
      if (this.scene.anims.exists(moveKey)) sprite.play(moveKey);
      this.bossPatrolPhase = BossPhase.MOVE;
      this.scene.tweens.add({
        targets: sprite,
        x: targetX,
        duration: 1800,
        ease: 'Sine.easeInOut',
        onComplete: () => {
          if (!sprite?.active) return;
          goingRight = !goingRight;
          idleAtEndpoint(doStep);
        },
      });
    };

    sprite.x = anchorX - range / 2;
    sprite.setFlipX(true);
    doStep();

    if (bossType?.float) {
      const { amplitude, duration } = bossType.float;
      sprite.y = anchorY;
      this.scene.tweens.add({
        targets: sprite,
        y: anchorY - amplitude,
        duration,
        ease: 'Sine.easeInOut',
        yoyo: true,
        repeat: -1,
      });
    }
  }

  /**
   * Checks fighters near bossX and applies stun visual to those in melee range.
   *
   * @param {number} bossX
   * @param {object} bossType
   * @return {void}
   */
  _bossSwipeHit(bossX, bossType) {
    const bossHalfW = bossType
      ? ((bossType.animFiles ? bossType.animFiles.idle.frameWidth : (bossType.frameWidth ?? 32))
          * (bossType.scale ?? 1)) / 2
      : 64;
    const hitRange = bossHalfW + 20;

    for (const entry of this.scene.fighters.values()) {
      if (!entry.sprite?.active) continue;
      if (Math.abs(entry.sprite.x - bossX) <= hitRange) {
        applyStunEffect(this.scene, entry);
      }
    }
  }

  /**
   * Handles a boss-spawned event: swaps the sprite, resets HP bar and leaderboard.
   *
   * @param {{ boss_number: number, max_hp: number, boss_name?: string, fighters?: Array<{user_id: number|string, character: string}> }} payload
   * @return {void}
   */
  handleBossSpawned(payload) {
    if (!payload || payload.boss_number == null || payload.max_hp == null) {
      return;
    }
    this.bossLastAttackAt = 0;
    this.bossPatrolPhase = BossPhase.MOVE;
    this.bossIdleRepeatListener = null;
    this.scene.charge?.clearAllCharges?.();
    const L = this.scene.layout;
    const oldSprite = this.scene.bossSprite;
    this.scene.tweens.killTweensOf(oldSprite);
    this.scene.tweens.add({
      targets: oldSprite,
      alpha: 0,
      duration: 200,
      onComplete: () => oldSprite.destroy(),
    });

    const bt = Boss.bossTypeFor(payload.boss_number);
    const typeKey = bt.key;
    const texKey = bt.animFiles ? `${typeKey}-idle` : typeKey;
    const idleKey = this.ensureBossIdleAnim(typeKey);
    const spawnKey = `${typeKey}-spawn`;

    if (this.scene.anims.exists(spawnKey)) {
      this.scene.bossSprite = this.scene.add
        .sprite(L.boss.anchor.x, L.boss.anchor.y, texKey)
        .setScale(bt.scale)
        .setDepth(5)
        .setData('bossTypeKey', typeKey)
        .play(spawnKey);
      if (bt.pixelate) {
        this.scene.bossSprite.preFX?.addPixelate(bt.pixelate);
      }
      this.scene.bossSprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        if (this.scene.bossSprite?.active) {
          this.scene.bossSprite.play(idleKey);
          this.startBossPatrol();
        }
      });
    } else {
      this.scene.bossSprite = this.scene.add
        .sprite(L.boss.anchor.x, -40, texKey)
        .setScale(bt.scale)
        .setDepth(5)
        .setData('bossTypeKey', typeKey)
        .play(idleKey);
      if (bt.pixelate) {
        this.scene.bossSprite.preFX?.addPixelate(bt.pixelate);
      }
      this.scene.tweens.add({
        targets: this.scene.bossSprite,
        y: L.boss.anchor.y,
        duration: TIMINGS.bossSpawnMs,
        ease: 'Bounce.easeOut',
        onComplete: () => this.startBossPatrol(),
      });
    }

    this.scene.bossState = {
      currentHp: payload.max_hp,
      maxHp: payload.max_hp,
      number: payload.boss_number,
      name: payload.boss_name,
    };
    this.scene.bossNameText.setText(Boss.bossLabel(this.scene.bossState));
    this.scene.hpBarFill.width = L.hpBar.width;
    this.scene.hpBarFill.setFillStyle(0x22c55e);
    this.scene.hpText.setText(`${formatHp(payload.max_hp)} / ${formatHp(payload.max_hp)}`);
    this.scene.leaderboard?.reset();
    this.scene.damageTotals.clear();
    for (const [, f] of this.scene.fighters.entries()) {
      f.damageScale = 1;
      this.scene.fighter.tweenToRestScale(f, { duration: 400, ease: 'Quad.easeOut' });
    }
    // characterForBoss() is deterministic per (user, boss) — every fighter
    // needs re-skinning to the new boss's assignment, otherwise they keep
    // showing the previous boss's character until the page is reloaded.
    this.scene.fighter.updateCharacters(payload.fighters);
  }

  /**
   * Handles a boss-killed event: plays death animation and shows MVP card.
   *
   * @param {{ boss_name?: string, boss_number?: number, killer_slack_handle?: string }} payload
   * @return {void}
   */
  handleBossKilled(payload = {}) {
    this.scene.charge?.clearAllCharges?.();
    if (this.scene.bossSprite) {
      this.scene.tweens.killTweensOf(this.scene.bossSprite);
      const bt = Boss.bossTypeFor(this.scene.bossState?.number ?? 0);
      const deathKey = `${bt.key}-death`;
      if (this.scene.anims.exists(deathKey)) {
        const dyingSprite = this.scene.bossSprite;
        dyingSprite.play(deathKey);
        dyingSprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
          if (!dyingSprite?.active) return;
          this.scene.tweens.add({
            targets: dyingSprite,
            alpha: 0,
            duration: 300,
            ease: 'Quad.easeIn',
          });
        });
      } else {
        this.scene.tweens.add({
          targets: this.scene.bossSprite,
          scale: 0,
          alpha: 0,
          angle: 360,
          duration: TIMINGS.bossKilledMs,
          ease: 'Quad.easeIn',
        });
      }
      this.scene.cameras.main.flash(400, 255, 255, 255);
    }
    if (this.scene.leaderboard) {
      Leaderboard.showMvpCard(this.scene, {
        bossLabel: Boss.bossLabel({
          name: payload.boss_name ?? this.scene.bossState.name,
          number: payload.boss_number ?? this.scene.bossState.number,
        }),
        ranked: this.scene.leaderboard.getRanked(),
        killerHandle: payload.killer_slack_handle ?? null,
      });
    }
  }
}
