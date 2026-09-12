import { FIGHTER_TYPES } from '@battlefield/config.js';
import { getAttackLabel } from './attack-labels.js';

/**
 * Builds the list of real atlas frame names an animation strip is made of,
 * e.g. buildFrameNames('orc-idle-', 6) -> ['orc-idle-0', ..., 'orc-idle-5'].
 * Pass reverse: true to count down instead — used for a derived Summon,
 * which is registered as the source strip's frames played backwards, so its
 * thumbnail must cycle through them in that same order.
 *
 * @param {string} prefix - e.g. 'orc-idle-'
 * @param {number} count - frame count
 * @param {{reverse?: boolean}} [options]
 * @return {Array<string>}
 */
function buildFrameNames(prefix, count, { reverse = false } = {}) {
  return Array.from({ length: count }, (_, i) => `${prefix}${reverse ? count - 1 - i : i}`);
}

/**
 * Builds the full skill list (idle, walk, one entry per attacks[], death)
 * for a character key, data-driven off FIGHTER_TYPES — never hardcoded to
 * a fixed attack count, since attacks[] is 2 entries for some characters
 * and 3 for others.
 *
 * @param {string} characterKey - a FighterCharacter enum value
 * @return {{key: string, attackType: string, skills: Array<object>}|null}
 */
export function buildMoveset(characterKey) {
  const ftype = FIGHTER_TYPES.find(ft => ft.key === characterKey);
  if (!ftype) {
    return null;
  }

  const skills = [
    {
      id: 'idle', label: '◇ Idle', animKey: `${ftype.key}-idle`, loop: true, effectAnimKey: null, durationMs: null,
      frames: ftype.animations.idle.frames, rate: ftype.animations.idle.rate,
      frameNames: buildFrameNames(`${ftype.key}-idle-`, ftype.animations.idle.frames),
    },
    {
      id: 'walk', label: '🏃 Walk', animKey: `${ftype.key}-walk`, loop: true, effectAnimKey: null, durationMs: null,
      frames: ftype.animations.walk.frames, rate: ftype.animations.walk.rate,
      frameNames: buildFrameNames(`${ftype.key}-walk-`, ftype.animations.walk.frames),
    },
    ...ftype.attacks.map((atk, i) => ({
      id: `attack${i + 1}`,
      label: getAttackLabel(ftype.attackType, i),
      animKey: `${ftype.key}-attack${i + 1}`,
      effectAnimKey: atk.effectFrames ? `${ftype.key}-effect${i + 1}` : null,
      loop: false,
      durationMs: Math.round((atk.frames / atk.rate) * 1000),
      frames: atk.frames,
      rate: atk.rate,
      frameNames: buildFrameNames(`${ftype.key}-attack${i + 1}-`, atk.frames),
    })),
    {
      id: 'death',
      label: '💀 Death',
      animKey: `${ftype.key}-death`,
      loop: false,
      effectAnimKey: null,
      durationMs: Math.round((ftype.animations.death.frames / ftype.animations.death.rate) * 1000),
      frames: ftype.animations.death.frames,
      rate: ftype.animations.death.rate,
      frameNames: buildFrameNames(`${ftype.key}-death-`, ftype.animations.death.frames),
    },
  ];

  // Only the skeleton family ships dedicated Summon (rise-from-ground) art;
  // every other character gets one derived by fighter/animations.js from its
  // own Death strip played in reverse, so it carries Death's frame count/rate
  // and — critically — frameNames pointing at the real orc-death-N atlas
  // frames (reversed), not fake orc-summon-N frames that were never packed.
  // Appended last, not first, so callers that default to skills[0] as idle
  // (the character-preview scene's initial selection) are unaffected.
  const hasDedicatedSummon = Boolean(ftype.animations.summon);
  const summonSource = ftype.animations.summon ?? ftype.animations.death;
  skills.push({
    id: 'summon',
    label: '✨ Summon',
    animKey: `${ftype.key}-summon`,
    loop: false,
    effectAnimKey: null,
    durationMs: Math.round((summonSource.frames / summonSource.rate) * 1000),
    frames: summonSource.frames,
    rate: summonSource.rate,
    frameNames: hasDedicatedSummon
      ? buildFrameNames(`${ftype.key}-summon-`, summonSource.frames)
      : buildFrameNames(`${ftype.key}-death-`, summonSource.frames, { reverse: true }),
  });

  return { key: ftype.key, attackType: ftype.attackType, skills };
}
