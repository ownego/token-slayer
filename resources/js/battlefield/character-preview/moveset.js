import { FIGHTER_TYPES } from '@battlefield/config.js';
import { getAttackLabel } from './attack-labels.js';

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
    { id: 'idle', label: '◇ Idle', animKey: `${ftype.key}-idle`, loop: true, effectAnimKey: null, durationMs: null, frames: ftype.animations.idle.frames, rate: ftype.animations.idle.rate },
    { id: 'walk', label: '🏃 Walk', animKey: `${ftype.key}-walk`, loop: true, effectAnimKey: null, durationMs: null, frames: ftype.animations.walk.frames, rate: ftype.animations.walk.rate },
    ...ftype.attacks.map((atk, i) => ({
      id: `attack${i + 1}`,
      label: getAttackLabel(ftype.attackType, i),
      animKey: `${ftype.key}-attack${i + 1}`,
      effectAnimKey: atk.effectFrames ? `${ftype.key}-effect${i + 1}` : null,
      loop: false,
      durationMs: Math.round((atk.frames / atk.rate) * 1000),
      frames: atk.frames,
      rate: atk.rate,
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
    },
  ];

  return { key: ftype.key, attackType: ftype.attackType, skills };
}
