/**
 * Splits a layer's draw order into runs for lite mode's baking: consecutive
 * still pieces form one run (baked into a single image), each live piece —
 * one that moves every frame — stays its own. The order is kept exactly, so
 * whatever was behind something stays behind it.
 *
 * @template T
 * @param {T[]} items The layer's children, back to front.
 * @param {function(T): boolean} isLive
 * @return {Array<{live: boolean, items: T[]}>}
 */
export function bakeRuns(items, isLive) {
  const runs = [];
  for (const item of items) {
    const live = isLive(item);
    const last = runs[runs.length - 1];
    if (last && !live && !last.live) {
      last.items.push(item);
    } else {
      runs.push({ live, items: [item] });
    }
  }

  return runs;
}

/**
 * The rect a still run bakes into: the smallest whole-pixel rect covering
 * its pieces, clipped to the world — or the whole world when that rect
 * covers most of it anyway. A piece without getBounds (a Graphics)
 * uses the `bakeBounds` it was given, else the whole world.
 *
 * @param {Array<object>} items
 * @param {{width: number, height: number}} world
 * @return {{x: number, y: number, w: number, h: number}}
 */
export function runBounds(items, { width, height }) {
  let x0 = width;
  let y0 = height;
  let x1 = 0;
  let y1 = 0;
  for (const o of items) {
    const b = o.getBounds ? o.getBounds() : (o.bakeBounds ?? { x: 0, y: 0, right: width, bottom: height });
    x0 = Math.min(x0, b.x);
    y0 = Math.min(y0, b.y);
    x1 = Math.max(x1, b.right);
    y1 = Math.max(y1, b.bottom);
  }
  const x = Math.max(0, Math.floor(x0));
  const y = Math.max(0, Math.floor(y0));
  const w = Math.max(1, Math.min(width, Math.ceil(x1)) - x);
  const h = Math.max(1, Math.min(height, Math.ceil(y1)) - y);
  // most of the world anyway: take all of it, so an additive halo reaching
  // past the run's pieces isn't clipped to a straight edge
  if (w * h > (width * height) / 2) {
    return { x: 0, y: 0, w: width, h: height };
  }

  return { x, y, w, h };
}

/**
 * Redraws a still run into its render texture: each piece shown just while
 * it is drawn, at its own place relative to the texture's corner (x0, y0).
 * Phaser's `draw(entries, x, y)` puts a Game Object AT x/y, not offset by
 * it, so pieces go one by one with their own position.
 *
 * @param {{clear: function(): *, draw: function(object, number, number): *}} rt
 * @param {Array<object>} items
 * @param {number} x0
 * @param {number} y0
 * @return {void}
 */
export function bakeInto(rt, items, x0, y0) {
  rt.clear();
  for (const o of items) {
    o.setVisible(true);
    rt.draw(o, o.x - x0, o.y - y0);
    o.setVisible(false);
  }
}

/**
 * Reorders a layer for baking: `floating` pieces (live, drawn every frame)
 * move up to just under the `top` ones, keeping their own order, so the
 * still pieces they sat between bake as one run. A still piece with an
 * additive blend (a brazier's glow) split off into its own run has nothing
 * under it in its texture and bakes as a hard-edged box.
 *
 * @template T
 * @param {T[]} items Back to front.
 * @param {T[]} floating
 * @param {T[]} top Pieces that stay on top of everything.
 * @return {T[]}
 */
export function floatLive(items, floating, top) {
  const moved = new Set([...floating, ...top]);

  return [...items.filter(o => !moved.has(o)), ...items.filter(o => floating.includes(o)), ...items.filter(o => top.includes(o))];
}
