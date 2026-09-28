import { expect, test } from 'vitest';
import { feedText, heraldFor } from '@battlefield/hud/feed-view.js';

test('one actor repeating shows ×N; several actors show the first two and +N', () => {
  expect(feedText({ kind: 'subagent', actors: ['tungot'], count: 3 })).toBe('tungot started subagents ×3');
  expect(feedText({ kind: 'join', actors: ['a', 'b', 'c', 'd'], count: 4 })).toBe('a, b +2 joined the fight');
  expect(feedText({ kind: 'join', actors: ['a'], count: 1 })).toBe('a joined the fight');
});

test('kills and new bosses go to the herald, not the feed', () => {
  expect(heraldFor({ type: 'boss-killed', killer: 'tungot', boss: 'CTHULHU', number: 13 })).toEqual({ kind: 'kill', text: 'tungot slew CTHULHU #13' });
  expect(heraldFor({ type: 'boss-spawned', boss: 'MINOTAUR', number: 14 })).toEqual({ kind: 'spawn', text: 'MINOTAUR #14 appeared' });
});
