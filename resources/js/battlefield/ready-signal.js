/**
 * The longest deep links wait for the arena before opening the sheet.
 *
 * @type {number}
 */
export const READY_CEILING_MS = 15000;

/**
 * Announces that the arena loader has finished, once per page.
 *
 * @return {void}
 */
export function announceReady() {
  if (typeof window === 'undefined' || window.__battlefieldReady) {
    return;
  }
  window.__battlefieldReady = true;
  window.dispatchEvent(new Event('battlefield-ready'));
}

/**
 * Calls a deep-link action when the arena is ready or when loading stalls.
 *
 * @param {function(): void} callback The action waiting for the arena.
 * @param {number} ceilingMs The maximum wait in milliseconds.
 * @return {void}
 */
export function whenReady(callback, ceilingMs = READY_CEILING_MS) {
  if (window.__battlefieldReady) {
    callback();

    return;
  }
  let finished = false;
  let timeout;
  const finish = () => {
    if (finished) {
      return;
    }
    finished = true;
    clearTimeout(timeout);
    window.removeEventListener('battlefield-ready', finish);
    callback();
  };
  window.addEventListener('battlefield-ready', finish, { once: true });
  timeout = setTimeout(finish, ceilingMs);
}
