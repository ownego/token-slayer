import { expect, test } from 'vitest';
import { clawdStep, poseRects, SEQUENCES, zoneOf } from '@battlefield/shared/clawd.js';

const R = { left: 0, top: 0, width: 300, height: 100 };

test('zones: 3 columns x 2 rows', () => {
  expect(zoneOf({ x: 10, y: 10 }, R)).toEqual({ col: 0, row: 0 });
  expect(zoneOf({ x: 290, y: 90 }, R)).toEqual({ col: 2, row: 1 });
  expect(zoneOf({ x: 150, y: 60 }, R)).toEqual({ col: 1, row: 1 });
});

test('pointer one column away: look that way (horizontally, even on the top row) and hop', () => {
  expect(clawdStep(1, { col: 2, row: 0 })).toEqual({ look: 'look-right', move: 'hop', to: 2 });
  expect(clawdStep(1, { col: 0, row: 1 })).toEqual({ look: 'look-left', move: 'hop', to: 0 });
});

test('pointer two columns away: somersault', () => {
  expect(clawdStep(0, { col: 2, row: 1 })).toEqual({ look: 'look-right', move: 'flip', to: 2 });
});

test('pointer in its own column: stay, look ahead', () => {
  expect(clawdStep(1, { col: 1, row: 0 })).toEqual({ look: 'default', move: 'none', to: 1 });
});

test('no pose ever looks up on its own: the step never returns arms-up', () => {
  for (const col of [0, 1, 2]) { for (const row of [0, 1]) {
    expect(clawdStep(1, { col, row }).look).not.toBe('arms-up');
  } }
});

test('the default pose has two eye cells on the head row', () => {
  const eyes = poseRects('default').filter(p => !p.on && p.y < 2);
  expect(eyes).toHaveLength(2);
});

test('sequences keep Claude Code timing: jump = crouch+dust, arms-up x3, default, twice', () => {
  expect(SEQUENCES.jump).toHaveLength(12);
  expect(SEQUENCES.jump[0]).toMatchObject({ pose: 'default', offset: 1, poof: 'dot' });
});
