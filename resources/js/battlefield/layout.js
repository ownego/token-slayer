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

// Up to +40% bigger for the top contributor
export function damageScaleMultiplier(damage, maxHp) {
  if (!maxHp) {
    return 1;
  }
  return 1 + Math.min(damage / maxHp, 1) * 0.4;
}

// Foot anchor for the charge particle emitters (≈ 0.35 leg height × 0.6)
export function chargeFootY(posY, displaySize) {
  return posY + Math.round(displaySize * 0.21);
}

export function rowsNeeded(count, perRow = 14) {
  return Math.max(1, Math.ceil(count / perRow));
}

// displaySize = target visible character height in logical pixels.
// Sprites were upscaled 4× (LANCZOS) — char is now 72px in a 400px frame.
// LINEAR filter at ~1.0–1.5× of 72px keeps them smooth without blur.
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
