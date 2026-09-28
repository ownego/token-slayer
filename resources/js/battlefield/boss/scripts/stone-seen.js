// Which of ThaNode's stones this viewer has already watched arrive, so the
// per-stone animation plays once per stone per browser: on the first visible
// moment after the stone lands — straight away when the tab is open, or when
// the viewer comes back to it. localStorage holds a single {boss, seen} record
// for the current ThaNode only (a per-viewer convenience, never shared state):
// a later ThaNode has another boss number, so it starts from nothing seen and
// overwrites the record, leaving nothing behind per past boss. An in-memory
// copy keeps it working for the page's lifetime when storage throws.

const KEY = 'ts:stones-seen';

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
      const record = JSON.parse(storage?.getItem(KEY) ?? 'null');
      if (record?.boss === bossNumber && Number.isInteger(record.seen)) {
        stored = record.seen;
      }
    } catch {
      stored = 0; // storage throws, or a garbage value that isn't JSON
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
        storage?.setItem(KEY, JSON.stringify({ boss: bossNumber, seen: next }));
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

let pageStore = null;

/**
 * The page's one seen-count store. A scene restart (rotate, resize-mode
 * switch) re-runs the script's create(), so a store made there would lose
 * its in-memory copy each time and replay every stone where storage is blocked.
 *
 * @return {{read: function(number): number, write: function(number, number): void}}
 */
export function pageSeenStore() {
  pageStore ??= createSeenStore(browserStorage());
  return pageStore;
}

