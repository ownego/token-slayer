import { expect, test } from 'vitest';
import { activityFit, wrapActivity } from '@battlefield/bubble-y.js';

test('the action bubble stays compact however big the fighter has grown', () => {
  const small = activityFit(40);
  const huge = activityFit(140);
  expect(huge.fontPx).toBeLessThanOrEqual(14);
  expect(huge.maxChars).toBeLessThanOrEqual(22);
  expect(small.fontPx).toBeGreaterThanOrEqual(10);
});

test('a long action wraps onto at most two lines at word breaks, then ends in an ellipsis', () => {
  const lines = wrapActivity('Running php artisan test --compact tests/Feature/Livewire/FighterSheetTest.php', 18, 2).split('\n');
  expect(lines).toHaveLength(2);
  lines.forEach(l => expect(l.length).toBeLessThanOrEqual(18));
  expect(lines[0]).toBe('Running php');
  expect(lines[1].endsWith('…')).toBe(true);
});

test('a short action stays on one line untouched', () => {
  expect(wrapActivity('thinking…', 18, 2)).toBe('thinking…');
});
