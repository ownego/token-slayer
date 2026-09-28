// @vitest-environment jsdom
import { expect, test } from 'vitest';
import { paintRunner, runnerHeel, runnerSprite, setRunnerProgress } from '@battlefield/sheet/runner.js';
import { BOX } from '@battlefield/sheet/sheet-roster.js';

// Everything the runner moves runs on transform/opacity, so the compositor
// keeps it smooth while Phaser blocks the main thread loading the arena
// (measured: 350-530ms long tasks while the loader shows).

test('the loading runner is the viewer\'s own fighter, its walk stepped by transform', () => {
  const el = document.createElement('span');

  paintRunner(el, 'werebear');

  const strip = el.querySelector('.ts-runner-strip');
  expect(strip.style.backgroundImage).toContain('werebear-walk');
  expect(strip.style.width).toBe('800px');
  expect(strip.style.animation).toContain('ts-walk-8');
  expect(strip.style.animation).toContain('steps(8)');
  expect(document.head.textContent).toContain('translateX(-800px)');
});

test('a guest or an unknown character still gets a runner, never an empty bar', () => {
  const el = document.createElement('span');

  paintRunner(el, '');

  expect(el.querySelector('.ts-runner-strip').style.backgroundImage).toContain('url(');
});

test('painting twice keeps one sprite and one spark burst', () => {
  const el = document.createElement('span');
  paintRunner(el, 'wizard');
  paintRunner(el, 'wizard');

  expect(el.querySelectorAll('.ts-runner-strip')).toHaveLength(1);
  expect(el.querySelectorAll('.ts-runner-sparks')).toHaveLength(1);
});

test('sparks fly from the runner\'s back heel: behind its middle, down at its feet', () => {
  const [x0, y0, x1, y1] = BOX.werebear;
  const heel = runnerHeel('werebear', 40, 40, null);
  const sc = Math.min(3, 40 / (x1 - x0), 40 / (y1 - y0));

  expect(heel.x).toBeLessThan(20);
  expect(heel.y).toBeCloseTo(40 * 0.6 + (y1 - (y0 + y1) / 2) * sc, 5);
});

test('the sparks are CSS pixels at the heel in the fighter\'s charge colours, not a per-frame canvas', () => {
  const el = document.createElement('span');
  paintRunner(el, 'werebear');

  const sparks = el.querySelector('.ts-runner-sparks');
  const heel = runnerHeel('werebear', 40, 40, null);
  expect(sparks.querySelectorAll('b').length).toBeGreaterThanOrEqual(5);
  expect(sparks.style.left).toBe(`${heel.x.toFixed(1)}px`);
  expect(el.style.getPropertyValue('--c3')).not.toBe('');
  expect(el.querySelector('canvas')).toBeNull();
});

test('progress moves the runner by a CSS variable, clamped to the bar', () => {
  document.body.innerHTML = '<div class="ts-runner"><div class="ts-runner-cover" id="bar"></div></div>';
  const bar = document.getElementById('bar');

  setRunnerProgress(bar, 0.4);
  expect(document.querySelector('.ts-runner').style.getPropertyValue('--p')).toBe('0.4');
  setRunnerProgress(bar, 3);
  expect(document.querySelector('.ts-runner').style.getPropertyValue('--p')).toBe('1');
});

test('the Alpine component paints its box from data-char', () => {
  const el = document.createElement('span');
  el.dataset.char = 'werebear';
  const runner = runnerSprite();
  runner.$el = el;

  runner.init();

  expect(el.querySelector('.ts-runner-strip').style.backgroundImage).toContain('werebear-walk');
});
