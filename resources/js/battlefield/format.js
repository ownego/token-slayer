/**
 * Formats an HP value to a compact string (e.g. 1500 → "1.5K", 2000000 → "2M").
 *
 * @param {number} n
 * @return {string}
 */
export function formatHp(n) {
  const v = Math.max(0, Math.round(n));
  if (v >= 999_500) {
    return trimZero((v / 1_000_000).toFixed(2)) + 'M';
  }
  if (v >= 1_000) {
    return trimZero((v / 1_000).toFixed(1)) + 'K';
  }
  return String(v);
}

/**
 * Strips trailing zeros after a decimal point from a formatted number string.
 *
 * @param {string} s
 * @return {string}
 */
function trimZero(s) {
  return s.includes('.') ? s.replace(/\.?0+$/, '') : s;
}
