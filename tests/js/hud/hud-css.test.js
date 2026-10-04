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

/** Declarations a selector gets inside @container blocks whose params match `params`. */
function containerDecls(params, selector) {
  const root = postcss.parse(readFileSync('resources/css/battlefield-hud.css', 'utf8'));
  const decls = {};
  root.walkAtRules('container', at => {
    if (!params.test(at.params)) {
      return;
    }
    at.walkRules(rule => {
      if (rule.selectors.includes(selector)) {
        rule.walkDecls(d => { decls[d.prop] = d.value; });
      }
    });
  });

  return decls;
}

test('the portrait board sheet shows TOP DAMAGE once: its handle, not the panel title under it', () => {
  expect(containerDecls(/^hud \(max-aspect-ratio: 1\/1\)$/, '.bf-board .tp-title').display).toBe('none');
});

test('desktop keeps team and board as their own grid panels: the stats wrapper adds no box', () => {
  const root = postcss.parse(readFileSync('resources/css/battlefield-hud.css', 'utf8'));
  let display = null;
  root.walkRules(rule => {
    if (rule.parent.type === 'root' && rule.selectors.includes('.bf-stats')) {
      rule.walkDecls('display', d => { display = d.value; });
    }
  });

  expect(display).toBe('contents');
});

test('a portrait phone keeps only the boss plate up top: team and board live in a bottom sheet', () => {
  expect(containerDecls(/^hud \(max-aspect-ratio: 1\/1\)$/, '.bf-stats').position).toBe('absolute');
  expect(containerDecls(/^hud \(max-aspect-ratio: 1\/1\)$/, '.bf-stats').bottom).toBe('0');
  expect(containerDecls(/^hud \(max-aspect-ratio: 1\/1\)$/, '.bf-stats:not(.open) .bf-team').display).toBe('none');
});

test('a phone on its side shows the team totals only when the stats panel is opened', () => {
  expect(containerDecls(/max-height: 440px/, '.bf-stats:not(.open) .bf-team').display).toBe('none');
});

test('the nav is icons only on a portrait phone too', () => {
  expect(containerDecls(/^hud \(max-aspect-ratio: 1\/1\)$/, '.bf-nav-label').display).toBe('none');
});

test('a phone on its side shows the nav as icons only', () => {
  expect(containerDecls(/max-height: 440px/, '.bf-nav-label').display).toBe('none');
});

test('both phone tiers show the stats sheet handle (the desktop hides it)', () => {
  expect(containerDecls(/^hud \(max-aspect-ratio: 1\/1\)$/, '.bf-stats-handle').display).toBe('flex');
  expect(containerDecls(/max-height: 440px/, '.bf-stats-handle').display).toBe('flex');
});

test('a portrait tablet has room for team and board in a right column over the sky: no sheet', () => {
  const tablet = /max-aspect-ratio: 1\/1\) and \(min-width: 640px/;

  expect(containerDecls(tablet, '.bf-stats').display).toBe('contents');
  expect(containerDecls(tablet, '.bf-stats-handle').display).toBe('none');
  expect(containerDecls(tablet, '.bf-stats:not(.open) .bf-team').display).toBe('block');
});

/** Declarations a top-level rule gets for one of its selectors. */
function rootDecls(selector) {
  const root = postcss.parse(readFileSync('resources/css/battlefield-hud.css', 'utf8'));
  const decls = {};
  root.walkRules(rule => {
    if (rule.parent.type === 'root' && rule.selectors.includes(selector)) {
      rule.walkDecls(d => { decls[d.prop] = d.value; });
    }
  });

  return decls;
}

test('the game page never pans, rubber-bands or zooms (a double tap or a pinch on iOS)', () => {
  for (const selector of ['html', 'body']) {
    expect(rootDecls(selector).overflow, selector).toBe('hidden');
    expect(rootDecls(selector)['overscroll-behavior'], selector).toBe('none');
  }
  expect(rootDecls('html')['touch-action']).toBe('pan-x pan-y');
  expect(rootDecls('#battlefield-mount')['touch-action']).toBe('none');
});

test('the HUD keeps clear of the notch and rounded corners now that the arena fills the whole screen', () => {
  const inset = rootDecls('.bf-hud-in').inset ?? '';

  for (const side of ['top', 'right', 'bottom', 'left']) {
    expect(inset).toContain(`env(safe-area-inset-${side})`);
  }
});
