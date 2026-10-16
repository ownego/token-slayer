/**
 * How long after a custom fighter's strips fail to load before the same version
 * is asked for again. A strip that is down would otherwise be re-fetched on every
 * charge event.
 * @type {number}
 */
export const CUSTOM_RETRY_MS = 30_000;

/**
 * What a fighter's entry keeps about the custom fighters asked for on its behalf:
 * the newest one wanted (and its spec, for a rotate), the one loading right now,
 * and the last one that failed with the time it may be tried again. Pure state
 * helpers: the manager decides what to do, this module decides whether to.
 *
 * @return {{wantedKey: ?string, wantedSpec: ?object, loadingKey: ?string, failedKey: ?string, retryAt: number}}
 */
export function newRequestState() {
  return { wantedKey: null, wantedSpec: null, loadingKey: null, failedKey: null, retryAt: 0 };
}

/**
 * Whether a version should be requested now.
 *
 * @param {{loadingKey: ?string, failedKey: ?string, retryAt: number}} state The entry's request state.
 * @param {?string} currentKey The key of the type the fighter is showing.
 * @param {string} key The custom fighter key asked for.
 * @param {number} now The scene time in ms.
 * @return {boolean}
 */
export function shouldRequest(state, currentKey, key, now) {
  if (currentKey === key || state.loadingKey === key) {
    return false;
  }

  return !(state.failedKey === key && now < state.retryAt);
}

/**
 * States what is wanted now: this version, and nothing newer. Called for every update
 * that names a custom fighter, whether or not a load is needed (it may already be on show,
 * or already loading), so an older load that lands later goes stale instead of being applied.
 *
 * @param {object} state The entry's request state.
 * @param {string} key The custom fighter key.
 * @param {object} spec The normalised custom character.
 * @return {void}
 */
export function want(state, key, spec) {
  state.wantedKey = key;
  state.wantedSpec = spec;
}

/**
 * Records that a load for this version started.
 *
 * @param {object} state The entry's request state.
 * @param {string} key The custom fighter key.
 * @return {void}
 */
export function begin(state, key) {
  state.loadingKey = key;
}

/**
 * Records that a load ended (either way). Only the load in flight for this version
 * is cleared, so a newer one that started meanwhile keeps its guard.
 *
 * @param {object} state The entry's request state.
 * @param {string} key The custom fighter key whose load ended.
 * @return {void}
 */
export function settle(state, key) {
  if (state.loadingKey === key) {
    state.loadingKey = null;
  }
}

/**
 * Records a failed load: that version waits out the retry window.
 *
 * @param {object} state The entry's request state.
 * @param {string} key The custom fighter key that failed.
 * @param {number} now The scene time in ms.
 * @return {void}
 */
export function fail(state, key, now) {
  state.failedKey = key;
  state.retryAt = now + CUSTOM_RETRY_MS;
}

/**
 * Whether a finished load should be ignored: it is not the version wanted any
 * more (replaced, or removed), or it is already on show.
 *
 * @param {{wantedKey: ?string}} state The entry's request state.
 * @param {string} key The custom fighter key that finished loading.
 * @param {?string} currentKey The key of the type the fighter is showing.
 * @return {boolean}
 */
export function isStale(state, key, currentKey) {
  return state.wantedKey !== key || currentKey === key;
}

/**
 * Drops every request: the fighter has no custom character any more.
 *
 * @param {object} state The entry's request state.
 * @return {void}
 */
export function withdraw(state) {
  state.wantedKey = null;
  state.wantedSpec = null;
}

/**
 * The custom character a rotate should carry: the newest one asked for (still
 * loading, or swapping in), else the one on show. Null for a roster fighter.
 *
 * @param {?{wantedSpec: ?object}} state The entry's request state.
 * @param {?{spec?: object}} ftype The type the fighter is showing.
 * @return {?object}
 */
export function snapshotSpec(state, ftype) {
  return state?.wantedSpec ?? ftype?.spec ?? null;
}
