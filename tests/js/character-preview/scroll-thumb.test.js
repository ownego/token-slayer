import { describe, expect, test } from 'vitest';
import { thumbGeometry, scrollTopForThumb } from '@battlefield/character-preview/scroll-thumb.js';

describe('thumbGeometry', () => {
  test('content that fits needs no thumb at all', () => {
    expect(thumbGeometry({ scrollTop: 0, scrollHeight: 400, clientHeight: 400, trackHeight: 400 }).visible).toBe(false);
  });

  test('thumb size is the visible share of the content, offset tracks the scroll', () => {
    // half the content is visible -> thumb is half the track
    expect(thumbGeometry({ scrollTop: 0, scrollHeight: 800, clientHeight: 400, trackHeight: 400 }))
      .toEqual({ visible: true, size: 200, offset: 0 });
    // scrolled to the bottom -> thumb touches the bottom of the track
    expect(thumbGeometry({ scrollTop: 400, scrollHeight: 800, clientHeight: 400, trackHeight: 400 }))
      .toEqual({ visible: true, size: 200, offset: 200 });
  });

  test('a very long list still gets a grabbable thumb (min size), and it still reaches the bottom', () => {
    const g = thumbGeometry({ scrollTop: 9600, scrollHeight: 10000, clientHeight: 400, trackHeight: 400, minSize: 24 });
    expect(g.size).toBe(24);
    expect(g.offset).toBe(376);
  });

  test('overscroll (elastic bounce) never pushes the thumb outside the track', () => {
    expect(thumbGeometry({ scrollTop: -30, scrollHeight: 800, clientHeight: 400, trackHeight: 400 }).offset).toBe(0);
    expect(thumbGeometry({ scrollTop: 450, scrollHeight: 800, clientHeight: 400, trackHeight: 400 }).offset).toBe(200);
  });
});

describe('scrollTopForThumb', () => {
  const box = { scrollHeight: 800, clientHeight: 400, trackHeight: 400 };

  test('is the inverse of thumbGeometry: dragging the thumb to an offset scrolls to match', () => {
    expect(scrollTopForThumb({ ...box, offset: 0 })).toBe(0);
    expect(scrollTopForThumb({ ...box, offset: 100 })).toBe(200);
    expect(scrollTopForThumb({ ...box, offset: 200 })).toBe(400);
  });

  test('dragging past either end clamps to the first/last row', () => {
    expect(scrollTopForThumb({ ...box, offset: -50 })).toBe(0);
    expect(scrollTopForThumb({ ...box, offset: 999 })).toBe(400);
  });

  test('uses the same min-size thumb as thumbGeometry so drag and display agree', () => {
    const long = { scrollHeight: 10000, clientHeight: 400, trackHeight: 400, minSize: 24 };
    expect(scrollTopForThumb({ ...long, offset: 376 })).toBe(9600);
  });
});
