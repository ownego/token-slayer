import { describe, test, expect } from 'vitest';
import { planContacts } from '@battlefield/attacks/hero/contacts.js';

const MOVE = [{ atMs: 200 }, { atMs: 400, main: true }, { atMs: 600 }];
const OPEN = { capped: false, reduced: false, killShot: false, maxMs: 900 };

describe('planContacts', () => {
  test('keeps every contact, with exactly one main', () => {
    expect(planContacts(MOVE, OPEN)).toEqual([
      { atMs: 200, main: false }, { atMs: 400, main: true }, { atMs: 600, main: false },
    ]);
  });

  test('past the effect cap a move plays only its main contact', () => {
    expect(planContacts(MOVE, { ...OPEN, capped: true })).toEqual([{ atMs: 400, main: true }]);
  });

  test('under reduced motion a move plays only its main contact', () => {
    expect(planContacts(MOVE, { ...OPEN, reduced: true })).toEqual([{ atMs: 400, main: true }]);
  });

  test('a kill shot drops the contacts after the main one, so the ceremony is not kept waiting', () => {
    expect(planContacts(MOVE, { ...OPEN, killShot: true })).toEqual([
      { atMs: 200, main: false }, { atMs: 400, main: true },
    ]);
  });

  test('the main contact never lands later than the cap on contact time', () => {
    expect(planContacts([{ atMs: 1500, main: true }], OPEN)).toEqual([{ atMs: 900, main: true }]);
  });

  test('a move that marks no main contact lands on its last one', () => {
    expect(planContacts([{ atMs: 100 }, { atMs: 300 }], OPEN)).toEqual([
      { atMs: 100, main: false }, { atMs: 300, main: true },
    ]);
  });
});
