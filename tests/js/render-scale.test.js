import { expect, test } from 'vitest';
import { LAYOUTS } from '@battlefield/config.js';
import { canvasSizeFor } from '@battlefield/render-scale.js';

test('renders the canvas at logical size times the render scale the available width affords', () => {
  expect(canvasSizeFor(1920, 1, LAYOUTS.landscape)).toEqual({ width: 1920, height: 1080, renderScale: 2 });
});

test('multiplies by devicePixelRatio', () => {
  expect(canvasSizeFor(960, 2, LAYOUTS.landscape)).toEqual({ width: 1920, height: 1080, renderScale: 2 });
});

test('caps the render scale at 2.5x', () => {
  expect(canvasSizeFor(4000, 2, LAYOUTS.landscape).renderScale).toBe(2.5);
});

test('never renders below logical size', () => {
  expect(canvasSizeFor(300, 1, LAYOUTS.landscape)).toEqual({ width: 960, height: 540, renderScale: 1 });
});

// An orientation flip re-sizes the running game. It used to set the bare
// logical size while the camera stayed zoomed by the boot-time render scale,
// so the whole world rendered blown up around the boss until a reload.
test('sizes a portrait flip against the portrait layout, not the bare logical size', () => {
  expect(canvasSizeFor(1080, 1, LAYOUTS.portrait)).toEqual({ width: 1080, height: 1920, renderScale: 2 });
});
