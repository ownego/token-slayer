import { describe, expect, test } from 'vitest';
import { moonDisplay, skyFrame } from '@battlefield/environment/sky-layer.js';

const site = { lat: 21.03, lon: 105.85 };
const box = { width: 960, horizonY: 186 };

test('noon: sun visible high up, no stars', () => {
  const f = skyFrame(new Date('2026-03-20T05:05:00Z'), site, box);
  expect(f.sun.visible).toBe(true);
  expect(f.sun.y).toBeLessThan(80);
  expect(f.night).toBe(0);
});

test('midnight: stars at full strength, sun hidden', () => {
  const f = skyFrame(new Date('2026-03-20T17:00:00Z'), site, box);
  expect(f.sun.visible).toBe(false);
  expect(f.night).toBe(1);
});

test('dusk has a warm horizon and a twilight weight', () => {
  const f = skyFrame(new Date('2026-03-20T11:05:00Z'), site, box); // ~18:05 local
  expect(f.twilight).toBeGreaterThan(0.5);
});

describe('moonDisplay', () => {
  const box = { width: 1000, horizonY: 300 };
  const frame = (over = {}) => ({ sun: { visible: false }, moon: { x: 700, y: 120, visible: true }, day: 0, night: 1, twilight: 0, ...over });

  test('a risen moon is drawn where it really is', () => {
    expect(moonDisplay(frame(), box, () => 150, 20)).toMatchObject({ x: 700, y: 120 });
  });

  test('after sunset, a moon not yet risen peeks over the back ridge, clear of the HUD corner', () => {
    const ridge = x => 150 + Math.round(x / 50); // the back range's skyline at x
    const shown = moonDisplay(frame({ moon: { x: 40, y: 400, visible: false }, night: 0.3, twilight: 0.8 }), box, ridge, 20);
    expect(shown.alpha).toBeGreaterThan(0.4);
    expect(shown.x).toBeGreaterThan(box.width * 0.3); // off the Team Damage panel's corner
    expect(shown.x).toBeLessThan(box.width * 0.45);
    // mostly above the ridge line, its bottom tucked behind it
    expect(shown.y).toBeLessThan(ridge(shown.x));
    expect(shown.y + 20).toBeGreaterThan(ridge(shown.x));
  });

  test('a risen moon still lower than the back ridge rises to peek over it instead of hiding behind the mountains', () => {
    // 20h in Hanoi: the moon is 20 degrees up, but its plotted point is below the skyline
    const shown = moonDisplay(frame({ moon: { x: 500, y: 170, visible: true } }), box, () => 150, 20);
    expect(shown.y).toBeLessThan(150);
    expect(shown.alpha).toBeGreaterThan(0.9);
  });

  test('a moon that would sit behind a HUD panel moves sideways just clear of it', () => {
    const team = { l: 0, r: 220, t: 40, b: 170 };
    const shown = moonDisplay(frame({ moon: { x: 108, y: 120, visible: true } }), box, () => 150, 20, [team]);
    expect(shown.x).toBeGreaterThanOrEqual(team.r + 20);
    expect(shown.x).toBeLessThan(team.r + 60);
  });

  test('a HUD zone with a fractional edge still leaves the moon on a real pixel (the skyline is looked up by index)', () => {
    const skyline = Array.from({ length: 1000 }, () => 150);
    const shown = moonDisplay(frame({ moon: { x: 108, y: 120, visible: true } }), box, x => skyline[x], 20, [{ l: 3.4, r: 215.7, t: 29.2, b: 143.9 }]);
    expect(Number.isInteger(shown.x)).toBe(true);
    expect(Number.isFinite(shown.y)).toBe(true);
  });

  test('by day a moon below the horizon stays hidden', () => {
    const shown = moonDisplay(frame({ sun: { visible: true }, moon: { x: 40, y: 400, visible: false }, day: 1, night: 0 }), box, () => 150, 20);
    expect(shown.alpha).toBe(0);
  });
});
