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
 * @param {number} count
 * @param {string} [mode='landscape']
 * @return {{ displaySize: number, topY: number, rowSpacing: number, showHandle: boolean, perRow: number }}
 */
export function fighterDisplayConfig(count, mode = 'landscape') {
  if (mode === 'portrait') {
    // Canvas 540×960. Boss area ends ~430. Fighters fill 430–960.
    if (count <= 8) {
      return { displaySize: 54, topY: 620, rowSpacing: 70, showHandle: true,  perRow: 8 };
    }
    return   { displaySize: 45, topY: 610, rowSpacing: 55, showHandle: false, perRow: 8 };
  }
  // Canvas 960×540. Boss area ends ~310. HP bar at 300. Fighters fill 340–540.
  if (count <= 14) {
    return { displaySize: 45, topY: 490, rowSpacing: 65, showHandle: true,  perRow: 14 };
  }
  if (count <= 28) {
    return { displaySize: 36, topY: 440, rowSpacing: 53, showHandle: false, perRow: 14 };
  }
  return   { displaySize: 27, topY: 425, rowSpacing: 35, showHandle: false, perRow: 14 };
}
