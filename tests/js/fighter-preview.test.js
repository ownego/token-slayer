import { describe, expect, test, vi } from 'vitest';
import { drawFighterPreview } from '@battlefield/fighter/preview.js';

function makeGame(frame) {
  return { textures: { getFrame: vi.fn(() => frame) } };
}

function makeCanvas() {
  const ctx = { clearRect: vi.fn(), drawImage: vi.fn() };
  return { width: 96, height: 96, getContext: vi.fn(() => ctx), _ctx: ctx };
}

describe('drawFighterPreview', () => {
  test('draws the frame onto the canvas and returns true when the frame exists', () => {
    const frame = { cutX: 10, cutY: 20, cutWidth: 30, cutHeight: 40, source: { image: 'IMG' } };
    const game = makeGame(frame);
    const canvas = makeCanvas();

    const result = drawFighterPreview(game, canvas, 'werebear');

    expect(result).toBe(true);
    expect(game.textures.getFrame).toHaveBeenCalledWith('fighters', 'werebear-idle-0');
    expect(canvas._ctx.drawImage).toHaveBeenCalledWith('IMG', 10, 20, 30, 40, 0, 0, 96, 96);
  });

  test('returns false and does not draw when the frame is missing', () => {
    const game = makeGame(null);
    const canvas = makeCanvas();

    const result = drawFighterPreview(game, canvas, 'unknown-key');

    expect(result).toBe(false);
    expect(canvas._ctx.drawImage).not.toHaveBeenCalled();
  });

  test('returns false when game is not booted yet', () => {
    const canvas = makeCanvas();

    expect(drawFighterPreview(null, canvas, 'werebear')).toBe(false);
    expect(canvas._ctx.drawImage).not.toHaveBeenCalled();
  });
});
