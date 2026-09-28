/**
 * Converts a DOM rect (CSS px, page-relative) into world coordinates through
 * the canvas's CSS-to-world scale, padded outward on every edge.
 *
 * @param {{left:number, right:number, top:number, bottom:number}} rect
 * @param {{left:number, top:number, width:number}} canvasRect
 * @param {number} worldWidth
 * @param {number} [pad]
 * @return {{l:number, r:number, t:number, b:number}}
 */
export function domToWorld(rect, canvasRect, worldWidth, pad = 6) {
  const k = worldWidth / canvasRect.width;
  return {
    l: (rect.left - canvasRect.left) * k - pad,
    r: (rect.right - canvasRect.left) * k + pad,
    t: (rect.top - canvasRect.top) * k - pad,
    b: (rect.bottom - canvasRect.top) * k + pad,
  };
}

/**
 * A fighter's occupied footprint, as offsets from its position.
 * @param {{x:number, y:number}} f
 * @param {{halfW:number, top:number, bottom:number}} sizes
 * @return {{l:number, r:number, t:number, b:number}}
 */
export function footprint(f, { halfW, top, bottom }) {
  return { l: f.x - halfW, r: f.x + halfW, t: f.y + top, b: f.y + bottom };
}

/**
 * Pushes overlapping footprints apart along their shallower axis and out of
 * HUD zones; with no conflict a fighter sits on its anchor (x, y).
 * @param {{x:number,y:number,nx?:number,ny?:number}[]} fighters
 * @param {{l:number,r:number,t:number,b:number}[]} zones
 * @param {{halfW:number, top:number, bottom:number}} sizes
 * @param {{iterations?:number}} [opts]
 * @return {object[]} the same array, with nx/ny set
 */
export function resolveSpacing(fighters, zones, sizes, { iterations = 3 } = {}) {
  fighters.forEach(f => { f.nx = f.x; f.ny = f.y; });
  const w = sizes.halfW * 2, h = sizes.bottom - sizes.top;
  for (let it = 0; it < iterations; it++) {
    for (let i = 0; i < fighters.length; i++) {
      for (let j = i + 1; j < fighters.length; j++) {
        const A = fighters[i], B = fighters[j];
        const ox = w - Math.abs(A.nx - B.nx), oy = h - Math.abs(A.ny - B.ny);
        if (ox > 0 && oy > 0) {
          if (ox < oy) { const s = Math.sign(A.nx - B.nx) || 1; A.nx += s * ox / 2; B.nx -= s * ox / 2; }
          else { const s = Math.sign(A.ny - B.ny) || 1; A.ny += s * oy / 2; B.ny -= s * oy / 2; }
        }
      }
    }
    for (const f of fighters) {
      for (const z of zones) {
        const b = footprint({ x: f.nx, y: f.ny }, sizes);
        if (b.r > z.l && b.l < z.r && b.b > z.t && b.t < z.b) {
          const dl = b.r - z.l, dr = z.r - b.l, db = z.b - b.t;
          const m = Math.min(dl, dr, db);
          if (m === db) { f.ny += db; } else if (m === dl) { f.nx -= dl; } else { f.nx += dr; }
        }
      }
    }
  }
  return fighters;
}
