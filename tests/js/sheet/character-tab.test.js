import { describe, expect, test } from 'vitest';
import { filterRoster } from '@battlefield/sheet/character-tab.js';

describe('filterRoster', () => {
  const roster = [
    { key: 'soldier', attackType: 'slash' },
    { key: 'archer', attackType: 'arrow' },
  ];

  test('returns every entry for the "all" filter (empty string)', () => {
    expect(filterRoster(roster, '')).toEqual(roster);
  });

  test('returns only entries matching the given attack type', () => {
    expect(filterRoster(roster, 'arrow')).toEqual([{ key: 'archer', attackType: 'arrow' }]);
  });

  test('returns an empty array for an attack type nothing matches', () => {
    expect(filterRoster(roster, 'blast')).toEqual([]);
  });
});
