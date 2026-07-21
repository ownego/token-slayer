import { describe, expect, it } from 'vitest';
import { computeHudTop } from '../../resources/js/battlefield/hud-position.js';

describe('computeHudTop', () => {
  it('aligns with the in-canvas panel when there is no nav (IDE embed)', () => {
    expect(computeHudTop({ navBottom: null, canvasTop: 40, parentTop: 0, scale: 2 })).toBe(50);
  });

  it('drops below the nav stack when the nav would overlap the canvas top', () => {
    // Canvas starts at the viewport top, so the panel's y=5 would land under
    // the nav pills (which end at 70) — clear them instead.
    expect(computeHudTop({ navBottom: 70, canvasTop: 0, parentTop: 0, scale: 1 })).toBe(78);
  });

  it('keeps canvas alignment when the letterboxed canvas already starts below the nav', () => {
    expect(computeHudTop({ navBottom: 70, canvasTop: 200, parentTop: 0, scale: 1 })).toBe(205);
  });

  it('is measured relative to the offset parent, not the viewport', () => {
    expect(computeHudTop({ navBottom: 70, canvasTop: 0, parentTop: 30, scale: 1 })).toBe(48);
  });

  it('grows with the nav so an extra link never causes an overlap', () => {
    const oneLink = computeHudTop({ navBottom: 40, canvasTop: 0, parentTop: 0, scale: 1 });
    const twoLinks = computeHudTop({ navBottom: 70, canvasTop: 0, parentTop: 0, scale: 1 });

    expect(twoLinks).toBeGreaterThan(oneLink);
  });
});
