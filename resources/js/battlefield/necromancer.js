import Phaser from 'phaser';
import { NECROMANCER_CONFIG, TIMINGS } from '@battlefield/config.js';
import { randomWanderPoint } from './boss/bat-wander.js';
import { isValidMoveTarget } from './move-geometry.js';
import { Boss } from './boss.js';
import { shouldSkipSummonFlourish } from './boss/summon-queue.js';

// Rough footprint used only to size isValidMoveTarget's exclusion margins
// for the Necromancer's own (much larger than a fighter's) sprite.
const SUMMON_SPOT_FSIZE = 90;
// How far to the side of the joining fighter the Necromancer appears.
const SUMMON_SPOT_OFFSET = 100;
// Minimum clearance from any other already-placed fighter.
const SUMMON_SPOT_MIN_FIGHTER_GAP = 50;
// Safety net for _processSummonQueue: comfortably above the full
// teleport-out/cast/teleport-back cycle's own worst-case duration (roughly
// 4.2s with the current asset pack's frame counts/rates), so a job that
// stalls for any reason (a thrown exception, a destroyed sprite mid-flight)
// still reveals its fighter and frees the queue instead of stranding every
// later join invisible for the rest of the browser session.
const SUMMON_WATCHDOG_MS = 7000;

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
    this._createHomePlatform();
    this.sprite = this.scene.add
      .sprite(this.homeAnchor.x, this.homeAnchor.y, `${NECROMANCER_CONFIG.key}-idle`)
      .setScale(NECROMANCER_CONFIG.scale)
      .setDepth(2)
      .play(`${NECROMANCER_CONFIG.key}-idle`);
    this._scheduleWander();
  }

  /**
   * Draws a permanent, larger summon-circle under the Necromancer's home
   * wander area — a fixed "platform" marking where it lives, sized to cover
   * the whole wander radius rather than one fighter's feet. The circle
   * animation isn't set to loop, so playing it once leaves it sitting on its
   * final (fully-formed) frame indefinitely, for free.
   *
   * @return {void}
   */
  _createHomePlatform() {
    const key = `${NECROMANCER_CONFIG.key}-circle`;
    const baseScale = 4.2;
    const platform = this.scene.add
      .sprite(this.homeAnchor.x, this.homeAnchor.y, key)
      .setDepth(0.5)
      .setScale(baseScale)
      .setAlpha(0.55)
      .setBlendMode(Phaser.BlendModes.ADD)
      .play(key);
    // Slow, gentle breathing pulse — alpha and scale drifting a little and
    // back, on a long cycle so it reads as ambient rather than animated.
    this.scene.tweens.add({
      targets: platform,
      alpha: 0.3,
      scale: baseScale * 0.92,
      duration: 3200,
      yoyo: true,
      repeat: -1,
      ease: 'Sine.easeInOut',
    });
  }

  /**
   * Requests the Necromancer's Summon animation for a newly-joined fighter at
   * (targetX, targetY), then invokes onRevealed so the caller can reveal the
   * summoned fighter there. The Necromancer teleports beside the target to
   * cast (rather than casting from its home spot across the screen), then
   * teleports back home once done. Multiple fighters joining close together
   * are queued and summoned one at a time — the shared sprite can only play
   * one animation/be in one place at once. Falls back to calling onRevealed
   * immediately if the Necromancer sprite isn't available, or if a burst of
   * joins has already backed the queue up past SUMMON_QUEUE_BURST_LIMIT.
   *
   * getTargetPos is called fresh right before each use, not captured once
   * here — a fighter queued behind another can have its grid slot shifted by
   * relayoutFighters() (triggered by a later fighter joining) before its own
   * turn comes up, and a snapshot taken at queue time would then point the
   * teleport/beam at a stale position the fighter no longer rises at.
   *
   * @param {Function} getTargetPos - () => {x: number, y: number}, read live
   * @param {Function} onRevealed
   * @return {void}
   */
  summon(getTargetPos, onRevealed) {
    if (!this.sprite?.active || shouldSkipSummonFlourish(this.summonQueue.length)) {
      onRevealed();
      return;
    }
    this.summonQueue.push({ getTargetPos, onRevealed });
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
   * A SUMMON_WATCHDOG_MS timeout backstops the whole chain: reveal() and
   * advance() are each idempotent (a second call is a no-op), so whichever
   * fires first — the chain's own natural completion or the watchdog — wins,
   * and the other becomes harmless. Without this, a single stall anywhere in
   * the chain (a thrown exception, a sprite destroyed mid-flight) would leave
   * isSummoning stuck true forever, permanently hiding every fighter queued
   * after it.
   *
   * @return {void}
   */
  _processSummonQueue() {
    const job = this.summonQueue.shift();
    if (!job) {
      this.isSummoning = false;
      return;
    }
    const { getTargetPos, onRevealed } = job;
    this.isSummoning = true;

    let revealed = false;
    let advanced = false;
    const reveal = () => {
      if (revealed) return;
      revealed = true;
      onRevealed();
    };
    const advance = () => {
      if (advanced) return;
      advanced = true;
      watchdog.remove();
      this._processSummonQueue();
    };
    const watchdog = this.scene.time.delayedCall(SUMMON_WATCHDOG_MS, () => {
      reveal();
      advance();
    });

    if (!this.sprite?.active) {
      reveal();
      advance();
      return;
    }
    this.scene.tweens.killTweensOf(this.sprite);
    const { x: targetX, y: targetY } = getTargetPos();
    const spot = this._pickSummonSpot(targetX, targetY);
    this._teleportTo(spot.x, spot.y, spot.flip, () => {
      if (!this.sprite?.active) {
        reveal();
        advance();
        return;
      }
      this.sprite.setTint(0xa855f7);
      this.sprite.play(`${NECROMANCER_CONFIG.key}-summon`);
      const summonInfo = NECROMANCER_CONFIG.animFiles.summon;
      const durationMs = Math.ceil((summonInfo.count / summonInfo.rate) * 1000);
      // The Summon artwork only raises its hand/snaps around the midpoint of
      // its frames (the streak shooting out starts around frame 5 of 10) —
      // the beam shouldn't shoot out before that gesture actually happens.
      const beamDelayMs = Math.round(durationMs * 0.45);
      this.scene.time.delayedCall(beamDelayMs, () => {
        if (this.sprite?.active) {
          // Re-read again rather than reusing targetX/Y from above — a
          // relayout can still land during the ~1.4s teleport-out itself.
          // Re-pick and snap the Necromancer's own standing spot too, not
          // just the beam's endpoint: otherwise it stays parked wherever it
          // originally teleported to (now stale) while the beam correctly
          // points at the fighter's new spot, reading as firing from a
          // nonsensical position instead of just "the wrong direction".
          const { x: beamX, y: beamY } = getTargetPos();
          const freshSpot = this._pickSummonSpot(beamX, beamY);
          this.sprite.setPosition(freshSpot.x, freshSpot.y).setFlipX(freshSpot.flip);
          this._castBeam(beamX, beamY, durationMs - beamDelayMs);
        }
      });
      this.scene.time.delayedCall(durationMs, () => {
        if (this.sprite?.active) {
          this.sprite.clearTint();
        }
        reveal();
        this._teleportTo(this.homeAnchor.x, this.homeAnchor.y, false, () => {
          if (this.sprite?.active) {
            this.sprite.play(`${NECROMANCER_CONFIG.key}-idle`);
          }
          advance();
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
      return { x, y, flip: dx > 0 };
    }

    const fallbackDx = offsets[0];
    return { x: targetX + fallbackDx, y: targetY, flip: fallbackDx > 0 };
  }

  /**
   * Plays the Death strip forward to vanish the Necromancer in place,
   * repositions and re-faces it instantly while it's gone, then plays the
   * same strip in reverse to reassemble it there — reusing the asset pack's
   * own death artwork for the teleport rather than a bare alpha fade, the
   * same "forward to leave, reversed to return" convention boss/index.js
   * already uses for the dreadknight's fall/getup.
   *
   * @param {number} x
   * @param {number} y
   * @param {boolean} flip
   * @param {Function} onArrived
   * @return {void}
   */
  _teleportTo(x, y, flip, onArrived) {
    this.scene.tweens.killTweensOf(this.sprite);
    const deathInfo = NECROMANCER_CONFIG.animFiles.death;
    const transitionMs = Math.ceil((deathInfo.count / deathInfo.rate) * 1000);
    this.sprite.play(`${NECROMANCER_CONFIG.key}-vanish`);
    this.scene.time.delayedCall(transitionMs, () => {
      if (!this.sprite?.active) {
        onArrived();
        return;
      }
      this.sprite.setPosition(x, y).setFlipX(flip);
      this._spawnAppearBurst(x, y);
      // The burst leads the reappearance by a beat rather than firing at
      // the exact same instant as the Appear animation.
      const burstLeadMs = 160;
      this.scene.time.delayedCall(burstLeadMs, () => {
        if (!this.sprite?.active) {
          onArrived();
          return;
        }
        this.sprite.play(`${NECROMANCER_CONFIG.key}-appear`);
        this.scene.time.delayedCall(transitionMs, onArrived);
      });
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
   * Reacts to Priest's odd-damage heal flourish (attacks/priest-heal.js):
   * flashes a green heal tint, faces the boss, then bolts it with the same
   * beam style _castBeam already draws for a summon cast — Priest's own
   * projectile/beam are skipped entirely for a heal roll (attacks/blast.js),
   * so this bolt is what actually stands in for "the hit landing" visually;
   * onImpact is always invoked, even when the flourish itself is skipped
   * (Necromancer unavailable, or mid-summon), so the real damage/HP-bar
   * update and kill-shot handling upstream never wait on a bolt that isn't
   * coming.
   *
   * @param {Function|null} [onImpact]
   * @return {void}
   */
  receiveHealAndBoltBoss(onImpact) {
    if (!this.sprite?.active || this.isSummoning) {
      onImpact?.();
      return;
    }
    this.sprite.setTint(0x4ade80);
    this.scene.tweens.add({
      targets: this.sprite,
      scaleX: NECROMANCER_CONFIG.scale * 1.08,
      scaleY: NECROMANCER_CONFIG.scale * 1.08,
      duration: 120,
      yoyo: true,
      ease: 'Sine.easeOut',
    });
    const bossX = this.scene.layout.boss.anchor.x;
    const bossY = this.scene.layout.boss.anchor.y;
    this.sprite.setFlipX(bossX < this.sprite.x);
    const healFlashMs = 160;
    const boltDurationMs = 280;
    this.scene.time.delayedCall(healFlashMs, () => {
      if (!this.sprite?.active) {
        onImpact?.();
        return;
      }
      this.sprite.clearTint();
      this._castBeam(bossX, bossY, boltDurationMs);
      this.scene.time.delayedCall(boltDurationMs, () => {
        // Reuses the same real Attack02-effect art _spawnAppearBurst already
        // plays for its own arrival flourish, now as the actual damage-hit
        // flash landing on the boss instead of a bare beam fade-out.
        this._spawnAppearBurst(bossX, bossY);
        onImpact?.();
      });
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
   * Spawns a one-shot burst at the Necromancer's own upcoming spot, a beat
   * before it reappears there — reuses the Attack02 effect artwork as a
   * "something is about to arrive here" flourish leading the reveal.
   *
   * @param {number} x
   * @param {number} y
   * @return {void}
   */
  _spawnAppearBurst(x, y) {
    const key = `${NECROMANCER_CONFIG.key}-appearBurst`;
    if (!this.scene.anims.exists(key)) {
      return;
    }
    const burst = this.scene.add.sprite(x, y, key).setDepth(2.5).setScale(2.2).setBlendMode(Phaser.BlendModes.ADD);
    burst.play(key);
    burst.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => burst.destroy());
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
   * Registers every Necromancer animation against its own individually-loaded
   * spritesheets, plus the derived vanish/appear pair (the Death strip
   * played forward and reversed) used by the teleport.
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

    const deathInfo = NECROMANCER_CONFIG.animFiles.death;
    const deathTexKey = `${NECROMANCER_CONFIG.key}-death`;
    const vanishKey = `${NECROMANCER_CONFIG.key}-vanish`;
    const appearKey = `${NECROMANCER_CONFIG.key}-appear`;
    if (!this.scene.anims.exists(vanishKey)) {
      this.scene.anims.create({
        key: vanishKey,
        frames: this.scene.anims.generateFrameNumbers(deathTexKey, { start: 0, end: deathInfo.count - 1 }),
        frameRate: deathInfo.rate,
        repeat: 0,
      });
    }
    if (!this.scene.anims.exists(appearKey)) {
      const reversed = Array.from({ length: deathInfo.count }, (_, i) => deathInfo.count - 1 - i);
      this.scene.anims.create({
        key: appearKey,
        frames: this.scene.anims.generateFrameNumbers(deathTexKey, { frames: reversed }),
        frameRate: deathInfo.rate,
        repeat: 0,
      });
    }
  }
}
