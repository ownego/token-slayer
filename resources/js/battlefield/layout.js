/**
 * Returns evenly spaced {x, y} positions for count fighters.
 *
 * @param {number} count
 * @param {[number, number]} bounds
 * @param {number} topY
 * @param {number} [perRow=14]
 * @param {number} [rowSpacing=27]
 * @return {Array<{x: number, y: number}>}
 */
/**
 * Returns the y of the line at the foot of the mountains, where the sky
 * backdrop meets the floor: the environment draws the horizon there, and
 * move-geometry.js keeps every fighter's feet on or below it.
 *
 * @param {{hpBar: {y: number}}} layout A `LAYOUTS` entry.
 * @return {number}
 */
export function horizonYFor(layout) {
  return Math.round(layout.hpBar.y * 0.62);
}

export function computeFighterPositions(count, [minX, maxX], topY, perRow = 14, rowSpacing = 27) {
  if (count === 0) {
    return [];
  }
  const positions = [];
  for (let i = 0; i < count; i++) {
    const row = Math.floor(i / perRow);
    const rowStart = row * perRow;
    const rowCount = Math.min(perRow, count - rowStart);
    const idxInRow = i - rowStart;
    const x = rowCount === 1
      ? minX + (maxX - minX) * 0.3
      : minX + ((maxX - minX) / (rowCount - 1)) * idxInRow;
    const y = topY + row * rowSpacing;
    positions.push({ x, y });
  }
  return positions;
}

/**
 * Returns a scale multiplier (1.0–1.4) based on this fighter's damage share.
 *
 * @param {number} damage
 * @param {number} maxHp
 * @return {number}
 */
export function damageScaleMultiplier(damage, maxHp) {
  if (!maxHp) {
    return 1;
  }
  return 1 + Math.min(damage / maxHp, 1) * 0.4;
}

/**
 * Returns the foot-anchor Y for charge particle emitters.
 *
 * @param {number} posY
 * @param {number} displaySize
 * @return {number}
 */
export function chargeFootY(posY, displaySize) {
  return posY + Math.round(displaySize * 0.21);
}

/**
 * Returns the number of rows needed to display count fighters.
 *
 * @param {number} count
 * @param {number} [perRow=14]
 * @return {number}
 */
export function rowsNeeded(count, perRow = 14) {
  return Math.max(1, Math.ceil(count / perRow));
}

/**
 * Returns display configuration for fighters based on count and viewport mode.
 *
 * The rows are authored against LAYOUTS; a derived layout (config/layouts.js's
 * layoutFor) moves them down by its `fighterShiftY` and lets a wider world hold more
 * fighters per row.
 *
 * @param {number} count
 * @param {string} [mode='landscape']
 * @param {{fighterShiftY?: number, shiftY?: number, fighters?: {perRowMax: number}}} [layout] The live layout.
 * @return {{ displaySize: number, topY: number, rowSpacing: number, showHandle: boolean, perRow: number }}
 */
export function fighterDisplayConfig(count, mode = 'landscape', layout = null) {
  const shiftY = layout?.fighterShiftY ?? layout?.shiftY ?? 0;
  const perRow = layout?.fighters?.perRowMax;
  if (mode === 'portrait') {
    // Authored canvas 540×960. Boss area ends ~430. Fighters fill 430–960.
    const portraitRow = Math.max(8, perRow ?? 8);
    if (count <= portraitRow) {
      return { displaySize: 54, topY: 620 + shiftY, rowSpacing: 70, showHandle: true,  perRow: portraitRow };
    }
    return   { displaySize: 45, topY: 610 + shiftY, rowSpacing: 55, showHandle: false, perRow: portraitRow };
  }
  // Authored canvas 960×540. Boss area ends ~310. HP bar at 300. Fighters fill 340–540.
  const landscapeRow = perRow ?? 14;
  if (count <= landscapeRow) {
    return { displaySize: 45, topY: 490 + shiftY, rowSpacing: 65, showHandle: true,  perRow: landscapeRow };
  }
  if (count <= landscapeRow * 2) {
    return { displaySize: 36, topY: 440 + shiftY, rowSpacing: 53, showHandle: false, perRow: landscapeRow };
  }
  return   { displaySize: 27, topY: 425 + shiftY, rowSpacing: 35, showHandle: false, perRow: landscapeRow };
}
