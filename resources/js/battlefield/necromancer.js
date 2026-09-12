import Phaser from 'phaser';
import { NECROMANCER_CONFIG, TIMINGS } from '@battlefield/config.js';
import { randomWanderPoint } from './boss/bat-wander.js';

/** Manages the permanent Necromancer fixture: idle wander and the summon-on-join effect. */
export class Necromancer {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
    this.sprite = null;
    this.isSummoning = false;
  }

  /**
   * Creates the Necromancer sprite at its fixed anchor and starts idle wandering.
   *
   * @return {void}
   */
  create() {
    this._ensureAnims();
    const anchor = this.scene.layout.necromancer.anchor;
    this.sprite = this.scene.add
      .sprite(anchor.x, anchor.y, `${NECROMANCER_CONFIG.key}-idle`)
      .setScale(NECROMANCER_CONFIG.scale)
      .setDepth(2)
      .play(`${NECROMANCER_CONFIG.key}-idle`);
    this._scheduleWander();
  }

  /**
   * Plays the Necromancer's Summon animation, then invokes onRevealed so the
   * caller can fade the newly-joined fighter in. Falls back to calling
   * onRevealed immediately if the Necromancer sprite isn't available.
   *
   * Uses a fixed delayedCall matched to the animation's own frame count/rate
   * rather than waiting on Phaser's ANIMATION_COMPLETE event: the wander
   * loop below independently calls .play() on this same sprite on its own
   * timer, which — if it lands mid-summon — replaces the summon animation
   * before it completes naturally and ANIMATION_COMPLETE never fires,
   * leaving onRevealed stuck forever. isSummoning also blocks the wander
   * loop from firing at all while a summon is in progress.
   *
   * @param {Function} onRevealed
   * @return {void}
   */
  summon(onRevealed) {
    if (!this.sprite?.active) {
      onRevealed();
      return;
    }
    this.isSummoning = true;
    this.sprite.play(`${NECROMANCER_CONFIG.key}-summon`);
    const summonInfo = NECROMANCER_CONFIG.animFiles.summon;
    const durationMs = Math.ceil((summonInfo.count / summonInfo.rate) * 1000);
    this.scene.time.delayedCall(durationMs, () => {
      this.isSummoning = false;
      if (this.sprite?.active) {
        this.sprite.play(`${NECROMANCER_CONFIG.key}-idle`);
      }
      onRevealed();
    });
  }

  /**
   * Spawns a one-shot summon-circle burst at the given world position — used
   * under a newly-joined fighter as it rises in, separate from the
   * Necromancer's own sprite/position.
   *
   * @param {number} x
   * @param {number} y
   * @return {void}
   */
  spawnSummonCircle(x, y) {
    const key = `${NECROMANCER_CONFIG.key}-circle`;
    if (!this.scene.anims.exists(key)) {
      return;
    }
    const circle = this.scene.add.sprite(x, y, key).setDepth(1).setScale(1.4);
    circle.play(key);
    circle.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => circle.destroy());
  }

  /**
   * Schedules the Necromancer's next small wander step within its fixed
   * zone. Reschedules without moving while a summon is in progress, so it
   * never interrupts the summon animation on the shared sprite.
   *
   * @return {void}
   */
  _scheduleWander() {
    const zone = this.scene.layout.necromancer;
    const delay = Phaser.Math.Between(TIMINGS.batWanderMinMs, TIMINGS.batWanderMaxMs);
    this.scene.time.delayedCall(delay, () => {
      if (!this.sprite?.active) {
        return;
      }
      if (this.isSummoning) {
        this._scheduleWander();
        return;
      }
      const point = randomWanderPoint({
        centerX: zone.anchor.x,
        centerY: zone.anchor.y,
        radiusX: zone.wanderRadiusX,
        radiusY: zone.wanderRadiusY,
      });
      this.sprite.setFlipX(point.x < this.sprite.x);
      this.sprite.play(`${NECROMANCER_CONFIG.key}-walk`);
      this.scene.tweens.add({
        targets: this.sprite,
        x: point.x,
        y: point.y,
        duration: 1200,
        ease: 'Sine.easeInOut',
        onComplete: () => {
          if (this.sprite?.active && !this.isSummoning) {
            this.sprite.play(`${NECROMANCER_CONFIG.key}-idle`);
          }
          this._scheduleWander();
        },
      });
    });
  }

  /**
   * Registers every Necromancer animation against its own individually-loaded spritesheets.
   *
   * @return {void}
   */
  _ensureAnims() {
    for (const [anim, info] of Object.entries(NECROMANCER_CONFIG.animFiles)) {
      const key = `${NECROMANCER_CONFIG.key}-${anim}`;
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
}
