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

/** Every rule in battlefield-hud.css, with the @container params it sits in. */
function hudRules() {
  const root = postcss.parse(readFileSync('resources/css/battlefield-hud.css', 'utf8'));
  const rules = [];
  root.walkRules(rule => rules.push({ rule, container: rule.parent.type === 'atrule' ? rule.parent.params : null }));

  return rules;
}

test('the boss HP reading has one rule: a second one later in the file silently won its size before', () => {
  const owners = hudRules().filter(({ rule }) => rule.selectors.includes('.bf-plate .hpbar .hp-t'));
  const sized = owners.filter(({ rule }) => rule.some(d => d.prop === 'font' || d.prop === 'font-size'));

  expect(sized).toHaveLength(1);
});

test('every damage number is sized by --bf-num-px, nowhere by hand', () => {
  for (const { rule } of hudRules()) {
    rule.walkDecls(/^font(-size)?$/, d => {
      if (d.value.includes('var(--bf-num)')) {
        expect(d.value, rule.selector).toContain('var(--bf-num-px)');
      }
    });
  }
});

test('Press Start 2P numbers render on its 8px grid at every HUD zoom, so the digits stay crisp', () => {
  const rules = hudRules();
  const base = rules.find(({ rule }) => rule.selector === ':root').rule.nodes.find(d => d.prop === '--bf-num-px').value;
  expect(base).toBe('8px');

  for (const { rule, container } of rules) {
    const zoom = rule.nodes.find(d => d.type === 'decl' && d.prop === 'zoom');
    if (!zoom) {
      continue;
    }
    const size = rules.find(r => r.container === container && r.rule.nodes.some(d => d.prop === '--bf-num-px'));
    expect(size, `${container} zooms the HUD without resizing the numbers`).toBeDefined();
    const [, px, divisor] = size.rule.nodes.find(d => d.prop === '--bf-num-px').value.match(/calc\((\d+)px \/ ([\d.]+)\)/);
    expect(Number(divisor)).toBe(Number(zoom.value));
    expect(Number(px) % 8).toBe(0);
  }
});
