import { expect, test, vi } from 'vitest';
import { createGlyphCache, glyphKey } from '@battlefield/shared/flair-glyphs.js';

test('each unique glyph is created once, however often it is asked for', () => {
  const made = new Set();
  const create = vi.fn(key => made.add(key));
  const cache = createGlyphCache({ exists: k => made.has(k), create });

  for (let i = 0; i < 4; i++) { cache.prewarm('FABLE', '#d946ef', 14); }

  expect(create).toHaveBeenCalledTimes(6);   // F A B L E ✦
});

test('keys differ by colour and size so a recoloured flair does not reuse the wrong texture', () => {
  expect(glyphKey('F', '#d946ef', 14)).not.toBe(glyphKey('F', '#ffffff', 14));
  expect(glyphKey('F', '#d946ef', 14)).not.toBe(glyphKey('F', '#d946ef', 16));
});

test('spaces are never baked', () => {
  const create = vi.fn();
  createGlyphCache({ exists: () => false, create }).prewarm('A B', '#fff', 12);
  expect(create.mock.calls.map(c => c[1])).not.toContain(' ');
});
