import { expect, test } from 'vitest';
import { crewIcons, minionSpots } from '@battlefield/sheet/mini-stage.js';

test('one minion stands on the mini stage per busy subagent, alternating sides', () => {
  const spots = minionSpots(3);

  expect(spots).toHaveLength(3);
  expect(Math.sign(spots[0].x)).toBe(-1);
  expect(Math.sign(spots[1].x)).toBe(1);
  expect(spots.map(s => s.kind)).toEqual(['m-goomba', 'm-demon', 'm-goomba']);
});

test('no subagents, no minions; a crowd is capped at the spots the stage has room for', () => {
  expect(minionSpots(0)).toEqual([]);
  expect(minionSpots(40)).toHaveLength(6);
});

test('the first minions are the busy ones; the rest of the crew stands idle', () => {
  expect(minionSpots(3, 1).map(s => s.busy)).toEqual([true, false, false]);
  expect(minionSpots(2, 5).map(s => s.busy)).toEqual([true, true]);
});

test('a big crew shows six Clawds and counts the rest, so the stat card never wraps off', () => {
  const crew = crewIcons(28, 3);

  expect(crew.icons).toHaveLength(6);
  expect(crew.icons.filter(i => i.busy)).toHaveLength(3);
  expect(crew.more).toBe(22);
  expect(crewIcons(2, 1)).toEqual({ icons: [{ busy: true }, { busy: false }], more: 0 });
});
