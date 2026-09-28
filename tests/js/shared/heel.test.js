import { expect, test } from 'vitest';
import { findHeel, heelToLocal, heelWorldOffset } from '@battlefield/shared/heel.js';

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

test('a weapon reaching below the feet inside the body box is never taken for the heel', () => {
  // priest/wizard staffs, skeleton-archer's bow, swordsman's blade touch the
  // ground a row or two under the feet: the sparks flew from the weapon tip
  const px = [];
  for (let y = 40; y <= 56; y++) { for (let x = 46; x <= 54; x++) { px.push([x, y]); } }
  px.push([56, 57], [56, 58]); // a staff, 1px wide, below the feet on the front side
  px.push([51, 57], [52, 57], [53, 57], [52, 58], [53, 58], [53, 59]); // a bow tip, front half
  expect(findHeel(frame(px), { x0: 44, x1: 58 })).toEqual({ x: 46, y: 57 });
});

test('the heel offset follows the fighter\'s whole size: its damage growth times its body scale', () => {
  // only the body's scale was used, so a fighter grown by its damage (up to
  // 1.4x) threw its sparks from inside its own legs
  const fighter = { sprite: { scaleX: 1.4 }, body: { scaleX: 2, flipX: false } };
  expect(heelWorldOffset({ x: 44, y: 58 }, fighter)).toEqual(heelToLocal({ x: 44, y: 58 }, 2.8, 1));
  expect(heelWorldOffset({ x: 44, y: 58 }, { ...fighter, body: { scaleX: 2, flipX: true } })).toEqual(heelToLocal({ x: 44, y: 58 }, 2.8, -1));
});
