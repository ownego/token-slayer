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
