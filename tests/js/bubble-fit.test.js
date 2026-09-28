import { expect, test } from 'vitest';
import { activityFit, wrapActivity } from '@battlefield/bubble-y.js';

test('the action bubble stays compact however big the fighter has grown', () => {
  const small = activityFit(40);
  const huge = activityFit(140);
  expect(huge.fontPx).toBeLessThanOrEqual(14);
  expect(huge.maxChars).toBeLessThanOrEqual(22);
  expect(small.fontPx).toBeGreaterThanOrEqual(10);
});

test('the action bubble is one line, never wrapped onto a second', () => {
  expect(activityFit(40).maxLines).toBe(1);
  expect(activityFit(140).maxLines).toBe(1);
});

test('a long action is cut to one line at a word break and ends in an ellipsis', () => {
  const out = wrapActivity('Running php artisan test --compact tests/Feature/Livewire/FighterSheetTest.php', 24, 1);
  expect(out).not.toContain('\n');
  expect(out.length).toBeLessThanOrEqual(24);
  expect(out.endsWith('…')).toBe(true);
});

test('a short action stays on one line untouched', () => {
  expect(wrapActivity('thinking…', 18, 2)).toBe('thinking…');
});
