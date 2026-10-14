import { expect, test, vi } from 'vitest';
import { heelOf } from '@battlefield/sheet/stage-sparks.js';
import { ROSTER } from '@battlefield/sheet/sheet-roster.js';

test('the heel probe asks for a CORS image before setting src, so a strip on the media origin does not taint the canvas', () => {
  const seen = [];
  vi.stubGlobal('Image', class {
    set src(value) {
      seen.push({ crossOrigin: this.crossOrigin, src: value });
    }
  });

  heelOf(ROSTER[0].key);

  expect(seen).toHaveLength(1);
  expect(seen[0].crossOrigin).toBe('anonymous');
  vi.unstubAllGlobals();
});
