import { describe, test, expect } from 'vitest';
import { FIGHTER_TYPES } from '@battlefield/config.js';
import { chargeAnim } from '@battlefield/fighter/charge-anim.js';

const type = key => FIGHTER_TYPES.find(ft => ft.key === key);

describe('chargeAnim', () => {
  test('a flying hero stands on its hover while its player works: it only flies when it moves', () => {
    expect(chargeAnim(type('iron-man'))).toBe('idle');
    expect(chargeAnim(type('superman'))).toBe('idle');
  });

  test('every other fighter keeps walking in place while its player works', () => {
    expect(chargeAnim(type('soldier'))).toBe('walk');
    expect(chargeAnim(type('captain-america'))).toBe('walk');
    expect(chargeAnim({ key: 'custom-1-abc', custom: true })).toBe('walk');
  });

  test('only Iron Man and Superman fly', () => {
    expect(FIGHTER_TYPES.filter(ft => ft.flies).map(ft => ft.key)).toEqual(['iron-man', 'superman']);
  });
});
