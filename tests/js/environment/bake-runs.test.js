import { expect, test } from 'vitest';
import { bakeInto, bakeRuns, floatLive, runBounds } from '@battlefield/environment/bake-runs.js';

test('groups the layer order into runs: consecutive still pieces bake together, a live one splits them', () => {
  const items = ['sky', 'stars', 'moon', 'clouds', 'clawd', 'back', 'far', 'sails', 'floor', 'vig'];
  const live = new Set(['moon', 'clawd', 'sails']);
  expect(bakeRuns(items, x => live.has(x))).toEqual([
    { live: false, items: ['sky', 'stars'] },
    { live: true, items: ['moon'] },
    { live: false, items: ['clouds'] },
    { live: true, items: ['clawd'] },
    { live: false, items: ['back', 'far'] },
    { live: true, items: ['sails'] },
    { live: false, items: ['floor', 'vig'] },
  ]);
});

test('keeps the draw order exactly, so what was behind stays behind', () => {
  const items = [1, 2, 3, 4, 5, 6];
  expect(bakeRuns(items, x => x % 3 === 0).flatMap(r => r.items)).toEqual(items);
});

test('an empty layer has no runs', () => {
  expect(bakeRuns([], () => false)).toEqual([]);
});

const box = (x, y, w, h) => ({ getBounds: () => ({ x, y, right: x + w, bottom: y + h }) });
const full = { width: 960, height: 540 };

test('a run bakes into the smallest whole-pixel rect covering it, inside the world', () => {
  expect(runBounds([box(10.5, 20, 30, 40), box(100, -5, 20, 10)], full)).toEqual({ x: 10, y: 0, w: 110, h: 60 });
});

test('a piece with no getBounds (a Graphics) uses the bounds it was given, else the whole world', () => {
  // the brazier stands are Graphics: bounds lookup threw and broke lite mode's whole scene
  expect(runBounds([{ bakeBounds: { x: 40, y: 200, right: 70, bottom: 236 } }], full)).toEqual({ x: 40, y: 200, w: 30, h: 36 });
  expect(runBounds([{}], full)).toEqual({ x: 0, y: 0, w: 960, h: 540 });
});

test('bakes each piece at its own place within the texture, shown only while it is drawn', () => {
  // Phaser's draw(array, x, y) puts every object AT x/y, not offset by it:
  // passing the texture's corner stacked the whole run in one spot
  const calls = [];
  const rt = { clear: () => calls.push('clear'), draw: (o, x, y) => calls.push([o.name, x, y, o.visible]) };
  const piece = (name, x, y) => ({ name, x, y, visible: false, setVisible(v) { this.visible = v; return this; } });
  const items = [piece('far', 0, 97), piece('castle', 700, 150)];

  bakeInto(rt, items, 0, 90);

  expect(calls).toEqual(['clear', ['far', 0, 7, true], ['castle', 700, 60, true]]);
  expect(items.every(o => o.visible === false)).toBe(true);
});

test('floats the given live pieces up to just under the top ones, so still pieces around them bake as one run', () => {
  // a brazier's additive glow split off into its own run had nothing under
  // it in its texture and baked as a hard-edged box
  const items = ['floor', 'glow1', 'stand1', 'fire1', 'glow2', 'stand2', 'fire2', 'dust', 'vig'];
  expect(floatLive(items, ['fire1', 'fire2'], ['vig'])).toEqual(['floor', 'glow1', 'stand1', 'glow2', 'stand2', 'dust', 'fire1', 'fire2', 'vig']);
});

test('a run covering most of the world bakes the whole world, so no halo is clipped to a straight edge', () => {
  // the far range's run cropped at its top edge cut the torches' additive
  // halos off in a straight line across the sky
  expect(runBounds([box(0, 109, 960, 432)], full)).toEqual({ x: 0, y: 0, w: 960, h: 540 });
});
