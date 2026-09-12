import { FIGHTER_TYPES } from '@battlefield/config.js';
import { AnimState, TextureKey } from '@battlefield/constants.js';

/**
 * Registers every Phaser animation (idle/walk/death, attackN/effectN) for a
 * single FIGHTER_TYPES entry against the shared 'fighters' atlas. Idempotent
 * via anims.exists() — safe to call repeatedly for the same character.
 *
 * @param {Phaser.Scene} scene
 * @param {object} ft - one entry from FIGHTER_TYPES
 * @return {void}
 */
export function registerFighterAnimations(scene, ft) {
  for (const [state, anim] of Object.entries(ft.animations)) {
    const animKey = `${ft.key}-${state}`;
    if (!scene.anims.exists(animKey)) {
      scene.anims.create({
        key: animKey,
        frames: scene.anims.generateFrameNames(TextureKey.FIGHTERS, {
          prefix: `${ft.key}-${state}-`,
          start: 0,
          end: anim.frames - 1,
        }),
        frameRate: anim.rate,
        repeat: (state === AnimState.IDLE || state === AnimState.WALK) ? -1 : 0,
      });
    }
  }
  for (let i = 0; i < (ft.attacks?.length ?? 0); i++) {
    const atk = ft.attacks[i];
    const atkKey = `${ft.key}-attack${i + 1}`;
    const effKey = `${ft.key}-effect${i + 1}`;
    if (!scene.anims.exists(atkKey)) {
      scene.anims.create({
        key: atkKey,
        frames: scene.anims.generateFrameNames(TextureKey.FIGHTERS, {
          prefix: `${ft.key}-attack${i + 1}-`,
          start: 0,
          end: atk.frames - 1,
        }),
        frameRate: atk.rate,
        repeat: 0,
      });
    }
    if (atk.effectFrames && !scene.anims.exists(effKey)) {
      scene.anims.create({
        key: effKey,
        frames: scene.anims.generateFrameNames(TextureKey.FIGHTERS, {
          prefix: `${ft.key}-effect${i + 1}-`,
          start: 0,
          end: atk.effectFrames - 1,
        }),
        frameRate: atk.rate,
        repeat: 0,
      });
    }
  }
  // Only the skeleton family ships dedicated Summon (rise-from-ground) art.
  // Everyone else gets one derived from their own Death strip played in
  // reverse — the same "forward to leave, reversed to return" convention
  // boss/index.js uses for the dreadknight's fall/getup and necromancer.js
  // uses for its vanish/appear teleport.
  const summonKey = `${ft.key}-summon`;
  if (!ft.animations.summon && !scene.anims.exists(summonKey)) {
    const deathInfo = ft.animations.death;
    const reversed = Array.from({ length: deathInfo.frames }, (_, i) => deathInfo.frames - 1 - i);
    scene.anims.create({
      key: summonKey,
      frames: scene.anims.generateFrameNames(TextureKey.FIGHTERS, {
        prefix: `${ft.key}-death-`,
        frames: reversed,
      }),
      frameRate: deathInfo.rate,
      repeat: 0,
    });
  }
}

/**
 * Registers animations for every FIGHTER_TYPES entry — any live battlefield
 * fighter can be reassigned any character, so the main scene needs all 15
 * registered up front.
 *
 * @param {Phaser.Scene} scene
 * @return {void}
 */
export function registerAllFighterAnimations(scene) {
  for (const ft of FIGHTER_TYPES) {
    registerFighterAnimations(scene, ft);
  }
}
