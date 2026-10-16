// @vitest-environment jsdom
import { readFileSync } from 'node:fs';
import { afterEach, expect, test, vi } from 'vitest';
import { posePrototype, prototypeFrame, prototypeRows } from '@battlefield/sheet/pose-prototype.js';
import { ROSTER, stripUrl } from '@battlefield/sheet/sheet-roster.js';

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

test('the guide follows the input sheet row order and pose counts', () => {
  expect(prototypeRows.map(({ key, sourcePoses }) => [key, sourcePoses])).toEqual([
    ['idle', '2–4'],
    ['walk', '4'],
    ['attack', '3'],
    ['death', '3'],
  ]);
});

test('the empty-state motion example masks a real roster fighter without rendering costume colors', () => {
  document.body.innerHTML = readFileSync('resources/views/partials/custom-character-motion-prototype.blade.php', 'utf8');
  const sprite = document.querySelector('.forge-motion-sprite');
  const css = readFileSync('resources/css/fighter-sheet.css', 'utf8');
  const lancer = ROSTER.find(fighter => fighter.key === 'lancer');

  expect(sprite).not.toBeNull();
  expect(document.querySelector('.forge-motion-figure')).toBeNull();
  expect(document.querySelector('.forge-motion-index')?.getAttribute('x-text')).toContain("'Frame '");
  expect(css).toMatch(/\.fs \.forge-motion-sprite \{[^}]*mask-image: var\(--forge-strip\)/);
  expect(css).toMatch(/\.fs \.forge-motion-sprite \{[^}]*image-rendering: auto/);
  expect(prototypeRows.map(row => row.strip)).toEqual(['idle', 'walk', 'attack1', 'death'].map(action => stripUrl(lancer.anims[action].file)));
  expect(css).toMatch(/@media \(max-height: 899px\) \{\s*\.fs \.forge-motion-sprite \{ scale: 2\.5; \}/);
});

test('each action advances through the reference fighter frames at its real rate', () => {
  const lancer = ROSTER.find(fighter => fighter.key === 'lancer');
  expect(prototypeRows.map(row => row.rate)).toEqual(['idle', 'walk', 'attack1', 'death'].map(action => lancer.anims[action].rate));
  expect(prototypeFrame('idle', 0).spriteFrame).not.toBe(prototypeFrame('idle', 2).spriteFrame);
  expect(prototypeFrame('walk', 0).spriteFrame).not.toBe(prototypeFrame('walk', 4).spriteFrame);
  expect(prototypeFrame('attack', 0).spriteFrame).not.toBe(prototypeFrame('attack', 4).spriteFrame);
  expect(prototypeFrame('death', 0).spriteFrame).toBeLessThan(prototypeFrame('death', 2).spriteFrame);
  expect(prototypeRows.find(row => row.key === 'death')?.sequence).toEqual([0, 1, 2, 3]);
});

test('choosing an action switches the visible example and restarts its frame sequence', () => {
  vi.useFakeTimers();
  vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
  const prototype = posePrototype();
  prototype.init();
  vi.advanceTimersByTime(250);
  expect(prototype.tick).toBeGreaterThan(0);

  prototype.select('attack');
  expect(prototype.action).toBe('attack');
  expect(prototype.tick).toBe(0);
  expect(prototype.row.sourcePoses).toBe('3');
  prototype.destroy();
});

test('reduced motion keeps the example on a still representative pose', () => {
  vi.useFakeTimers();
  vi.stubGlobal('matchMedia', () => ({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
  const prototype = posePrototype();
  prototype.init();
  vi.advanceTimersByTime(1000);

  expect(prototype.tick).toBe(0);
  expect(prototype.motionReduced).toBe(true);
  prototype.togglePlayback();
  expect(prototype.timer).toBeNull();
  prototype.select('walk');
  expect(prototype.frame).toEqual(prototypeFrame('walk', 0));
  prototype.destroy();
});

test('death holds its final pose and replay starts from the first pose', () => {
  vi.useFakeTimers();
  vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
  const prototype = posePrototype();
  prototype.select('death');
  prototype.init();
  vi.advanceTimersByTime(1000);

  expect(prototype.tick).toBe(3);
  expect(prototype.poseNumber).toBe(4);
  expect(prototype.timer).toBeNull();
  expect(prototypeFrame('death', 99)).toEqual(prototypeFrame('death', 3));

  prototype.togglePlayback();
  expect(prototype.tick).toBe(0);
  vi.advanceTimersByTime(170);
  expect(prototype.tick).toBe(1);
  prototype.destroy();
});

test('pause freezes the pose and play resumes it', () => {
  vi.useFakeTimers();
  vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
  const prototype = posePrototype();
  prototype.init();
  vi.advanceTimersByTime(200);
  const pausedTick = prototype.tick;

  prototype.togglePlayback();
  vi.advanceTimersByTime(500);
  expect(prototype.tick).toBe(pausedTick);
  expect(prototype.timer).toBeNull();

  prototype.togglePlayback();
  vi.advanceTimersByTime(100);
  expect(prototype.tick).toBeGreaterThan(pausedTick);
  prototype.destroy();
});

test('playback stops when the character tab or sheet is hidden', async () => {
  vi.useFakeTimers();
  vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
  document.body.innerHTML = '<div x-data="sheetFrame()"><div class="fs"><div class="tab-panel is-active"><div class="showcase blank editing"><div id="prototype"></div></div></div></div></div>';
  const prototype = posePrototype();
  prototype.$el = document.querySelector('#prototype');
  prototype.init();
  expect(prototype.timer).not.toBeNull();

  const tab = document.querySelector('.tab-panel');
  tab.classList.remove('is-active');
  await Promise.resolve();
  expect(prototype.timer).toBeNull();
  tab.classList.add('is-active');
  await Promise.resolve();
  expect(prototype.timer).not.toBeNull();

  const frame = document.querySelector('[x-data="sheetFrame()"]');
  frame.style.display = 'none';
  await Promise.resolve();
  expect(prototype.timer).toBeNull();
  prototype.destroy();
  document.body.innerHTML = '';
});
