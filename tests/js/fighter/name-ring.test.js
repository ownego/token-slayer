import { expect, test } from 'vitest';
import { NAME_DEPTH, NAME_STYLE, YOU_NAME_COLOR, youRingSparks } from '@battlefield/fighter/name-ring.js';

test('the name is plain outlined text, no frame behind it', () => {
  expect(NAME_STYLE.backgroundColor).toBeUndefined();
  expect(NAME_STYLE.padding).toBeUndefined();
  expect(NAME_STYLE.stroke).toBeTruthy();
  expect(NAME_STYLE.strokeThickness).toBeGreaterThan(0);
});

test('the name draws above the fighter container, so the YOU ring never covers it', () => {
  // fighter containers sit at depth 2 (fighter/index.js addFighter)
  expect(NAME_DEPTH).toBeGreaterThan(2);
});

test('your own name wears the mockup\'s gold', () => {
  expect(YOU_NAME_COLOR).toBe('#fde68a');
});

test('the ring\'s sparks orbit the ellipse, evenly spaced, moving over time', () => {
  const now = youRingSparks(0, 4, 60, 20);
  const later = youRingSparks(500, 4, 60, 20);

  expect(now).toHaveLength(4);
  now.forEach(p => expect((p.x / 30) ** 2 + (p.y / 10) ** 2).toBeCloseTo(1, 5));
  expect(later[0].x).not.toBeCloseTo(now[0].x, 3);
  // sparks on the far side of the ring (behind the feet) are dimmer
  const back = now.find(p => p.y < 0);
  const front = now.find(p => p.y > 0);
  expect(back.alpha).toBeLessThan(front.alpha);
});
