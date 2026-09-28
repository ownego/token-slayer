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
