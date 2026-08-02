import { describe, expect, test, vi } from 'vitest';
import { centeredScaleFit, drawFighterPreview, findOpaqueBounds } from '@battlefield/fighter/preview.js';
import { PREVIEW_SPRITE_SCALE } from '@battlefield/constants.js';

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

describe('centeredScaleFit', () => {
  test('scales the source by the given fixed factor and centers it in the destination, never covering/stretching to fill it', () => {
    // 5x6 source at scale 2 into a 96x96 destination: 10x12, centered
    expect(centeredScaleFit(5, 6, 2, 96, 96)).toEqual({ x: 43, y: 42, width: 10, height: 12 });
  });

  test('a scaled source larger than the destination overhangs on both sides equally (left to the caller/canvas to clip)', () => {
    // 60x30 source at scale 2 into a 60x60 destination: 120x60, overhangs 30px each side on X,
    // and the scaled height (60) exactly matches the destination height, so no Y offset
    expect(centeredScaleFit(60, 30, 2, 60, 60)).toEqual({ x: -30, y: 0, width: 120, height: 60 });
  });

  test('scale 1 with a source exactly matching the destination needs no centering offset', () => {
    expect(centeredScaleFit(50, 50, 1, 50, 50)).toEqual({ x: 0, y: 0, width: 50, height: 50 });
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
  test('probes the frame for its real silhouette, then draws only that region at PREVIEW_SPRITE_SCALE, centered (not stretched to fill the destination)', () => {
    const frame = { cutX: 10, cutY: 20, cutWidth: 30, cutHeight: 40, source: { image: 'IMG' } };
    const game = makeGame(frame);
    const canvas = makeCanvas(96, 96, { x: 5, y: 10, width: 5, height: 6 });

    const result = drawFighterPreview(game, canvas, 'werebear');

    expect(result).toBe(true);
    expect(game.textures.getFrame).toHaveBeenCalledWith('fighters', 'werebear-idle-0');
    // probe pass: draws the whole untrimmed slot into the resized canvas
    expect(canvas._ctx.drawImage).toHaveBeenNthCalledWith(1, 'IMG', 10, 20, 30, 40, 0, 0, 30, 40);
    // final pass: only the detected silhouette (offset by cutX/cutY), scaled by the fixed
    // PREVIEW_SPRITE_SCALE (2.4) and centered — not stretched to fill 0,0,96,96, which would both
    // warp a non-square silhouette's aspect ratio and render it bigger than the live Phaser tile
    const [img, sx, sy, sw, sh, dx, dy, dw, dh] = canvas._ctx.drawImage.mock.calls[1];
    expect([img, sx, sy, sw, sh]).toEqual(['IMG', 15, 30, 5, 6]);
    const expectedWidth = 5 * PREVIEW_SPRITE_SCALE;
    const expectedHeight = 6 * PREVIEW_SPRITE_SCALE;
    expect(dx).toBeCloseTo((96 - expectedWidth) / 2);
    expect(dy).toBeCloseTo((96 - expectedHeight) / 2);
    expect(dw).toBeCloseTo(expectedWidth);
    expect(dh).toBeCloseTo(expectedHeight);
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
