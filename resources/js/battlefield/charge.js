import { TIMINGS } from '@battlefield/config.js';
import { AnimState } from '@battlefield/constants.js';
import { heelToLocal } from '@battlefield/shared/heel.js';
import { nextBurst } from '@battlefield/shared/sparks.js';

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
   * Returns the tint weighting for a fighter's heel sparks: white-hot,
   * twice the brightest charge colour, then the two next-brightest —
   * never the old hard-coded green trail tints.
   *
   * @param {number[]} chargeColors 5 hex ints, dimmest to brightest
   * @return {number[]}
   */
  static sparkTints(chargeColors) {
    const [, , c2, c3, c4] = chargeColors;
    return [0xffffff, c4, c4, c3, c2];
  }

  /**
   * Handles a fighter-charging event: creates or updates charge visuals.
   *
   * @param {{ user_id: number, activity?: string, slack_handle?: string, avatar_url?: string, character?: string, position?: {x: number, y: number}|null }} payload
   * @return {void}
   */
  handleCharging(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    if (!this.scene.fighters.has(payload.user_id)) {
      // Synthesizes a client-side rejoin for a fighter this client doesn't
      // have (e.g. its FighterJoined was missed, or it was previously swept
      // idle) — carries the saved position so it doesn't snap to the grid.
      this.scene.fighter?.handleFighterJoined({
        user_id: payload.user_id,
        slack_handle: payload.slack_handle,
        avatar_url: payload.avatar_url,
        character: payload.character ?? null,
        position: payload.position ?? null,
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
    const ring = this.createChargingRing(fighter);
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
    const entry = { ring, breath, bubble: null, activity: payload.activity ?? '', sparks: { wait: 0 } };
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
   * Emits a burst of grinding sparks off a charging fighter's rear heel,
   * called every frame from `scene.update()` for each currently-charging
   * fighter. Reduced motion (`scene.reducedMotion`, read once at boot):
   * no sparks at all — the charging ring alone stays the "charging" cue.
   *
   * @param {object} fighter
   * @param {number} dt seconds since the last frame
   * @param {function(): number} [rnd] 0..1, injectable for tests
   * @return {void}
   */
  emitFor(fighter, dt, rnd = Math.random) {
    if (this.scene.reducedMotion) {
      return;
    }
    const entry = this.scene.charges.get(fighter.id);
    if (!entry?.sparks || !this.scene.sparks) {
      return;
    }
    const burst = nextBurst({ wait: entry.sparks.wait, moving: fighter.waypointMoving }, dt, rnd);
    entry.sparks.wait = burst.wait;
    if (!burst.count) {
      return;
    }
    const facing = fighter.body?.flipX ? -1 : 1;
    const heel = fighter.ftype?.heel;
    if (!heel) {
      return;
    }
    const { dx, dy } = heelToLocal(heel, fighter.body?.scaleX ?? 1, facing);
    this.scene._sparkFacing = facing;
    this.scene._sparkPower = fighter.waypointMoving ? 1.35 : 1;
    const tints = Charge.sparkTints(Charge.chargeParticleColors(fighter.ftype));
    for (let i = 0; i < burst.count; i++) {
      this.scene._sparkTint = tints[Math.floor(rnd() * tints.length)];
      this.scene.sparks.emitParticleAt(fighter.sprite.x + dx, fighter.sprite.y + dy);
    }
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
   * Handles the fighter-charge-cleared event: clears charging visuals only,
   * without removing the fighter from the battlefield — the server fires
   * this when a turn ends with no damage dealt (a very common, non-idle
   * case), as opposed to `FighterIdled` which means the fighter has
   * genuinely been idle past the sweep window and should be removed.
   *
   * @param {{ user_id: number|string }} payload
   * @return {void}
   */
  handleChargeCleared(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    this.clearCharge(payload.user_id);
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
