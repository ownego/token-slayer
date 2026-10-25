import { expect, test } from 'vitest';
import { crewIcons, minionSpots } from '@battlefield/sheet/mini-stage.js';

const look = (name, frames = 6) => ({ url: `/m/${name}/idle.png`, frames, rate: 8, frame: 100, bodyPx: 22 });
const POOL = [look('a'), look('b')];

test('one minion stands on the mini stage per live subagent, alternating sides, taking the pool\'s looks in turn', () => {
  const spots = minionSpots(3, 3, POOL);

  expect(spots).toHaveLength(3);
  expect(Math.sign(spots[0].x)).toBe(-1);
  expect(Math.sign(spots[1].x)).toBe(1);
  expect(spots.map(s => s.look)).toEqual([POOL[0], POOL[1], POOL[0]]);
});

test('a crowd is capped at the spots the stage has room for', () => {
  expect(minionSpots(40, 40, POOL)).toHaveLength(6);
});

test('the first minions are the busy ones; the rest of the crew stands idle', () => {
  expect(minionSpots(3, 1, POOL).map(s => s.busy)).toEqual([true, false, false]);
  expect(minionSpots(2, 5, POOL).map(s => s.busy)).toEqual([true, true]);
});

test('with no subagent working there is no minion on the stage, whatever the pool holds', () => {
  expect(minionSpots(0, 0, POOL)).toEqual([]);
  expect(minionSpots(0, 3, POOL)).toEqual([]);
  expect(minionSpots(-1, 0, POOL)).toEqual([]);
});

test('a big pool fills no more spots than the stage has', () => {
  const pool = Array.from({ length: 8 }, (_, n) => look(`m${n}`));

  expect(minionSpots(10, 0, pool)).toHaveLength(6);
});

test('a spot is a subagent\'s, not a preview of the pool: it carries no preview flag', () => {
  expect(minionSpots(2, 1, POOL).some(s => 'preview' in s)).toBe(false);
});

test('with no looks there is nothing to draw', () => {
  expect(minionSpots(0)).toEqual([]);
  expect(minionSpots(3)).toEqual([]);
  expect(minionSpots(3, 3, [])).toEqual([]);
});

test('a big crew shows six Clawds and counts the rest, so the stat card never wraps off', () => {
  const crew = crewIcons(28, 3);

  expect(crew.icons).toHaveLength(6);
  expect(crew.icons.filter(i => i.busy)).toHaveLength(3);
  expect(crew.more).toBe(22);
  expect(crewIcons(2, 1)).toEqual({ icons: [{ busy: true }, { busy: false }], more: 0 });
});
