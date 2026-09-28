// Small pure helpers behind the fighter sheet's live UI, ported from the
// approved mockup's own inline logic so each can be tested on its own.

/**
 * The text an account card's probe stamp shows after its own refresh.
 *
 * @param {{status: string, from?: ?number, to?: ?number, seconds?: number}} outcome AccountRefresh's result.
 * @return {{text: string, cls: string}}
 */
export function probeLabel(outcome) {
  switch (outcome.status) {
    case 'changed':
      return { text: `${outcome.from ?? 0}% → ${outcome.to ?? 0}%`, cls: 'changed' };
    case 'failed':
      return { text: 'failed', cls: 'failed' };
    case 'cooldown':
      return { text: `wait ${outcome.seconds}s`, cls: '' };
    default:
      return { text: 'no change', cls: '' };
  }
}

/**
 * The value `k` of the way (0..1) through an ease-out-cubic count.
 *
 * @param {number} from Start value.
 * @param {number} to End value.
 * @param {number} k Progress, 0..1.
 * @return {number}
 */
export function easeCount(from, to, k) {
  const e = 1 - Math.pow(1 - Math.min(1, Math.max(0, k)), 3);

  return Math.round(from + (to - from) * e);
}

/**
 * "just now", "2m ago", "3h 12m ago", "3d 10h ago".
 *
 * @param {number} seconds Seconds since the moment.
 * @return {string}
 */
export function agoLabel(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  const m = Math.floor(s / 60);
  const h = Math.floor(m / 60);
  const d = Math.floor(h / 24);
  if (s < 60) {
    return 'just now';
  }
  if (h < 1) {
    return `${m}m ago`;
  }
  if (d < 1) {
    return `${h}h ${m % 60}m ago`;
  }

  return `${d}d ${h % 24}h ago`;
}

/**
 * Folds a minion event into the viewer's crew: `count` sets how many
 * minions exist, `tool` marks one agent busy (in a tool call) or idle.
 *
 * @param {{total: number, busy: Set<string>}} state Current crew.
 * @param {{type: 'count', count: number}|{type: 'tool', agent_id: string, busy: boolean}} event
 * @return {{total: number, busy: Set<string>}}
 */
export function crewReducer(state, event) {
  const busy = new Set(state.busy);
  if (event.type === 'count') {
    const total = Math.max(0, Number(event.count) || 0);
    if (total === 0) {
      busy.clear();
    }

    return { total, busy };
  }
  if (event.busy) {
    busy.add(String(event.agent_id));
  } else {
    busy.delete(String(event.agent_id));
  }

  return { total: state.total, busy };
}

/**
 * Busy / idle / total for the crew stat and the mini stage.
 *
 * @param {{total: number, busy: Set<string>}} state Current crew.
 * @return {{busy: number, idle: number, total: number}}
 */
export function crewCounts(state) {
  const busy = Math.min(state.busy.size, state.total);

  return { busy, idle: state.total - busy, total: state.total };
}

/**
 * The storage key the best combo is kept under, one per local day.
 *
 * @param {Date} date Now.
 * @return {string}
 */
export function bestComboKey(date) {
  const pad = n => String(n).padStart(2, '0');

  return `ts:best-combo:${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * The next index an arrow key moves focus to in a wrapping row (or
 * column, with `vertical`), or null for any other key.
 *
 * @param {number} i Current index.
 * @param {string} key KeyboardEvent.key.
 * @param {number} n Item count.
 * @param {{vertical?: boolean}} [opts]
 * @return {?number}
 */
export function rovingIndex(i, key, n, { vertical = false } = {}) {
  const step = vertical ? { ArrowUp: -1, ArrowDown: 1 }[key] : { ArrowLeft: -1, ArrowRight: 1 }[key];

  return step === undefined ? null : (i + step + n) % n;
}

/**
 * The last-hit stamp: "just now", then whole minutes, hours, days.
 *
 * @param {number} seconds Seconds since the hit.
 * @return {string}
 */
export function coarseAgo(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  if (s < 60) {
    return 'just now';
  }
  if (s < 3600) {
    return `${Math.floor(s / 60)}m ago`;
  }
  if (s < 86400) {
    return `${Math.floor(s / 3600)}h ago`;
  }

  return `${Math.floor(s / 86400)}d ago`;
}
