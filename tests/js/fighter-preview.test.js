import { describe, expect, test, vi } from 'vitest';
import { centeredScaleFit, drawFighterPreview } from '@battlefield/fighter/preview.js';
import { PREVIEW_SPRITE_SCALE } from '@battlefield/constants.js';

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

function makeCanvas(width, height) {
  const ctx = { clearRect: vi.fn(), drawImage: vi.fn() };
  return { width, height, getContext: vi.fn(() => ctx), _ctx: ctx };
}

describe('drawFighterPreview', () => {
  test('draws the whole untrimmed frame slot at PREVIEW_SPRITE_SCALE, centered on the destination — the same centering Phaser uses for the live tiles, so a character never shifts position when a tile switches between this static draw and the live one', () => {
    const frame = { cutX: 10, cutY: 20, cutWidth: 30, cutHeight: 40, source: { image: 'IMG' } };
    const game = makeGame(frame);
    const canvas = makeCanvas(96, 96);

    const result = drawFighterPreview(game, canvas, 'werebear');

    expect(result).toBe(true);
    expect(game.textures.getFrame).toHaveBeenCalledWith('fighters', 'werebear-idle-0');
    expect(canvas._ctx.clearRect).toHaveBeenCalledWith(0, 0, 96, 96);
    const [img, sx, sy, sw, sh, dx, dy, dw, dh] = canvas._ctx.drawImage.mock.calls[0];
    expect([img, sx, sy, sw, sh]).toEqual(['IMG', 10, 20, 30, 40]);
    const expectedWidth = 30 * PREVIEW_SPRITE_SCALE;
    const expectedHeight = 40 * PREVIEW_SPRITE_SCALE;
    expect(dx).toBeCloseTo((96 - expectedWidth) / 2);
    expect(dy).toBeCloseTo((96 - expectedHeight) / 2);
    expect(dw).toBeCloseTo(expectedWidth);
    expect(dh).toBeCloseTo(expectedHeight);
  });

  test('returns false and does not draw when the frame is missing', () => {
    const game = makeGame(null);
    const canvas = makeCanvas(96, 96);

    const result = drawFighterPreview(game, canvas, 'unknown-key');

    expect(result).toBe(false);
    expect(canvas._ctx.drawImage).not.toHaveBeenCalled();
  });

  test('returns false when game is not booted yet', () => {
    const canvas = makeCanvas(96, 96);

    expect(drawFighterPreview(null, canvas, 'werebear')).toBe(false);
    expect(canvas._ctx.drawImage).not.toHaveBeenCalled();
  });
});
