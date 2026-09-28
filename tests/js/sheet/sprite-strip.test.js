// @vitest-environment jsdom
import { expect, test } from 'vitest';
import { chargePalette, faceColor, frameIn, moves, pretty } from '@battlefield/sheet/sprite-strip.js';

test('a fighter\'s hotbar is idle, walk, its attacks named by type, then death and summon', () => {
  expect(moves('orc-rider').map(m => m.label)).toEqual(['Idle', 'Walk', 'Quick shot', 'Arrow volley', 'Piercing shot', 'Death', 'Summon']);
});

test('summon replays the death strip backwards', () => {
  expect(moves('soldier').find(m => m.id === 'summon')).toMatchObject({ anim: 'death', reverse: true });
});

test('frameIn scales the body box into the target and centres it, capped at 3x', () => {
  const el = document.createElement('span');
  frameIn(el, 'soldier', { maxW: 46, maxH: 36, cyPct: 40 });

  expect(Number(el.style.scale)).toBeCloseTo(2.12, 2);
  expect(el.style.left).toContain('50%');
});

test('pretty turns a key into a display name', () => {
  expect(pretty('orc-rider')).toBe('Orc rider');
});

test('the charge palette comes from the battlefield\'s own fighter config, as CSS hex', () => {
  expect(chargePalette('orc-rider')).toEqual(['#005500', '#117700', '#33aa00', '#55cc11', '#88ee44']);
});

test('a teammate keeps one face colour everywhere', () => {
  expect(faceColor(9)).toBe(faceColor(9));
  expect(faceColor(0)).toBe('#fb923c');
});
