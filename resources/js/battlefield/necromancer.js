import Phaser from 'phaser';
import { NECROMANCER_CONFIG, TIMINGS } from '@battlefield/config.js';
import { randomWanderPoint } from './boss/bat-wander.js';
import { isValidMoveTarget } from './move-geometry.js';
import { Boss } from './boss.js';

// Rough footprint used only to size isValidMoveTarget's exclusion margins
// for the Necromancer's own (much larger than a fighter's) sprite.
const SUMMON_SPOT_FSIZE = 90;
// How far to the side of the joining fighter the Necromancer appears.
const SUMMON_SPOT_OFFSET = 100;
// Minimum clearance from any other already-placed fighter.
const SUMMON_SPOT_MIN_FIGHTER_GAP = 50;
const TELEPORT_FADE_MS = 140;

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
    this.homeAnchor = null;
  }

  /**
   * Creates the Necromancer sprite at its fixed anchor and starts idle wandering.
   *
   * @return {void}
   */
  create() {
    this._ensureAnims();
    this.homeAnchor = this.scene.layout.necromancer.anchor;
    this.sprite = this.scene.add
      .sprite(this.homeAnchor.x, this.homeAnchor.y, `${NECROMANCER_CONFIG.key}-idle`)
      .setScale(NECROMANCER_CONFIG.scale)
      .setDepth(2)
      .play(`${NECROMANCER_CONFIG.key}-idle`);
    this._scheduleWander();
  }

  /**
   * Requests the Necromancer's Summon animation for a newly-joined fighter at
   * (targetX, targetY), then invokes onRevealed so the caller can reveal the
   * summoned fighter there. The Necromancer teleports beside the target to
   * cast (rather than casting from its home spot across the screen), then
   * teleports back home once done. Multiple fighters joining close together
   * are queued and summoned one at a time — the shared sprite can only play
   * one animation/be in one place at once. Falls back to calling onRevealed
   * immediately if the Necromancer sprite isn't available.
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
   * Plays one queued summon to completion: teleports beside the target,
   * casts (a fixed delayedCall matched to the animation's own frame
   * count/rate, not Phaser's ANIMATION_COMPLETE event, which the wander
   * loop's independent .play() calls could otherwise strand), teleports back
   * home, then recurses onto the next queued job, if any.
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
    const spot = this._pickSummonSpot(targetX, targetY);
    this._teleportTo(spot.x, spot.y, spot.flip, () => {
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
        }
        onRevealed();
        this._teleportTo(this.homeAnchor.x, this.homeAnchor.y, false, () => {
          if (this.sprite?.active) {
            this.sprite.play(`${NECROMANCER_CONFIG.key}-idle`);
          }
          this._processSummonQueue();
        });
      });
    });
  }

  /**
   * Picks a spot beside (targetX, targetY) for the Necromancer to appear at —
   * whichever side (left/right, a fixed offset away) is clear of the
   * boss/HP-bar column, the leaderboard and Damage HUD panels, the screen
   * edges, and every other currently-placed fighter. Prefers the side
   * toward screen-center first. Falls back to the preferred side's raw
   * offset (unvalidated) if neither side comes up clear, so a summon can
   * never silently fail to produce a spot.
   *
   * @param {number} targetX
   * @param {number} targetY
   * @return {{x: number, y: number, flip: boolean}}
   */
  _pickSummonSpot(targetX, targetY) {
    const L = this.scene.layout;
    const bossType = Boss.bossTypeFor(this.scene.bossState?.number ?? 0);
    const ctx = { layout: L, bossType, fsize: SUMMON_SPOT_FSIZE };
    // Appear on whichever side points back toward screen-center, so the
    // Necromancer favors open middle space over crowding a screen edge.
    const preferLeft = targetX > L.logicalWidth / 2;
    const offsets = preferLeft ? [-SUMMON_SPOT_OFFSET, SUMMON_SPOT_OFFSET] : [SUMMON_SPOT_OFFSET, -SUMMON_SPOT_OFFSET];

    for (const dx of offsets) {
      const x = targetX + dx;
      const y = targetY;
      if (!isValidMoveTarget(x, y, ctx)) continue;
      const tooCloseToFighter = [...this.scene.fighters.values()].some(f =>
        f.sprite?.active && Phaser.Math.Distance.Between(x, y, f.sprite.x, f.sprite.y) < SUMMON_SPOT_MIN_FIGHTER_GAP
      );
      if (tooCloseToFighter) continue;
      return { x, y, flip: dx < 0 };
    }

    const fallbackDx = offsets[0];
    return { x: targetX + fallbackDx, y: targetY, flip: fallbackDx < 0 };
  }

  /**
   * Fades the Necromancer out, repositions and re-faces it instantly, then
   * fades it back in — reads as "vanish and reappear" rather than sliding.
   *
   * @param {number} x
   * @param {number} y
   * @param {boolean} flip
   * @param {Function} onArrived
   * @return {void}
   */
  _teleportTo(x, y, flip, onArrived) {
    this.scene.tweens.add({
      targets: this.sprite,
      alpha: 0,
      duration: TELEPORT_FADE_MS,
      ease: 'Quad.easeIn',
      onComplete: () => {
        if (!this.sprite?.active) {
          onArrived();
          return;
        }
        this.sprite.setPosition(x, y).setFlipX(flip);
        this.scene.tweens.add({
          targets: this.sprite,
          alpha: 1,
          duration: TELEPORT_FADE_MS,
          ease: 'Quad.easeOut',
          onComplete: onArrived,
        });
      },
    });
  }

  /**
   * Draws a fading purple beam from the Necromancer's raised hand to the
   * summon target for the duration of its cast, so the cast itself reads as
   * visibly "calling" the circle into being — now typically a short arc,
   * since the Necromancer teleports beside the target before casting.
   *
   * @param {number} targetX
   * @param {number} targetY
   * @param {number} durationMs
   * @return {void}
   */
  _castBeam(targetX, targetY, durationMs) {
    // The sprite's origin sits at frame-center; the raised hand/staff in the
    // Summon artwork sits above and to the side it's currently facing (the
    // artwork casts toward its own right by default, mirrored with flipX) —
    // starting the beam at sprite.x/y itself reads as coming from around its
    // waist instead.
    const facingSign = this.sprite.flipX ? -1 : 1;
    const handX = this.sprite.x + facingSign * NECROMANCER_CONFIG.scale * 100 * 0.22;
    const handY = this.sprite.y - NECROMANCER_CONFIG.scale * 100 * 0.12;
    const dx = targetX - handX;
    const dy = targetY - handY;
    const dist = Math.hypot(dx, dy) || 1;
    // Perpendicular unit vector, used to jitter the arc's midpoints so it
    // redraws as a flickering lightning-like bolt instead of one static,
    // stiff-looking straight line.
    const nx = -dy / dist;
    const ny = dx / dist;
    const segments = 8;
    const jitterPx = 10;

    const beam = this.scene.add.graphics().setDepth(1).setBlendMode(Phaser.BlendModes.ADD);
    const redraw = () => {
      const points = [{ x: handX, y: handY }];
      for (let i = 1; i < segments; i++) {
        const t = i / segments;
        const jitter = (Math.random() - 0.5) * jitterPx;
        points.push({ x: handX + dx * t + nx * jitter, y: handY + dy * t + ny * jitter });
      }
      points.push({ x: targetX, y: targetY });
      beam.clear();
      beam.lineStyle(4, 0xa855f7, 0.8);
      for (let i = 0; i < points.length - 1; i++) {
        beam.lineBetween(points[i].x, points[i].y, points[i + 1].x, points[i + 1].y);
      }
      beam.lineStyle(2, 0xe9d5ff, 0.9);
      for (let i = 0; i < points.length - 1; i++) {
        beam.lineBetween(points[i].x, points[i].y, points[i + 1].x, points[i + 1].y);
      }
    };
    redraw();
    const flicker = this.scene.time.addEvent({
      delay: 60,
      repeat: Math.max(0, Math.ceil(durationMs / 60) - 1),
      callback: redraw,
    });
    this.scene.tweens.add({
      targets: beam,
      alpha: 0,
      duration: durationMs,
      ease: 'Sine.easeIn',
      onComplete: () => {
        flicker.remove();
        beam.destroy();
      },
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
