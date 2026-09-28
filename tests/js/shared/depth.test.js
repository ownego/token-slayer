import { expect, test, vi } from 'vitest';
import { setDepthIfChanged } from '@battlefield/shared/depth.js';

test('skips the setter when the depth is already that value', () => {
  const obj = { depth: 5, setDepth: vi.fn(function (d) { this.depth = d; return this; }) };
  expect(setDepthIfChanged(obj, 5)).toBe(false);
  expect(obj.setDepth).not.toHaveBeenCalled();
});

test('writes through setDepth when the value differs', () => {
  const obj = { depth: 5, setDepth: vi.fn(function (d) { this.depth = d; return this; }) };
  expect(setDepthIfChanged(obj, 7)).toBe(true);
  expect(obj.setDepth).toHaveBeenCalledWith(7);
});
