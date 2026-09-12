import Phaser from 'phaser';
import { BAT_CONFIG, TIMINGS } from '@battlefield/config.js';
import { computeBatHitTarget, BAT_HP_THRESHOLDS } from './bat-targeting.js';
import { randomWanderPoint } from './bat-wander.js';

/** Manages the 5-bat boss-minion swarm: spawn, hit routing, auto-attacks, and wander movement. */
export class BatSwarm {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
    /** @type {Map<number, {sprite: Phaser.GameObjects.Sprite|null, alive: boolean, wanderTimer: Phaser.Time.TimerEvent|null}>} */
    this.bats = new Map();
    this.maxHp = 1;
    this.autoAttackTimer = null;
    this.attackCycleIndex = -1;
  }

  /**
   * Spawns BAT_CONFIG.count bats around the current boss's wander zone and
   * starts their loops. A bat whose HP threshold the given currentHp has
   * already crossed starts dead with no sprite at all — this is how a mid-
   * fight scene reboot (orientation change) lands on the correct bat state
   * without needing a separate snapshot of which bats were alive.
   *
   * @param {number} maxHp
   * @param {number} currentHp
   * @return {void}
   */
  spawn(maxHp, currentHp) {
    this.destroy();
    this.maxHp = maxHp;
    this._ensureAnims();
    const zone = this.scene.layout.bats;
    const hpPct = currentHp / maxHp;
    for (let i = 1; i <= BAT_CONFIG.count; i++) {
      if (hpPct <= BAT_HP_THRESHOLDS[i - 1]) {
        this.bats.set(i, { sprite: null, alive: false, wanderTimer: null });
        continue;
      }
      const point = randomWanderPoint(zone);
      const sprite = this.scene.add
        .sprite(point.x, point.y, `${BAT_CONFIG.key}-flying`)
        .setScale(BAT_CONFIG.scale)
        .setDepth(4)
        .play(`${BAT_CONFIG.key}-flying`);
      const entry = { sprite, alive: true, wanderTimer: null };
      this.bats.set(i, entry);
      this._scheduleWander(entry);
    }
    this._scheduleAutoAttack();
  }

  /**
   * Resolves a HitDealt's visual target, killing any bat whose threshold this hit just crossed.
   *
   * @param {number} damage
   * @param {number} hpBefore
   * @param {number} hpAfter
   * @return {{sprite: Phaser.GameObjects.Sprite, x: number, y: number, index: number}|null}
   */
  resolveHitTarget(damage, hpBefore, hpAfter) {
    const aliveBats = [false];
    for (let i = 1; i <= BAT_CONFIG.count; i++) {
      aliveBats.push(this.bats.get(i)?.alive ?? false);
    }
    const { targetBat, killedBats } = computeBatHitTarget({
      damage,
      hpBeforePct: hpBefore / this.maxHp,
      hpAfterPct: hpAfter / this.maxHp,
      aliveBats,
    });
    for (const idx of killedBats) {
      this._killBat(idx);
    }
    if (targetBat == null) {
      return null;
    }
    const entry = this.bats.get(targetBat);
    if (!entry?.sprite?.active) {
      return null;
    }
    return { sprite: entry.sprite, x: entry.sprite.x, y: entry.sprite.y, index: targetBat };
  }

  /**
   * Plays a bat's Hurt reaction after it takes a non-lethal hit.
   *
   * @param {{index: number}} target
   * @return {void}
   */
  reactHurt(target) {
    const entry = this.bats.get(target.index);
    if (!entry?.sprite?.active || !entry.alive) {
      return;
    }
    entry.sprite.play(`${BAT_CONFIG.key}-hurt`);
    entry.sprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
      if (entry.sprite?.active && entry.alive) {
        entry.sprite.play(`${BAT_CONFIG.key}-flying`);
      }
    });
  }

  /**
   * Kills one bat: plays Death, cancels its loops, and fades the sprite out.
   *
   * @param {number} index
   * @return {void}
   */
  _killBat(index) {
    const entry = this.bats.get(index);
    if (!entry || !entry.alive) {
      return;
    }
    entry.alive = false;
    entry.wanderTimer?.remove();
    this.scene.tweens.killTweensOf(entry.sprite);
    entry.sprite.play(`${BAT_CONFIG.key}-death`);
    entry.sprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
      this.scene.tweens.add({
        targets: entry.sprite,
        alpha: 0,
        duration: 300,
        onComplete: () => entry.sprite?.destroy(),
      });
    });
  }

  /**
   * Schedules this bat's next random-wander tween, re-scheduling itself on completion.
   *
   * @param {{sprite: Phaser.GameObjects.Sprite, alive: boolean, wanderTimer: Phaser.Time.TimerEvent|null}} entry
   * @return {void}
   */
  _scheduleWander(entry) {
    const delay = Phaser.Math.Between(TIMINGS.batWanderMinMs, TIMINGS.batWanderMaxMs);
    entry.wanderTimer = this.scene.time.delayedCall(delay, () => {
      if (!entry.alive || !entry.sprite?.active) {
        return;
      }
      const point = randomWanderPoint(this.scene.layout.bats);
      const dist = Phaser.Math.Distance.Between(entry.sprite.x, entry.sprite.y, point.x, point.y);
      this.scene.tweens.add({
        targets: entry.sprite,
        x: point.x,
        y: point.y,
        duration: Phaser.Math.Clamp(dist * 4, 400, 1400),
        ease: 'Sine.easeInOut',
        onComplete: () => this._scheduleWander(entry),
      });
    });
  }

  /**
   * Schedules the next autonomous bat attack, cycling through the living bats one at a time.
   *
   * @return {void}
   */
  _scheduleAutoAttack() {
    const delay = Phaser.Math.Between(TIMINGS.batAutoAttackMinMs, TIMINGS.batAutoAttackMaxMs);
    this.autoAttackTimer = this.scene.time.delayedCall(delay, () => {
      const living = [...this.bats.entries()].filter(([, e]) => e.alive).map(([i]) => i);
      if (living.length === 0) {
        this._scheduleAutoAttack();
        return;
      }
      this.attackCycleIndex = (this.attackCycleIndex + 1) % living.length;
      const entry = this.bats.get(living[this.attackCycleIndex]);
      const animKey = Math.random() < 0.5 ? `${BAT_CONFIG.key}-attack1` : `${BAT_CONFIG.key}-attack2`;
      entry.sprite.play(animKey);
      entry.sprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        if (entry.sprite?.active && entry.alive) {
          entry.sprite.play(`${BAT_CONFIG.key}-flying`);
        }
      });
      this._scheduleAutoAttack();
    });
  }

  /**
   * Registers every Bat animation against its own individually-loaded spritesheets.
   *
   * @return {void}
   */
  _ensureAnims() {
    for (const [anim, info] of Object.entries(BAT_CONFIG.animFiles)) {
      const key = `${BAT_CONFIG.key}-${anim}`;
      if (!this.scene.anims.exists(key)) {
        this.scene.anims.create({
          key,
          frames: this.scene.anims.generateFrameNumbers(key, { start: 0, end: info.count - 1 }),
          frameRate: info.rate,
          repeat: info.loop ? -1 : 0,
        });
      }
    }
  }

  /**
   * Destroys every bat sprite and cancels all pending timers — called before a respawn and on BossKilled.
   *
   * @return {void}
   */
  destroy() {
    this.autoAttackTimer?.remove();
    this.autoAttackTimer = null;
    for (const entry of this.bats.values()) {
      entry.wanderTimer?.remove();
      this.scene.tweens.killTweensOf(entry.sprite);
      entry.sprite?.destroy();
    }
    this.bats.clear();
  }
}
