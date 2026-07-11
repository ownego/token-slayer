import Phaser from 'phaser';
import { BossPhase, DreadknightAttack } from '@battlefield/constants.js';
import { applyStunEffect } from './stun.js';

const DREADKNIGHT_KEY = 'boss-abyssal-dreadknight';

/** @type {Array<{moveAnim: string, attack: string|null, breathAfter: number, isSlash: boolean}>} */
const TURN_SEQUENCE = [
  { moveAnim: 'move',           attack: null,                        breathAfter: 4, isSlash: false },
  { moveAnim: 'run',            attack: null,                        breathAfter: 6, isSlash: false },
  { moveAnim: 'jump',           attack: DreadknightAttack.SLAM,      breathAfter: 2, isSlash: false },
  { moveAnim: DreadknightAttack.DASH, attack: DreadknightAttack.SLASH_LOW, breathAfter: 2, isSlash: true },
  { moveAnim: DreadknightAttack.DASH, attack: DreadknightAttack.THRUST,    breathAfter: 2, isSlash: true },
  { moveAnim: DreadknightAttack.DASH, attack: DreadknightAttack.SPIN,      breathAfter: 2, isSlash: true },
];

/**
 * Returns the turn step config for the given turn index (0–5, wraps every 6).
 *
 * @param {number} turnIndex
 * @return {{ moveAnim: string, attack: string|null, breathAfter: number, isSlash: boolean }}
 */
export function getDreadknightTurnStep(turnIndex) {
  return TURN_SEQUENCE[turnIndex % 6];
}

/**
 * Returns true if the boss key is abyssal-dreadknight.
 *
 * @param {string} bossKey
 * @return {boolean}
 */
export function isDreadknight(bossKey) {
  return bossKey === DREADKNIGHT_KEY;
}

/**
 * Starts the abyssal-dreadknight deterministic turn-based patrol on the scene.
 *
 * @param {Phaser.Scene} scene
 * @param {object} boss  Boss manager instance (owns bossPatrolPhase, bossIdleRepeatListener)
 * @return {void}
 */
export function startDreadknightPatrol(scene, boss) {
  const sprite = scene.bossSprite;
  const anchorX = scene.layout.boss.anchor.x;
  const anchorY = scene.layout.boss.anchor.y;
  const range = 120;
  const idleKey = `${DREADKNIGHT_KEY}-idle`;
  let turnIndex = 0;
  let goingRight = false;

  const clearRepeatListener = () => {
    if (boss.bossIdleRepeatListener) {
      sprite.off(Phaser.Animations.Events.ANIMATION_REPEAT, boss.bossIdleRepeatListener);
      boss.bossIdleRepeatListener = null;
    }
  };

  const doBreath = (count, onDone) => {
    if (!sprite?.active) return;
    sprite.play(idleKey);
    let done = 0;
    const onRepeat = () => {
      if (sprite.anims.currentAnim?.key !== idleKey) return;
      done++;
      if (done >= count) {
        clearRepeatListener();
        onDone();
      }
    };
    boss.bossIdleRepeatListener = onRepeat;
    sprite.on(Phaser.Animations.Events.ANIMATION_REPEAT, onRepeat);
  };

  const SLASH_FX_RANGE = 480;
  const SLAM_FX_RADIUS = 100;

  // Stun fighters in the directional path of the slash wind (flipX=true → boss facing right).
  const hitFightersSlash = (bossX, flipX) => {
    const dir = flipX ? 1 : -1;
    const slashY = anchorY - 15;
    for (const entry of scene.fighters.values()) {
      if (!entry.sprite?.active) continue;
      const dx = (entry.sprite.x - bossX) * dir;
      const dy = Math.abs(entry.sprite.y - slashY);
      if (dx >= -20 && dx <= SLASH_FX_RANGE && dy <= 100) {
        applyStunEffect(scene, entry);
      }
    }
  };

  // Stun fighters near the slam impact point.
  const hitFightersSlam = (impactX) => {
    for (const entry of scene.fighters.values()) {
      if (!entry.sprite?.active) continue;
      if (Math.abs(entry.sprite.x - impactX) <= SLAM_FX_RADIUS) {
        applyStunEffect(scene, entry);
      }
    }
  };

  const spawnSlashFx = (bossX, flipX) => {
    const fxKey = `${DREADKNIGHT_KEY}-fx-slash`;
    const animKey = `${DREADKNIGHT_KEY}-fx-slash`;
    if (!scene.anims.exists(animKey)) return;
    // attack6 naturally faces left, so flipX=true means the boss is facing right
    const dir = flipX ? 1 : -1;
    const startX = bossX + dir * 40;
    const targetX = bossX + dir * SLASH_FX_RANGE;
    const fx = scene.add.sprite(startX, anchorY - 15, fxKey).setScale(1.5).setDepth(6).setFlipX(flipX);
    fx.play(animKey);
    scene.tweens.add({ targets: fx, x: targetX, duration: 380, ease: 'Sine.easeIn' });
    fx.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => fx.destroy());
  };

  // Returns the impact X used for stun range.
  const spawnSlamFx = (bossX) => {
    const fxKey = `${DREADKNIGHT_KEY}-fx-slam`;
    const animKey = `${DREADKNIGHT_KEY}-fx-slam`;
    if (!scene.anims.exists(animKey)) return bossX;
    let closestEntry = null;
    let closestDist = Infinity;
    for (const entry of scene.fighters.values()) {
      if (!entry.sprite?.active) continue;
      const dist = Math.abs(entry.sprite.x - bossX);
      if (dist < closestDist) { closestDist = dist; closestEntry = entry; }
    }
    const tx = closestEntry ? closestEntry.sprite.x : bossX;
    const ty = closestEntry ? closestEntry.sprite.y - 20 : anchorY;
    const fx = scene.add.sprite(tx, ty, fxKey).setScale(1.5).setDepth(6);
    fx.play(animKey);
    fx.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => fx.destroy());
    return tx;
  };

  const doStep = () => {
    if (!sprite?.active) return;
    clearRepeatListener();

    const step = getDreadknightTurnStep(turnIndex);
    turnIndex = (turnIndex + 1) % 6;
    goingRight = !goingRight;

    const targetX = goingRight ? anchorX + range : anchorX - range;
    sprite.setFlipX(!goingRight);
    boss.bossPatrolPhase = BossPhase.MOVE;

    const onArrived = () => {
      if (!sprite?.active) return;
      boss.bossPatrolPhase = BossPhase.IDLE;
      if (step.moveAnim !== DreadknightAttack.DASH) {
        sprite.setFlipX(goingRight);
      }

      if (!step.attack) {
        doBreath(step.breathAfter, doStep);
        return;
      }

      // When slamming after a jump, skip the windup frames so the impact lands immediately on touchdown.
      const attackKey = (step.moveAnim === 'jump' && step.attack === DreadknightAttack.SLAM)
        ? `${DREADKNIGHT_KEY}-slam-impact`
        : `${DREADKNIGHT_KEY}-${step.attack}`;
      if (!scene.anims.exists(attackKey)) {
        doBreath(step.breathAfter, doStep);
        return;
      }

      sprite.play(attackKey);
      sprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        if (!sprite?.active) return;
        if (step.isSlash) {
          spawnSlashFx(sprite.x, sprite.flipX);
          hitFightersSlash(sprite.x, sprite.flipX);
        } else {
          const impactX = spawnSlamFx(sprite.x);
          hitFightersSlam(impactX);
        }
        doBreath(step.breathAfter, doStep);
      });
    };

    if (step.moveAnim === 'jump') {
      const jumpKey = `${DREADKNIGHT_KEY}-jump`;
      if (scene.anims.exists(jumpKey)) sprite.play(jumpKey);
      scene.tweens.add({
        targets: sprite,
        x: targetX,
        y: anchorY - 45,
        duration: 600,
        ease: 'Sine.easeOut',
        onComplete: () => {
          if (!sprite?.active) return;
          scene.tweens.add({
            targets: sprite,
            y: anchorY,
            duration: 400,
            ease: 'Bounce.easeOut',
            onComplete: onArrived,
          });
        },
      });
    } else if (step.moveAnim === DreadknightAttack.DASH) {
      const dashKey = `${DREADKNIGHT_KEY}-${DreadknightAttack.DASH}`;
      sprite.setFlipX(goingRight);
      if (scene.anims.exists(dashKey)) sprite.play(dashKey);
      scene.tweens.add({
        targets: sprite,
        x: targetX,
        duration: 350,
        ease: 'Quad.easeIn',
        onComplete: onArrived,
      });
    } else {
      const moveKey = `${DREADKNIGHT_KEY}-${step.moveAnim}`;
      if (scene.anims.exists(moveKey)) sprite.play(moveKey);
      const duration = step.moveAnim === 'run' ? 1100 : 1800;
      scene.tweens.add({
        targets: sprite,
        x: targetX,
        duration,
        ease: 'Sine.easeInOut',
        onComplete: onArrived,
      });
    }
  };

  sprite.x = anchorX - range;
  sprite.setFlipX(false);
  doStep();
}
