/**
 * Bar heights (percent of the busiest hour) for the sheet's hourly chart —
 * a 5% floor so a zero-damage hour still shows a visible sliver instead of
 * vanishing.
 *
 * @param {Array<{hour: string, damage: number}>} buckets
 * @return {number[]}
 */
export function barHeights(buckets) {
  const max = Math.max(1, ...buckets.map(b => b.damage));

  return buckets.map(b => Math.max(5, Math.round((b.damage / max) * 100)));
}

/**
 * The chart's own heading value/label — the period's total until one bar
 * is pinned (hovered/clicked), then that bar's own reading.
 *
 * @param {Array<{hour: string, damage: number}>} buckets
 * @param {?number} pinnedIndex
 * @return {{value: number, label: string}}
 */
export function readout(buckets, pinnedIndex) {
  if (pinnedIndex === null || pinnedIndex === undefined) {
    return { value: buckets.reduce((sum, b) => sum + b.damage, 0), label: 'in total' };
  }

  return { value: buckets[pinnedIndex].damage, label: `at ${buckets[pinnedIndex].hour}` };
}

/**
 * The refresh button's disabled state and cooldown text.
 *
 * @param {number} secondsLeft
 * @return {{disabled: boolean, text: string}}
 */
export function cooldownLabel(secondsLeft) {
  return secondsLeft > 0
    ? { disabled: true, text: `Refresh again in ${secondsLeft}s` }
    : { disabled: false, text: '' };
}

/**
 * Folds one live HitDealt broadcast into the sheet's own running token
 * ledger — only when it's the viewer's own hit; any other fighter's hit
 * returns the state untouched.
 *
 * @param {{me: number, output: number, input: number, cacheWritten: number, cacheRead: number}} state
 * @param {{user_id: number, damage: number, input_tokens: number, cache_creation_input_tokens: number, cache_read_input_tokens: number}} hit
 * @return {{me: number, output: number, input: number, cacheWritten: number, cacheRead: number}}
 */
export function liveTokens(state, hit) {
  if (Number(hit.user_id) !== Number(state.me)) {
    return state;
  }

  return {
    ...state,
    output: state.output + hit.damage,
    input: state.input + hit.input_tokens,
    cacheWritten: state.cacheWritten + hit.cache_creation_input_tokens,
    cacheRead: state.cacheRead + hit.cache_read_input_tokens,
  };
}

/**
 * Short count ("1.26M", "780K"), the JS twin of App\Support\CompactNumber.
 *
 * @param {number} n Count to format.
 * @return {string}
 */
export function compact(n) {
    const trim = (v) => String(Number(v.toFixed(2)));
    if (n >= 1e9) return `${trim(n / 1e9)}B`;
    if (n >= 1e6) return `${trim(n / 1e6)}M`;
    if (n >= 1e3) return `${Math.round(n / 1e3)}K`;
    return String(Math.round(n));
}
