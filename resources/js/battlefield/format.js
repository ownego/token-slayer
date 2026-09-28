/**
 * Formats an HP value to a compact string (e.g. 1500 → "1.5K", 2000000 → "2M", 1000000000 → "1B").
 *
 * @param {number} n
 * @return {string}
 */
export function formatHp(n) {
  const v = Math.max(0, Math.round(n));
  if (v >= 999_500_000) {
    return trimZero((v / 1_000_000_000).toFixed(2)) + 'B';
  }
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

/**
 * A Team Damage figure: 'one' keeps one decimal (Today: "6.2M"); 'whole'
 * keeps only the whole part below a billion (Month, All-time: "347M"),
 * then one decimal ("1.2B") — the full precision reads as noise at a glance.
 *
 * @param {number} n
 * @param {'one'|'whole'} precision
 * @return {string}
 */
export function formatTeamStat(n, precision) {
  const v = Math.max(0, n);
  // divide straight to tenths: (2.15).toFixed(1) is "2.1" in binary floating point
  const one = div => trimZero((Math.round(v / (div / 10)) / 10).toFixed(1));
  if (v >= 1e9) {
    return one(1e9) + 'B';
  }
  const [div, unit] = v >= 1e6 ? [1e6, 'M'] : v >= 1e3 ? [1e3, 'K'] : [1, ''];

  return (precision === 'one' && div > 1 ? one(div) : String(Math.floor(v / div))) + unit;
}
