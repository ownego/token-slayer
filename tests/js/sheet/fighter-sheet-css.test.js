import { readFileSync } from 'node:fs';
import postcss from 'postcss';
import { expect, test } from 'vitest';

test('no sheet rule sets translate alongside transform (the CSS minifier silently drops the translate)', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  const offenders = [];
  root.walkRules(rule => {
    const props = new Set();
    rule.walkDecls(d => props.add(d.prop));
    if (props.has('transform') && props.has('translate')) {
      offenders.push(rule.selector);
    }
  });

  expect(offenders).toEqual([]);
});

test('on a phone the sheet spans the whole screen: the last width rule under 640px wins over the desktop min(1240px, …)', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  let last = null;
  root.walkDecls('width', d => {
    if (d.parent.selector !== '.fs .sheet') {
      return;
    }
    const media = d.parent.parent.type === 'atrule' ? d.parent.parent.params : null;
    if (!media || media.includes('640px')) {
      last = { media, value: d.value };
    }
  });

  expect(last).toEqual({ media: '(max-width: 640px)', value: '100vw' });
});

test('the period ink slides by transform once ready, and the selected tab lets it show through', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  const decl = (selector, prop) => {
    let value = null;
    root.walkRules(rule => {
      if (rule.selector === selector) {
        rule.walkDecls(prop, d => { value = d.value; });
      }
    });
    return value;
  };

  expect(decl('.fs .period-ink', 'position')).toBe('absolute');
  expect(decl('.fs .period-ink.ready', 'transition')).toMatch(/transform/);
  expect(decl('.fs .periods:has(.period-ink) .period[aria-selected="true"]', 'background')).toBe('transparent');
});

test('the sheet content fades in by opacity alone: a transform on it would re-anchor the fixed overlay and shift the sheet', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  const props = new Set();
  root.walkRules(rule => {
    if (/fs-content-(enter|from|to)/.test(rule.selector)) {
      rule.walkDecls(d => props.add(d.prop));
    }
  });

  expect([...props].sort()).toEqual(['opacity', 'transition']);
});

test('the Clawd loader bounces and leaps by transform only, so it stays smooth', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  const bad = [];
  let leap = null;
  root.walkAtRules('keyframes', at => {
    if (at.params.startsWith('fs-hop')) {
      at.walkDecls(d => {
        if (!['transform', 'opacity'].includes(d.prop)) {
          bad.push(`${at.params} ${d.prop}`);
        }
      });
    }
  });
  root.walkRules(rule => {
    if (rule.selector === '.fs .ts-hop.is-leaping .ts-hop-mover') {
      rule.walkDecls('transition', d => { leap = d.value; });
    }
  });

  expect(bad).toEqual([]);
  expect(leap).toMatch(/^transform /);
});

/** The last value a `.fs …` selector gets for a prop inside @media blocks whose params match. */
function mediaDecl(params, selector, prop) {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  let value = null;
  root.walkAtRules('media', at => {
    if (!params.test(at.params)) {
      return;
    }
    at.walkRules(rule => {
      if (rule.selectors.includes(selector)) {
        rule.walkDecls(prop, d => { value = d.value; });
      }
    });
  });

  return value;
}

test('on a phone the close button pins to the header corner instead of wrapping onto a row of its own', () => {
  expect(mediaDecl(/max-width: 640px/, '.fs .close', 'position')).toBe('absolute');
  expect(mediaDecl(/max-width: 640px/, '.fs .sheet-head', 'position')).toBe('relative');
});

test('a phone on its side gets the whole screen, not a 330px-tall box with two scrollbars', () => {
  const shortLandscape = /max-height: 500px/;

  expect(mediaDecl(shortLandscape, '.fs .sheet', 'height')).toBe('100dvh');
  expect(mediaDecl(shortLandscape, '.fs .sheet', 'width')).toBe('100vw');
  expect(mediaDecl(shortLandscape, '.fs .main', 'overflow-x')).toBe('hidden');
});

test('the full-screen phone sheet keeps its content clear of the notch and rounded corners', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  let padding = '';
  root.walkRules(rule => {
    if (rule.parent.type === 'root' && rule.selectors.includes('.fs .sheet')) {
      rule.walkDecls('padding', d => { padding = d.value; });
    }
  });

  for (const side of ['top', 'right', 'bottom', 'left']) {
    expect(padding).toContain(`env(safe-area-inset-${side})`);
  }
});

test('the build progress indicator stops moving when reduced motion is requested', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  let animation = null;
  root.walkAtRules('media', at => {
    if (!at.params.includes('prefers-reduced-motion: reduce')) {
      return;
    }
    at.walkRules('.fs .build-bar i', rule => {
      rule.walkDecls('animation', decl => { animation = decl.value; });
    });
  });

  expect(animation).toBe('none');
});

test('phone keeps preview actions above its measured toast clearance and hides the roster while editing', () => {
  expect(mediaDecl(/max-width: 640px/, '.fs .showcase .equip-row', 'position')).toBe('sticky');
  expect(mediaDecl(/max-width: 640px/, '.fs .char-body:has(.showcase.editing) .roster', 'display')).toBe('none');

  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  let bottom;
  root.walkRules('.fs .toast', rule => rule.walkDecls('bottom', decl => { bottom = decl.value; }));
  expect(bottom).toContain('var(--bar-h)');
});

test('the landscape drawer scrolls beneath a 120px stage', () => {
  const landscape = /max-height: 500px/;
  expect(Number.parseInt(mediaDecl(landscape, '.fs .show-stage', 'height'), 10)).toBeLessThanOrEqual(120);
  expect(mediaDecl(landscape, '.fs .cce-drawer', 'overflow-y')).toBe('auto');
});

test('editor controls have touch targets and its build action stays in the drawer', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  const value = selector => {
    let height;
    root.walkRules(rule => {
      if (rule.selectors.includes(selector)) {
        rule.walkDecls('min-height', decl => { height = decl.value; });
      }
    });
    return height;
  };
  expect(value('.fs .cce-drawer .btn')).toBe('44px');
  expect(value('.fs .inspector .btn')).toBe('44px');
  expect(value('.fs .slot-add')).toBe('44px');
  expect(mediaDecl(/max-width: 640px/, '.fs .cce-form .cce-row:last-child', 'position')).toBe('sticky');
});

test('every control whose label changes at runtime reserves the width of its longest label, so toggling never shifts its neighbours', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  const reserved = new Set();
  root.walkRules(rule => {
    rule.walkDecls('min-width', () => reserved.add(rule.selector));
  });
  const joined = [...reserved].join(' , ');

  for (const selector of ['#inspect-toggle', '#inspect-label', '.btn-equip', '#discard-draft', '.cce-build']) {
    expect(joined, `${selector} has no min-width`).toContain(selector);
  }
});

test('a custom minion on the Profile stage is 88px and steps its strip by the variables the blade sets', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  const decls = {};
  root.walkRules('.fs .mini-minion.m-custom', rule => {
    if (rule.parent.type === 'root') {
      rule.walkDecls(d => { decls[d.prop] = d.value; });
    }
  });

  expect(decls['background-image']).toBe('var(--strip)');
  expect(decls['background-size']).toContain('var(--frames)');
  expect(decls.animation).toMatch(/var\(--frames\).*var\(--rate\)|var\(--rate\).*var\(--frames\)/);
  expect(root.toString()).toMatch(/\.fs \.mini-minion\.m-custom \{[^}]*width: 88px/);
});

test('a custom minion holds its first frame under reduced motion', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  let found = null;
  root.walkAtRules('media', at => {
    if (at.params.includes('prefers-reduced-motion: reduce')) {
      at.walkRules(rule => {
        if (rule.selectors.includes('.fs .mini-minion.m-custom') || rule.selectors.includes('.fs .minion-field-view .mfv-sprite')) {
          rule.walkDecls('animation', d => { found = d.value; });
        }
      });
    }
  });

  expect(found).toBe('none');
});

test('the Forge tabs and the card buttons are at least 44px to hit', () => {
  const root = postcss.parse(readFileSync('resources/css/fighter-sheet.css', 'utf8'));
  let height = null;
  root.walkRules('.fs .forge-tabs .sheet-tab', rule => {
    if (rule.parent.type === 'root') {
      rule.walkDecls('min-height', d => { height = d.value; });
    }
  });

  expect(height).toBe('44px');
});
