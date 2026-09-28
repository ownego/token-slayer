// Which of ThaNode's stones this viewer has already watched arrive, so the
// per-stone animation plays once per stone per browser: on the first visible
// moment after the stone lands — straight away when the tab is open, or when
// the viewer comes back to it. Remembered per boss number in localStorage (a
// per-viewer convenience, never shared state); an in-memory copy keeps it
// working for the page's lifetime when storage throws.

const KEY_PREFIX = 'ts:stones-seen:';

/**
 * The ordinals (1-based) of every stone earned since the viewer last looked,
 * oldest first.
 *
 * @param {number} seen Stones the viewer has already watched arrive.
 * @param {number} stones Stones the boss holds now.
 * @return {Array<number>}
 */
export function unseenOrdinals(seen, stones) {
  const out = [];
  for (let n = seen + 1; n <= stones; n++) {
    out.push(n);
  }
  return out;
}

/**
 * A seen-count store over a Storage-like object.
 *
 * @param {{getItem: function(string): (string|null), setItem: function(string, string): void}|null} storage
 * @return {{read: function(number): number, write: function(number, number): void}}
 */
export function createSeenStore(storage) {
  const memory = new Map();

  const read = (bossNumber) => {
    let stored = 0;
    try {
      stored = Number.parseInt(storage?.getItem(KEY_PREFIX + bossNumber) ?? '0', 10) || 0;
    } catch {
      stored = 0;
    }
    return Math.max(stored, memory.get(bossNumber) ?? 0);
  };

  return {
    read,

    /**
     * Records that the viewer has seen `count` stones; never moves backwards.
     *
     * @param {number} bossNumber
     * @param {number} count
     * @return {void}
     */
    write(bossNumber, count) {
      const next = Math.max(read(bossNumber), count);
      memory.set(bossNumber, next);
      try {
        storage?.setItem(KEY_PREFIX + bossNumber, String(next));
      } catch {
        // private window / blocked site data: the in-memory copy still holds
      }
    },
  };
}

/**
 * The page's localStorage, or null where touching it throws.
 *
 * @return {Storage|null}
 */
export function browserStorage() {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}
