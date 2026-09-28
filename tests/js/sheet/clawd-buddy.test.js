// @vitest-environment jsdom
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { createBuddy, spotsFor } from '@battlefield/sheet/clawd-buddy.js';

test('three spots span the panel: left edge, centre, right edge', () => {
  expect(spotsFor(29)).toEqual([0, 10, 20]);
  expect(spotsFor(21)).toEqual([0, 6, 12]);
});

beforeEach(() => {
  document.body.innerHTML = '<div id="panel" style="width: 200px"></div>';
  vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} });
  vi.stubGlobal('matchMedia', () => ({ matches: false }));
});

afterEach(() => {
  vi.unstubAllGlobals();
});

test('mounts an svg stick figure and tears it down cleanly', () => {
  const panel = document.getElementById('panel');
  const buddy = createBuddy(panel);

  expect(panel.querySelector('svg')).not.toBeNull();

  expect(() => buddy.destroy()).not.toThrow();
});

test('a hit plays a reaction and pops the damage text', () => {
  const panel = document.getElementById('panel');
  const buddy = createBuddy(panel);

  buddy.onHit(1234);

  expect(panel.querySelector('.buddy-pop')).not.toBeNull();
  buddy.destroy();
});

test('a kill celebrates instead of a plain hit pop', () => {
  const panel = document.getElementById('panel');
  const buddy = createBuddy(panel);

  buddy.onKill();

  expect(panel.textContent).toContain('Boss down!');
  buddy.destroy();
});

test('exposes a live combo count and calls onComboChange on every change', () => {
  vi.stubGlobal('matchMedia', () => ({ matches: true })); // reduced-motion: skip the idle animation loop so advanceTimersByTime doesn't process it forever
  vi.useFakeTimers();
  const panel = document.getElementById('panel');
  const changes = [];
  const buddy = createBuddy(panel, { onComboChange: c => changes.push(c) });

  buddy.onHit(100);
  expect(buddy.combo).toBe(1);
  expect(changes).toEqual([1]);

  buddy.onHit(50);
  expect(buddy.combo).toBe(2);
  expect(changes).toEqual([1, 2]);

  vi.advanceTimersByTime(60_001);
  expect(buddy.combo).toBe(0);
  expect(changes).toEqual([1, 2, 0]);

  buddy.destroy();
  vi.useRealTimers();
});

test('a hit after the combo window restarts the streak at 1, not 0-then-1 as two separate changes', () => {
  vi.stubGlobal('matchMedia', () => ({ matches: true })); // reduced-motion: skip the idle animation loop so advanceTimersByTime doesn't process it forever
  vi.useFakeTimers();
  const panel = document.getElementById('panel');
  const changes = [];
  const buddy = createBuddy(panel, { onComboChange: c => changes.push(c) });

  buddy.onHit(10);
  vi.advanceTimersByTime(61_000); // window expires, resets to 0
  buddy.onHit(10); // new streak starts at 1 — combo math is independent of tokens/damage magnitude

  expect(buddy.combo).toBe(1);
  expect(changes).toEqual([1, 0, 1]);

  buddy.destroy();
  vi.useRealTimers();
});
