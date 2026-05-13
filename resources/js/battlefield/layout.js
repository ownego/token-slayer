export function computeFighterPositions(count, [minX, maxX], y) {
  if (count === 0) {
    return [];
  }
  if (count === 1) {
    return [{ x: (minX + maxX) / 2, y }];
  }
  const step = (maxX - minX) / (count - 1);
  return Array.from({ length: count }, (_, i) => ({ x: minX + step * i, y }));
}
