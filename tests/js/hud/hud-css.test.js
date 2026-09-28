import { readFileSync } from 'node:fs';
import postcss from 'postcss';
import { expect, test } from 'vitest';

test('no HUD panel blurs the live canvas behind it (backdrop-filter over WebGL re-composites every frame and can flicker the panel out on hits)', () => {
  const root = postcss.parse(readFileSync('resources/css/battlefield-hud.css', 'utf8'));
  const offenders = [];
  root.walkDecls(/^(-webkit-)?backdrop-filter$/, d => {
    if (d.value !== 'none') {
      offenders.push(d.parent.selector);
    }
  });

  expect(offenders).toEqual([]);
});

test('no global stylesheet owns a bare .flash rule: the HUD flashes its Team totals with that class, and the old one faded them to nothing for good', () => {
  const root = postcss.parse(readFileSync('resources/css/app.css', 'utf8'));
  const bare = [];
  root.walkRules(rule => {
    if (rule.selectors.some(s => s.trim() === '.flash')) {
      bare.push(rule.selector);
    }
  });

  expect(bare).toEqual([]);
});

test('lite mode\'s small canvas is scaled up crisp, not smeared', () => {
  // it renders at the layout's own size and the browser scales it; smoothing
  // would blur every pixel-art sprite
  const root = postcss.parse(readFileSync('resources/css/app.css', 'utf8'));
  let value = null;
  root.walkRules(rule => {
    if (rule.selector === '#battlefield-mount.bf-lite canvas') {
      rule.walkDecls('image-rendering', d => { value = d.value; });
    }
  });
  expect(value).toBe('pixelated');
});
