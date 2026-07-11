import Phaser from 'phaser';
import { TIMINGS } from '@battlefield/config.js';
import { AnimState, TextureKey } from '@battlefield/constants.js';

/** Manages charge rings, trails, fire emitters, and activity bubbles for charging fighters. */
export class Charge {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
  }

  /**
   * Returns per-type particle tint colors for the charge fire emitter.
   *
   * @param {{ chargeColors?: number[] }|null} ftype  fighter type config object
   * @return {number[]}
   */
  static chargeParticleColors(ftype) {
    return ftype?.chargeColors ?? [0x991100, 0xcc3300, 0xdd6600, 0xee9900, 0xffbb00];
  }

  /**
   * Handles a fighter-charging event: creates or updates charge visuals.
   *
   * @param {{ user_id: number, activity?: string, slack_handle?: string, avatar_url?: string, character?: string }} payload
   * @return {void}
   */
  handleCharging(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    if (!this.scene.fighters.has(payload.user_id)) {
      this.scene.fighter?.handleFighterJoined({
        user_id: payload.user_id,
        slack_handle: payload.slack_handle,
        avatar_url: payload.avatar_url,
        character: payload.character ?? null,
      });
    }
    const fighter = this.scene.fighters.get(payload.user_id);
    if (!fighter) {
      return;
    }
    const existing = this.scene.charges.get(payload.user_id);
    if (existing) {
      existing.activity = payload.activity ?? '';
      if (this.scene.bubble?.fightersAllowBubbles?.()) {
        this.scene.bubble.updateActivityBubble(existing, fighter, payload.activity);
      }
      return;
    }
    if (fighter.body && fighter.animState !== AnimState.ATTACK) {
      fighter.animState = AnimState.WALK;
      fighter.body.setFlipX(fighter.pos.x > this.scene.layout.boss.anchor.x);
      fighter.body.play(fighter.ftype.key + '-walk');
    }
    const localFootY = Math.round(fighter.displaySize / 3);
    const { fireEmitter, fireEmbers } = this.spawnChargeFireEmitters(fighter.ftype, 0, localFootY, fighter.displaySize);
    fighter.sprite.addAt(fireEmbers, 0);
    fighter.sprite.addAt(fireEmitter, 0);
    const ring  = this.createChargingRing(fighter);
    const trail = this.createChargingTrail(fighter);
    fighter.sprite.addAt(ring, 0);
    const avSize = fighter.avatarSize ?? Math.round(fighter.displaySize * 0.85);
    const breath = fighter.head ? this.scene.tweens.add({
      targets: fighter.head,
      displayWidth: avSize * 1.06,
      displayHeight: avSize * 1.06,
      duration: TIMINGS.chargeRingPulseMs / 2,
      yoyo: true,
      repeat: -1,
      ease: 'Sine.easeInOut',
    }) : null;
    const entry = { ring, trail, fireEmitter, fireEmbers, breath, bubble: null, activity: payload.activity ?? '' };
    if (this.scene.bubble?.fightersAllowBubbles?.()) {
      this.scene.bubble.updateActivityBubble(entry, fighter, payload.activity);
    }
    this.scene.charges.set(payload.user_id, entry);
  }

  /**
   * Creates the pulsing cyan ring around a fighter's avatar.
   *
   * @param {object} fighter
   * @return {Phaser.GameObjects.Graphics}
   */
  createChargingRing(fighter) {
    const avatarRelY = fighter.head?.y ?? 0;
    const avR = (fighter.avatarSize ?? Math.round(fighter.displaySize * 0.85)) / 2;
    const r   = Math.round(avR + Math.max(4, fighter.displaySize * 0.08));
    const g = this.scene.add.graphics();
    g.lineStyle(2, 0x22d3ee, 1);
    g.strokeCircle(0, 0, r);
    g.setPosition(0, avatarRelY);
    this.scene.tweens.add({
      targets: g,
      alpha: { from: 0.9, to: 0.15 },
      scaleX: { from: 1.0, to: 1.18 },
      scaleY: { from: 1.0, to: 1.18 },
      duration: TIMINGS.chargeRingPulseMs,
      ease: 'Sine.easeInOut',
      yoyo: true,
      repeat: -1,
    });
    return g;
  }

  /**
   * Creates the speed-trail particle emitter for a charging fighter.
   *
   * @param {object} fighter
   * @return {Phaser.GameObjects.Particles.ParticleEmitter}
   */
  createChargingTrail(fighter) {
    const charBot    = Math.round(fighter.displaySize / 3);
    const towardBoss = fighter.pos.x <= this.scene.layout.boss.anchor.x ? 1 : -1;
    const emitX      = fighter.pos.x - towardBoss * Math.round(fighter.displaySize * 0.18);
    const emitY      = fighter.pos.y + charBot - Math.round(fighter.displaySize * 0.12);
    const emitter    = this.scene.add.particles(emitX, emitY, TextureKey.SPARK, {
      tint:      { onEmit: () => Phaser.Math.RND.pick([0x4ade80, 0x86efac, 0xa3e635, 0xffffff]) },
      scale:     { start: 0.5, end: 0 },
      alpha:     { start: 0.65, end: 0 },
      speedX:    { min: -towardBoss * 80, max: -towardBoss * 20 },
      speedY:    { min: -10, max: 22 },
      lifespan:  { min: 130, max: 230 },
      frequency: 45,
      quantity:  2,
      blendMode: Phaser.BlendModes.ADD,
    });
    emitter.setDepth(1);
    return emitter;
  }

  /**
   * Creates the fire and ember particle emitters used inside the fighter's container.
   *
   * @param {object} ftype  fighter type config object (has .key string)
   * @param {number} localX  x offset inside container
   * @param {number} localFootY  y offset inside container
   * @param {number} displaySize  logical display size in px
   * @return {{ fireEmitter: Phaser.GameObjects.Particles.ParticleEmitter, fireEmbers: Phaser.GameObjects.Particles.ParticleEmitter }}
   */
  spawnChargeFireEmitters(ftype, localX, localFootY, displaySize) {
    const fireColors = Charge.chargeParticleColors(ftype);
    const ps = displaySize * 0.018;
    const fireEmitter = this.scene.add.particles(localX, localFootY, TextureKey.SPARK, {
      tint:      { onEmit: () => Phaser.Math.RND.pick(fireColors) },
      rotate:    { min: 0, max: 360 },
      scale:     { start: ps, end: 0 },
      alpha:     { start: 0.55, end: 0 },
      speedX:    { min: -180, max: 180 },
      speedY:    { min: -90, max: 20 },
      lifespan:  { min: 280, max: 480 },
      frequency: 22,
      quantity:  3,
      gravityY:  80,
      blendMode: Phaser.BlendModes.ADD,
    });
    const fireEmbers = this.scene.add.particles(localX, localFootY, TextureKey.SPARK, {
      tint:      { onEmit: () => Phaser.Math.RND.pick(fireColors) },
      rotate:    { min: 0, max: 360 },
      scale:     { start: ps * 0.5, end: 0 },
      alpha:     { start: 0.4, end: 0 },
      speedX:    { min: -240, max: 240 },
      speedY:    { min: -60, max: 30 },
      lifespan:  { min: 200, max: 400 },
      frequency: 28,
      quantity:  2,
      gravityY:  60,
      blendMode: Phaser.BlendModes.ADD,
    });
    return { fireEmitter, fireEmbers };
  }

  /**
   * Tears down all charge visuals for a single fighter.
   *
   * @param {number} userId
   * @return {void}
   */
  clearCharge(userId) {
    const entry = this.scene.charges.get(userId);
    if (!entry) {
      return;
    }
    if (entry.breath) {
      entry.breath.stop();
      const fighter = this.scene.fighters.get(userId);
      if (fighter?.head?.scene) {
        const av = fighter.avatarSize ?? Math.round(fighter.displaySize * 0.85);
        fighter.head.setDisplaySize(av, av);
      }
    }
    if (entry.ring?.scene) {
      this.scene.tweens.killTweensOf(entry.ring);
      const ring = entry.ring;
      this.scene.tweens.add({
        targets: ring,
        alpha: 0,
        duration: 200,
        onComplete: () => { if (ring.scene) ring.destroy(); },
      });
    }
    if (entry.trail?.scene) {
      entry.trail.stop();
      this.scene.time.delayedCall(250, () => { if (entry.trail?.scene) entry.trail.destroy(); });
    }
    if (entry.fireEmitter?.scene) {
      entry.fireEmitter.stop();
      this.scene.time.delayedCall(500, () => { if (entry.fireEmitter?.scene) entry.fireEmitter.destroy(); });
    }
    if (entry.fireEmbers?.scene) {
      entry.fireEmbers.stop();
      this.scene.time.delayedCall(500, () => { if (entry.fireEmbers?.scene) entry.fireEmbers.destroy(); });
    }
    if (entry.bubble) {
      entry.bubble.destroy();
      entry.bubble = null;
    }
    this.scene.charges.delete(userId);
    const fighter = this.scene.fighters.get(userId);
    if (fighter?.body && fighter.animState !== AnimState.ATTACK) {
      fighter.animState = AnimState.IDLE;
      fighter.body.setFlipX(false);
      fighter.body.play(fighter.ftype.key + '-idle');
    }
  }

  /**
   * Clears charge visuals for every currently charging fighter.
   *
   * @return {void}
   */
  clearAllCharges() {
    for (const userId of [...this.scene.charges.keys()]) {
      this.clearCharge(userId);
    }
  }
}
