import { expect, test } from 'vitest';
import { createFeed } from '@battlefield/shared/feed-merge.js';

test('the same actor doing the same thing within 8s folds into one line with a count', () => {
  const f = createFeed();
  f.push('subagent', 'tungot', 0); f.push('subagent', 'tungot', 2000); f.push('subagent', 'tungot', 7000);
  const [line] = f.visible(7000).lines;
  expect(line).toMatchObject({ actors: ['tungot'], count: 3 });
});

test('different actors within 4s fold into "a, b +N"', () => {
  const f = createFeed();
  ['a', 'b', 'c', 'd'].forEach((who, i) => f.push('join', who, i * 500));
  const [line] = f.visible(2000).lines;
  expect(line.actors).toEqual(['a', 'b', 'c', 'd']);
});

test('at most 3 lines show; older live ones are counted as "earlier"', () => {
  const f = createFeed();
  // alternate kinds and space the same kind > 4 s apart so nothing merges, all still alive at 4.3 s
  f.push('join', 'a', 0); f.push('subagent', 'b', 100); f.push('join', 'c', 4200); f.push('subagent', 'd', 4300);
  const v = f.visible(4300);
  expect(v.lines).toHaveLength(3);
  expect(v.earlier).toBe(1);
});

test('lines age out after 7s from their last event', () => {
  const f = createFeed();
  f.push('join', 'a', 0);
  expect(f.visible(7001).lines).toHaveLength(0);
});

test('history keeps the last 12 raw events, newest first', () => {
  const f = createFeed();
  for (let i = 0; i < 15; i++) { f.push('join', 'u' + i, i * 10000); }
  expect(f.history()).toHaveLength(12);
  expect(f.history()[0].actor).toBe('u14');
});
