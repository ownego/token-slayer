// @vitest-environment jsdom
import { expect, test } from 'vitest';
import { draw, SEQ } from '@battlefield/sheet/clawd-kit.js';

const svgWith = () => document.createElementNS('http://www.w3.org/2000/svg', 'svg');

test('the default pose draws a body with two dark eye cells per eye', () => {
  const svg = svgWith();
  draw(svg, { pose: 'default', offset: 0 });

  expect(svg.querySelectorAll('rect.b').length).toBeGreaterThan(20);
  expect(svg.querySelectorAll('rect.e').length).toBeGreaterThan(0);
});

test('x shifts the whole figure by two svg units per column', () => {
  const a = svgWith();
  const b = svgWith();
  draw(a, { pose: 'default', offset: 0, x: 0 });
  draw(b, { pose: 'default', offset: 0, x: 3 });

  const firstX = svg => Math.min(...[...svg.querySelectorAll('rect')].map(r => Number(r.getAttribute('x'))));
  expect(firstX(b) - firstX(a)).toBe(6);
});

test('a crouched frame puffs dust on both sides', () => {
  const svg = svgWith();
  draw(svg, SEQ.JUMP[0]);

  expect(svg.querySelectorAll('text.poof')).toHaveLength(2);
});
