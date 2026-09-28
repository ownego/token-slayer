import { describe, expect, it } from 'vitest';
import { bannerPixels, bloomPixels, flowerBed, islandPixels, landmarkLayout, pixelate, stemPixels, windmillPixels } from '@battlefield/environment/landmarks.js';
import { LOGOS } from '@battlefield/environment/logo-paths.js';

const lcg = seed => {
  let s = seed;
  return () => (s = (s * 16807) % 2147483647) / 2147483647;
};

/**
 * An RGBA buffer `n` px square, opaque red wherever `on(x, y)` holds.
 */
function rgba(n, on) {
  const data = new Uint8ClampedArray(n * n * 4);
  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      if (on(x, y)) {
        data.set([255, 0, 0, 255], (y * n + x) * 4);
      }
    }
  }
  return data;
}

describe('pixelate', () => {
  it('turns a supersampled render into one cell per block, lit where it is mostly covered', () => {
    // 2x2 output at 4x supersampling: the left half fully covered, the right a thin sliver
    const g = pixelate(rgba(8, x => x < 4 || x === 7), 2, 4, 0.45);
    expect(g).toEqual([['#ff0000', null], ['#ff0000', null]]);
  });

  it('keeps the average colour of what covers a cell', () => {
    const data = rgba(4, () => true);
    for (let k = 0; k < 16; k += 2) {
      data.set([0, 0, 255, 255], k * 4);
    }
    expect(pixelate(data, 1, 4, 0.45)).toEqual([['#800080']]);
  });
});

describe('LOGOS', () => {
  it('holds a real 24-unit SVG path and a fill for every landmark', () => {
    ['claude', 'openai', 'cursor', 'gemini', 'antigravity'].forEach(name => {
      expect(LOGOS[name].path).toMatch(/^M[\d.]/);
      expect(LOGOS[name].fill.length).toBeGreaterThan(0);
    });
  });
});

describe('windmillPixels', () => {
  it('is a tower tapering to its cap with the hub near the top', () => {
    const { art, hub } = windmillPixels();
    const width = row => row.filter(Boolean).length;
    expect(width(art[art.length - 1])).toBeGreaterThan(width(art[Math.floor(art.length / 2)]));
    expect(hub.y).toBeLessThan(art.length / 3);
    expect(art[hub.y][hub.x]).toBeTruthy();
  });
});

describe('stemPixels', () => {
  it('a stalk `h` tall with leaves off both sides', () => {
    const g = stemPixels(20);
    expect(g).toHaveLength(20);
    const mid = Math.floor(g[0].length / 2);
    expect(g.every(row => row[mid])).toBe(true);
    expect(g.some(row => row.slice(0, mid).some(Boolean))).toBe(true);
    expect(g.some(row => row.slice(mid + 1).some(Boolean))).toBe(true);
  });
});

describe('flowerBed', () => {
  it('three flowers of different heights rooted on the hill crest around their spot', () => {
    const crest = x => 300 + (x % 7);
    const bed = flowerBed({ x: 900 }, 2, crest, lcg(2));
    expect(bed).toHaveLength(3);
    expect(new Set(bed.map(f => f.h)).size).toBe(3);
    bed.forEach(f => {
      expect(f.y).toBe(crest(f.x) + 2);
      expect(Math.abs(f.x - 900)).toBeLessThanOrEqual(20 * 2);
    });
  });
});

describe('bloomPixels', () => {
  it("the ChatGPT app icon as a flower head: the whole mark on a round #10a37f disc", () => {
    const mark = [['#ffffff', null], [null, '#ffffff']];
    const g = bloomPixels(mark);
    const n = g.length;
    expect(g[0]).toHaveLength(n);
    expect(g.flat().filter(c => c === '#ffffff')).toHaveLength(2);
    expect(g.flat().filter(c => c === '#10a37f').length).toBeGreaterThan(4);
    expect(g[0][0]).toBeNull();
    expect(g[n - 1][n - 1]).toBeNull();
  });
});

describe('bannerPixels', () => {
  const mark = [[null, '#eeeeee', null], ['#eeeeee', '#eeeeee', '#eeeeee'], [null, '#eeeeee', null]];

  it('a hanging banner: the whole mark on a dark cloth, a swallowtail at the foot', () => {
    const g = bannerPixels(mark);
    expect(g.flat().filter(c => c === '#eeeeee')).toHaveLength(5);
    const width = row => row.filter(Boolean).length;
    expect(width(g[g.length - 1])).toBeLessThan(width(g[0]));
    expect(g[g.length - 1][Math.floor(g[0].length / 2)]).toBeNull();
  });
});
describe('islandPixels', () => {
  it('a grassy top wider than its hanging rock, narrowing to a point', () => {
    const g = islandPixels(40, 22, lcg(3));
    const width = row => row.filter(Boolean).length;
    const top = Math.max(...g.slice(0, 5).map(width));
    const bottom = width(g[g.length - 2]);
    expect(top).toBeGreaterThan(30);
    expect(bottom).toBeLessThan(top / 3);
  });

});

describe('landmarkLayout', () => {
  const box = { width: 1600, horizonY: 400, keepX: 1184 };
  const hill = Array.from({ length: 1600 }, (_, x) => 340 + Math.round(20 * Math.sin(x / 90)));

  it('stands the windmill on the hill crest, clear of the castle and the boss column', () => {
    const l = landmarkLayout(box, x => hill[x]);
    expect(l.windmill.y).toBe(hill[l.windmill.x]);
    expect(Math.abs(l.windmill.x - box.keepX)).toBeGreaterThan(box.width * 0.2);
    expect(Math.abs(l.windmill.x - box.width / 2)).toBeGreaterThan(box.width * 0.15);
  });

  it('keeps every landmark out from under the HUD: the Team panel (left quarter) and the boss plate (top middle third)', () => {
    const l = landmarkLayout(box, x => hill[x]);
    [l.windmill, l.island, l.star].forEach(p => {
      expect(p.x).toBeGreaterThan(box.width * 0.25);
      const underPlate = p.x > box.width * 0.33 && p.x < box.width * 0.67 && p.y < box.horizonY * 0.36;
      expect(underPlate).toBe(false);
    });
  });

  it('plants the OpenAI flowers on the hill crest at the right, clear of the castle', () => {
    const l = landmarkLayout(box, x => hill[x]);
    expect(l.flowers.x).toBeGreaterThan(box.width * 0.85);
    expect(l.flowers.x).toBeLessThan(box.width);
    expect(Math.abs(l.flowers.x - box.keepX)).toBeGreaterThan(box.width * 0.1);
  });

  it('puts the island and the star in the sky, the island low enough for its mark to clear the boss plate', () => {
    const l = landmarkLayout(box, x => hill[x]);
    // low enough that the mark hovering over it clears the boss plate's bottom (36% of the horizon)
    expect(l.island.y).toBeGreaterThan(box.horizonY * 0.65);
    expect(l.island.y).toBeLessThan(box.horizonY * 0.8);
    expect(l.star.y).toBeLessThan(box.horizonY * 0.4);
    [l.island, l.star].forEach(p => {
      expect(p.x).toBeGreaterThan(0);
      expect(p.x).toBeLessThan(box.width);
    });
  });

  it('scales with the world box (portrait is narrower)', () => {
    const narrow = landmarkLayout({ width: 800, horizonY: 500, keepX: 592 }, () => 450);
    expect(narrow.windmill.x).toBeLessThan(800);
    expect(narrow.unit).toBeLessThanOrEqual(landmarkLayout(box, x => hill[x]).unit);
  });
});
