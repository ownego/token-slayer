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
    /** @type {Array<Function>} pending onRevealed callbacks, processed one at a time */
    this.summonQueue = [];
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
   * Requests the Necromancer's Summon animation, casting toward (targetX,
   * targetY), then invokes onRevealed so the caller can reveal the summoned
   * fighter there. Multiple fighters joining close together are queued and
   * summoned one at a time — the shared sprite can only play one animation
   * at once, and playing a second Summon over an in-progress one left
   * Phaser's animation state corrupted (an uncaught error deep in Phaser's
   * animation start, from a rapid-join repro). Falls back to calling
   * onRevealed immediately if the Necromancer sprite isn't available.
   *
   * @param {number} targetX
   * @param {number} targetY
   * @param {Function} onRevealed
   * @return {void}
   */
  summon(targetX, targetY, onRevealed) {
    if (!this.sprite?.active) {
      onRevealed();
      return;
    }
    this.summonQueue.push({ targetX, targetY, onRevealed });
    if (!this.isSummoning) {
      this._processSummonQueue();
    }
  }

  /**
   * Plays one queued summon to completion via a fixed delayedCall matched to
   * the animation's own frame count/rate (not Phaser's ANIMATION_COMPLETE
   * event, which the wander loop's independent .play() calls could
   * otherwise strand), then recurses onto the next queued job, if any.
   *
   * @return {void}
   */
  _processSummonQueue() {
    const job = this.summonQueue.shift();
    if (!job) {
      this.isSummoning = false;
      return;
    }
    const { targetX, targetY, onRevealed } = job;
    this.isSummoning = true;
    if (!this.sprite?.active) {
      onRevealed();
      this._processSummonQueue();
      return;
    }
    this.scene.tweens.killTweensOf(this.sprite);
    // The Summon artwork's default (unflipped) orientation already casts
    // toward the right; only mirror it when the target actually sits to
    // the left, overriding whatever flip the wander loop last left it in —
    // via a quick squash-flip-unsquash turn instead of an instant mirror,
    // so it reads as the character actually turning rather than snapping.
    this._faceTarget(targetX, () => {
      if (!this.sprite?.active) {
        onRevealed();
        this._processSummonQueue();
        return;
      }
      this.sprite.setTint(0xa855f7);
      this.sprite.play(`${NECROMANCER_CONFIG.key}-summon`);
      const summonInfo = NECROMANCER_CONFIG.animFiles.summon;
      const durationMs = Math.ceil((summonInfo.count / summonInfo.rate) * 1000);
      this._castBeam(targetX, targetY, durationMs);
      this.scene.time.delayedCall(durationMs, () => {
        if (this.sprite?.active) {
          this.sprite.clearTint();
          this.sprite.play(`${NECROMANCER_CONFIG.key}-idle`);
        }
        onRevealed();
        this._processSummonQueue();
      });
    });
  }

  /**
   * Turns the Necromancer to face targetX before onDone runs — a quick
   * horizontal squash, flip, and unsquash instead of an instant mirror, so
   * facing the summon target reads as a natural turn. Calls onDone
   * immediately if it's already facing the right way.
   *
   * @param {number} targetX
   * @param {Function} onDone
   * @return {void}
   */
  _faceTarget(targetX, onDone) {
    const shouldFlip = targetX < this.sprite.x;
    if (this.sprite.flipX === shouldFlip) {
      onDone();
      return;
    }
    const baseScaleX = Math.abs(this.sprite.scaleX);
    this.scene.tweens.add({
      targets: this.sprite,
      scaleX: 0,
      duration: 90,
      ease: 'Quad.easeIn',
      onComplete: () => {
        this.sprite.setFlipX(shouldFlip);
        this.scene.tweens.add({
          targets: this.sprite,
          scaleX: baseScaleX,
          duration: 90,
          ease: 'Quad.easeOut',
          onComplete: onDone,
        });
      },
    });
  }

  /**
   * Draws a fading purple beam from the Necromancer's raised hand to the
   * summon target for the duration of its cast, so the cast itself reads as
   * visibly "calling" the circle into being rather than happening invisibly
   * off to the side.
   *
   * @param {number} targetX
   * @param {number} targetY
   * @param {number} durationMs
   * @return {void}
   */
  _castBeam(targetX, targetY, durationMs) {
    // The sprite's origin sits at frame-center; the raised hand/staff in the
    // Summon artwork is well above that, roughly a third of the way up the
    // rendered frame — starting the beam at sprite.y itself reads as coming
    // from around its feet/waist instead.
    const handY = this.sprite.y - NECROMANCER_CONFIG.scale * 100 * 0.18;
    const beam = this.scene.add.graphics().setDepth(1).setBlendMode(Phaser.BlendModes.ADD);
    beam.lineStyle(4, 0xa855f7, 0.8);
    beam.lineBetween(this.sprite.x, handY, targetX, targetY);
    beam.lineStyle(2, 0xe9d5ff, 0.9);
    beam.lineBetween(this.sprite.x, handY, targetX, targetY);
    this.scene.tweens.add({
      targets: beam,
      alpha: 0,
      duration: durationMs,
      ease: 'Sine.easeIn',
      onComplete: () => beam.destroy(),
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
    const circle = this.scene.add.sprite(x, y, key).setDepth(1).setScale(2.6).setBlendMode(Phaser.BlendModes.ADD);
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
