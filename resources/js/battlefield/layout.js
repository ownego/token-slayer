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
      ? (minX + maxX) / 2
      : minX + ((maxX - minX) / (rowCount - 1)) * idxInRow;
    const y = topY + row * rowSpacing;
    positions.push({ x, y });
  }
  return positions;
}

export function rowsNeeded(count, perRow = 14) {
  return Math.max(1, Math.ceil(count / perRow));
}

export function fighterDisplayConfig(count, mode = 'landscape') {
  if (mode === 'portrait') {
    if (count <= 8) {
      return { displaySize: 28, topY: 290, rowSpacing: 32, showHandle: true, perRow: 8 };
    }
    return { displaySize: 22, topY: 290, rowSpacing: 28, showHandle: false, perRow: 8 };
  }
  if (count <= 14) {
    return { displaySize: 24, topY: 230, rowSpacing: 27, showHandle: true, perRow: 14 };
  }
  if (count <= 28) {
    return { displaySize: 22, topY: 220, rowSpacing: 24, showHandle: false, perRow: 14 };
  }
  return { displaySize: 18, topY: 200, rowSpacing: 22, showHandle: false, perRow: 14 };
}
