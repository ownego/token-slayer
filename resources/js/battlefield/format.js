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

function trimZero(s) {
  return s.includes('.') ? s.replace(/\.?0+$/, '') : s;
}
