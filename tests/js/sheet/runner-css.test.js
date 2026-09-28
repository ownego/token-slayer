import { readFileSync } from 'node:fs';
import postcss from 'postcss';
import { expect, test } from 'vitest';

const root = () => postcss.parse(readFileSync('resources/css/app.css', 'utf8'));

test('nothing in the runner animates or transitions a main-thread property (width, left, background-position)', () => {
  // Phaser blocks the main thread for up to half a second while the arena
  // loads; only transform/opacity keep moving on the compositor meanwhile
  const bad = [];
  root().walkRules(rule => {
    if (!rule.selector.includes('ts-runner')) {
      return;
    }
    rule.walkDecls(/^transition(-property)?$/, d => {
      if (/width|left|background/.test(d.value)) {
        bad.push(`${rule.selector} ${d.value}`);
      }
    });
  });
  root().walkAtRules('keyframes', at => {
    if (!at.params.startsWith('ts-')) {
      return;
    }
    at.walkDecls(d => {
      if (!['transform', 'opacity'].includes(d.prop)) {
        bad.push(`@keyframes ${at.params} ${d.prop}`);
      }
    });
  });

  expect(bad).toEqual([]);
});

test('the sheet loader\'s bar never loops back to empty: a lap jumping from full to 0 teleported the runner', () => {
  const anims = [];
  root().walkRules(rule => {
    if (rule.selector.includes('.is-lapping')) {
      rule.walkDecls('animation', d => anims.push(d.value));
    }
  });

  expect(anims.join(' ')).toContain('ts-runner-creep');
  expect(anims.filter(a => a.includes('creep')).every(a => a.includes('forwards') && !a.includes('infinite'))).toBe(true);
});
