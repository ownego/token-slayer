import { describe, expect, test } from 'vitest';
import { FIGHTER_TYPES } from '@battlefield/config.js';
import { AttackType } from '@battlefield/constants.js';
import { buildMoveset } from '@battlefield/character-preview/moveset.js';

describe('buildMoveset', () => {
  test('returns null for an unknown character key', () => {
    expect(buildMoveset('not-a-real-character')).toBeNull();
  });

  test('a 2-attack character (orc) exposes exactly 5 skills: idle, walk, attack1, attack2, death', () => {
    const moveset = buildMoveset('orc');

    expect(moveset.key).toBe('orc');
    expect(moveset.attackType).toBe(AttackType.SLASH);
    expect(moveset.skills.map(s => s.id)).toEqual(['idle', 'walk', 'attack1', 'attack2', 'death']);
  });

  test('a 3-attack character (soldier) exposes exactly 6 skills, including attack3', () => {
    const moveset = buildMoveset('soldier');

    expect(moveset.skills.map(s => s.id)).toEqual(['idle', 'walk', 'attack1', 'attack2', 'attack3', 'death']);
  });

  test('idle and walk loop and have no effect/duration', () => {
    const moveset = buildMoveset('soldier');
    const idle = moveset.skills.find(s => s.id === 'idle');
    const walk = moveset.skills.find(s => s.id === 'walk');

    expect(idle).toMatchObject({ animKey: 'soldier-idle', loop: true, effectAnimKey: null });
    expect(walk).toMatchObject({ animKey: 'soldier-walk', loop: true, effectAnimKey: null });
  });

  test('attack skills carry the animKey, effectAnimKey, label, and computed durationMs', () => {
    const moveset = buildMoveset('soldier');
    const attack1 = moveset.skills.find(s => s.id === 'attack1');

    // soldier.attacks[0] = { frames: 6, rate: 12, effectFrames: 6 } -> 500ms
    expect(attack1).toMatchObject({
      animKey: 'soldier-attack1',
      effectAnimKey: 'soldier-effect1',
      label: '⚔ Slash Combo',
      loop: false,
      durationMs: 500,
    });
  });

  test('death has no effect and a computed durationMs from animations.death', () => {
    const moveset = buildMoveset('soldier');
    const death = moveset.skills.find(s => s.id === 'death');

    // animations.death = { frames: 4, rate: 6 } -> 667ms
    expect(death).toMatchObject({ animKey: 'soldier-death', loop: false, effectAnimKey: null, durationMs: 667 });
  });

  test('every FIGHTER_TYPES character produces a moveset whose attack count matches attacks.length', () => {
    for (const ft of FIGHTER_TYPES) {
      const moveset = buildMoveset(ft.key);
      const attackSkills = moveset.skills.filter(s => s.id.startsWith('attack'));
      expect(attackSkills).toHaveLength(ft.attacks.length);
    }
  });
});
