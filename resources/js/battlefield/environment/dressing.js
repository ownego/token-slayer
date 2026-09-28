/**
 * Pure placement for the arena's ambient dressing (braziers, the distant
 * keep, the dust/firefly band, and where a bird flock's flight line sits) —
 * derived entirely from the world box, so it works unchanged in landscape
 * and portrait with no fixed coordinates.
 *
 * @param {{width: number, height: number, horizonY: number}} box
 * @return {{
 *   braziers: Array<{x: number, y: number}>,
 *   keep: {x: number, base: number, unit: number, castleUnit: number},
 *   moteBand: {top: number, bottom: number},
 *   count: {motes: number},
 * }}
 */
export function dressingLayout({ width, height, horizonY }) {
  const y = Math.round(horizonY + (height - horizonY) * 0.2);
  const braziers = [0.055, 0.945].map(fx => ({ x: Math.round(width * fx), y }));
  const unit = Math.max(2, Math.round(width / 320));
  // the castle a little larger than the rest of the dressing, still a whole-number scale
  const castleUnit = unit + Math.max(1, Math.round(unit * 0.25));
  const keep = { x: Math.round(width * 0.74), base: Math.round(horizonY * 0.72), unit, castleUnit };
  const moteBand = { top: horizonY, bottom: height };
  return { braziers, keep, moteBand, count: { motes: Math.round(width / 40) } };
}
