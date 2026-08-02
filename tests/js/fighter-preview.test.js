import { describe, expect, test, vi } from 'vitest';
import { coverFit, drawFighterPreview, findOpaqueBounds } from '@battlefield/fighter/preview.js';

/**
 * Builds a fully-transparent width*height RGBA buffer with a single opaque
 * rectangle set within it, for feeding into findOpaqueBounds/getImageData
 * mocks deterministically.
 */
function bufferWithOpaqueRect(width, height, rect) {
  const data = new Uint8ClampedArray(width * height * 4);
  for (let py = rect.y; py < rect.y + rect.height; py++) {
    for (let px = rect.x; px < rect.x + rect.width; px++) {
      data[(py * width + px) * 4 + 3] = 255;
    }
  }
  return data;
}

describe('findOpaqueBounds', () => {
  test('returns the tight bounding box of non-transparent pixels', () => {
    const data = bufferWithOpaqueRect(30, 40, { x: 5, y: 10, width: 5, height: 6 });

    expect(findOpaqueBounds(data, 30, 40)).toEqual({ x: 5, y: 10, width: 5, height: 6 });
  });

  test('falls back to the full region when every pixel is transparent', () => {
    const data = new Uint8ClampedArray(30 * 40 * 4);

    expect(findOpaqueBounds(data, 30, 40)).toEqual({ x: 0, y: 0, width: 30, height: 40 });
  });

  test('ignores near-zero alpha noise (threshold > 8)', () => {
    const data = new Uint8ClampedArray(10 * 10 * 4);
    data[3] = 8;

    expect(findOpaqueBounds(data, 10, 10)).toEqual({ x: 0, y: 0, width: 10, height: 10 });
  });
});

describe('coverFit', () => {
  test('scales a narrower-than-tall source uniformly to cover a square destination, cropping width', () => {
    // 5x6 source into a 96x96 destination: scale = max(96/5, 96/6) = 19.2 on both axes
    const result = coverFit(5, 6, 96, 96);
    expect(result.x).toBeCloseTo(0);
    expect(result.y).toBeCloseTo(-9.6);
    expect(result.width).toBeCloseTo(96);
    expect(result.height).toBeCloseTo(115.2);
  });

  test('scales a wider-than-tall source uniformly to cover a square destination, cropping height', () => {
    // 60x30 source into a 60x60 destination: scale = max(60/60, 60/30) = 2 on both axes
    expect(coverFit(60, 30, 60, 60)).toEqual({ x: -30, y: 0, width: 120, height: 60 });
  });

  test('an already-matching aspect ratio needs no cropping', () => {
    expect(coverFit(10, 10, 50, 50)).toEqual({ x: 0, y: 0, width: 50, height: 50 });
  });
});

function makeGame(frame) {
  return { textures: { getFrame: vi.fn(() => frame) } };
}

function makeCanvas(width, height, opaqueRect) {
  const ctx = {
    clearRect: vi.fn(),
    drawImage: vi.fn(),
    // Mirrors the real Canvas API: the returned buffer is shaped to
    // whatever region is actually requested (here, the probe pass's
    // resized cutWidth/cutHeight), not the canvas's original destination size.
    getImageData: vi.fn((x, y, w, h) => ({ data: bufferWithOpaqueRect(w, h, opaqueRect) })),
  };
  const canvas = { width, height, getContext: vi.fn(() => ctx), _ctx: ctx };
  return canvas;
}

describe('drawFighterPreview', () => {
  test('probes the frame for its real silhouette, then draws only that region cover-fit (not stretched) to the destination size', () => {
    const frame = { cutX: 10, cutY: 20, cutWidth: 30, cutHeight: 40, source: { image: 'IMG' } };
    const game = makeGame(frame);
    const canvas = makeCanvas(96, 96, { x: 5, y: 10, width: 5, height: 6 });

    const result = drawFighterPreview(game, canvas, 'werebear');

    expect(result).toBe(true);
    expect(game.textures.getFrame).toHaveBeenCalledWith('fighters', 'werebear-idle-0');
    // probe pass: draws the whole untrimmed slot into the resized canvas
    expect(canvas._ctx.drawImage).toHaveBeenNthCalledWith(1, 'IMG', 10, 20, 30, 40, 0, 0, 30, 40);
    // final pass: only the detected silhouette (offset by cutX/cutY), scaled uniformly via coverFit — not
    // stretched to 0,0,96,96, which would warp a non-square silhouette's aspect ratio
    const [img, sx, sy, sw, sh, dx, dy, dw, dh] = canvas._ctx.drawImage.mock.calls[1];
    expect([img, sx, sy, sw, sh]).toEqual(['IMG', 15, 30, 5, 6]);
    expect(dx).toBeCloseTo(0);
    expect(dy).toBeCloseTo(-9.6);
    expect(dw).toBeCloseTo(96);
    expect(dh).toBeCloseTo(115.2);
    // canvas dimensions are restored to their original destination size
    expect(canvas.width).toBe(96);
    expect(canvas.height).toBe(96);
  });

  test('returns false and does not draw when the frame is missing', () => {
    const game = makeGame(null);
    const canvas = makeCanvas(96, 96, { x: 0, y: 0, width: 0, height: 0 });

    const result = drawFighterPreview(game, canvas, 'unknown-key');

    expect(result).toBe(false);
    expect(canvas._ctx.drawImage).not.toHaveBeenCalled();
  });

  test('returns false when game is not booted yet', () => {
    const canvas = makeCanvas(96, 96, { x: 0, y: 0, width: 0, height: 0 });

    expect(drawFighterPreview(null, canvas, 'werebear')).toBe(false);
    expect(canvas._ctx.drawImage).not.toHaveBeenCalled();
  });
});
