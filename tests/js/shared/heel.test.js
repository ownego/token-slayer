import { expect, test } from 'vitest';
import { findHeel, heelToLocal } from '@battlefield/shared/heel.js';

function frame(pixels) {           // pixels: [[x, y], ...] opaque
  const a = new Uint8ClampedArray(100 * 100 * 4);
  for (const [x, y] of pixels) { a[(y * 100 + x) * 4 + 3] = 255; }
  return a;
}

test('the heel is the rear-most inked pixel in the rows just above the feet', () => {
  // body 45..55, feet row 57 spans 44..54, a spear tip on row 57 at x=62 is outside the box
  const px = [];
  for (let y = 40; y <= 57; y++) { for (let x = 45; x <= 55; x++) { px.push([x, y]); } }
  for (let x = 44; x <= 54; x++) { px.push([x, 57]); }
  px.push([62, 57]);
  expect(findHeel(frame(px), { x0: 43, x1: 58 })).toEqual({ x: 44, y: 58 });
});

test('falls back to the box centre when the frame is empty', () => {
  expect(findHeel(frame([]), { x0: 40, x1: 60 })).toEqual({ x: 47, y: 57 });
});

test('heelToLocal mirrors the x offset for a fighter facing left', () => {
  expect(heelToLocal({ x: 44, y: 58 }, 2, 1)).toEqual({ dx: -12, dy: 16 });
  expect(heelToLocal({ x: 44, y: 58 }, 2, -1)).toEqual({ dx: 12, dy: 16 });
});
