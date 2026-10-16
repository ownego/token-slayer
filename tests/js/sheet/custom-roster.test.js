import { describe, expect, test } from 'vitest';
import { CUSTOM_BOX, CUSTOM_GROUP, CUSTOM_KEY, customRosterEntry, groupOf, matchesChip, registerCustomRoster } from '@battlefield/sheet/custom-roster.js';
import { stripUrl } from '@battlefield/sheet/sheet-roster.js';

const WIRE = {
  version: 'abc123def456',
  url_base: '/storage/custom-characters/7/abc123def456',
  animations: {
    idle: { frames: 6, rate: 8 },
    walk: { frames: 8, rate: 10 },
    attack: { frames: 6, rate: 12 },
    death: { frames: 3, rate: 6 },
  },
};

describe('customRosterEntry', () => {
  const entry = customRosterEntry(WIRE);

  test('is a roster-shaped fighter named custom, fighting as a slasher', () => {
    expect(entry).toMatchObject({ key: CUSTOM_KEY, type: 'SLASH' });
    expect(entry.accent).toMatch(/^#[0-9a-f]{6}$/i);
  });

  test('points each animation at its served strip, with its frame count and rate', () => {
    expect(entry.anims.idle).toEqual({ frames: 6, rate: 8, file: '/storage/custom-characters/7/abc123def456/idle.png' });
    expect(entry.anims.walk.frames).toBe(8);
    expect(entry.anims.death.file).toBe('/storage/custom-characters/7/abc123def456/death.png');
  });

  test('has one attack, named like the roster\'s first, and no effect strip', () => {
    expect(entry.anims.attack1).toEqual({ frames: 6, rate: 12, file: '/storage/custom-characters/7/abc123def456/attack.png' });
    expect(Object.keys(entry.anims).filter(name => name.startsWith('effect'))).toEqual([]);
    expect(entry.anims.attack).toBeUndefined();
  });

  test('is null for a payload that is not a whole custom character', () => {
    expect(customRosterEntry(null)).toBeNull();
    expect(customRosterEntry({ version: 'x' })).toBeNull();
  });

  test('belongs to the custom group and carries its author, null when none is sent', () => {
    expect(entry.group).toBe(CUSTOM_GROUP);
    expect(entry.author).toBeNull();
    expect(customRosterEntry({ ...WIRE, author: '  tungot.work ' }).author).toBe('tungot.work');
    expect(customRosterEntry({ ...WIRE, author: 42 }).author).toBeNull();
  });
});

describe('filter groups', () => {
  test('an entry is filtered under its own group, else its attack style', () => {
    expect(groupOf({ key: 'custom', type: 'SLASH', group: CUSTOM_GROUP })).toBe('CUSTOM');
    expect(groupOf({ key: 'knight', type: 'BLADE' })).toBe('BLADE');
  });

  test('All matches everything; a chip matches only its group, so the custom fighter is no longer exempt', () => {
    expect(matchesChip('', 'CUSTOM')).toBe(true);
    expect(matchesChip('ARROW', 'CUSTOM')).toBe(false);
    expect(matchesChip('CUSTOM', 'CUSTOM')).toBe(true);
    expect(matchesChip('ARROW', 'ARROW')).toBe(true);
    expect(matchesChip('CUSTOM', 'ARROW')).toBe(false);
  });
});

describe('registerCustomRoster', () => {
  test('adds the entry and its body box under the custom key', () => {
    const byKey = {};
    const box = {};

    expect(registerCustomRoster(byKey, box, WIRE)).toBe(true);

    expect(byKey.custom.anims.idle.frames).toBe(6);
    expect(box.custom).toEqual(CUSTOM_BOX);
  });

  test('replaces an earlier version', () => {
    const byKey = {};
    const box = {};
    registerCustomRoster(byKey, box, WIRE);

    registerCustomRoster(byKey, box, { ...WIRE, animations: { ...WIRE.animations, idle: { frames: 4, rate: 8 } } });

    expect(byKey.custom.anims.idle.frames).toBe(4);
  });

  test('removes it when the user has no custom character any more', () => {
    const byKey = {};
    const box = {};
    registerCustomRoster(byKey, box, WIRE);

    expect(registerCustomRoster(byKey, box, null)).toBe(false);

    expect(byKey.custom).toBeUndefined();
    expect(box.custom).toBeUndefined();
  });

  test('never touches the roster fighters', () => {
    const byKey = { soldier: { key: 'soldier' } };
    const box = { soldier: [1, 2, 3, 4] };

    registerCustomRoster(byKey, box, null);
    registerCustomRoster(byKey, box, WIRE);

    expect(byKey.soldier).toEqual({ key: 'soldier' });
    expect(box.soldier).toEqual([1, 2, 3, 4]);
  });

  test('the box is a real box: left < right, top < bottom, inside the 100px frame', () => {
    const [x0, y0, x1, y1] = CUSTOM_BOX;

    expect(x0).toBeLessThan(x1);
    expect(y0).toBeLessThan(y1);
    expect(Math.min(x0, y0)).toBeGreaterThanOrEqual(0);
    expect(Math.max(x1, y1)).toBeLessThanOrEqual(100);
  });
});

describe('stripUrl', () => {
  test('hands a served URL back as it is, so custom strips play like the roster\'s', () => {
    expect(stripUrl('/storage/custom-characters/7/abc/idle.png')).toBe('/storage/custom-characters/7/abc/idle.png');
  });
});
