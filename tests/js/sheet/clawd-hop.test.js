// @vitest-environment jsdom
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { clawdHop, leap } from '@battlefield/sheet/clawd-hop.js';

const DOM = '<div class="ts-hop"><div class="ts-hop-lane"><span class="ts-hop-mover"><span class="ts-hop-body"><svg class="ts-hop-clawd" viewBox="0 0 18 6"></svg></span></span><span class="ts-hop-goal"></span></div></div>';

beforeEach(() => {
  vi.useFakeTimers();
  document.body.innerHTML = DOM;
});
afterEach(() => vi.useRealTimers());

function mount() {
  const hop = clawdHop();
  hop.$el = document.querySelector('.ts-hop-clawd');
  hop.init();

  return hop;
}

test('the loader draws Clawd bouncing in place, glancing about while it waits', () => {
  mount();
  const svg = document.querySelector('.ts-hop-clawd');
  expect(svg.querySelectorAll('rect.b').length).toBeGreaterThan(10);
  const poses = new Set();
  for (let i = 0; i < 12; i++) {
    poses.add(svg.dataset.pose);
    vi.advanceTimersByTime(400);
  }
  expect(poses.has('default')).toBe(true);
  expect(poses.has('look-left') || poses.has('look-right')).toBe(true);
});

test('once the sheet is ready Clawd leaps, arms up, to the goal and stops glancing', () => {
  mount();
  const root = document.querySelector('.ts-hop');

  leap(root);

  const svg = document.querySelector('.ts-hop-clawd');
  expect(root.classList.contains('is-leaping')).toBe(true);
  expect(svg.dataset.pose).toBe('arms-up');
  vi.advanceTimersByTime(300);
  expect(svg.dataset.pose).toBe('arms-up');
});
