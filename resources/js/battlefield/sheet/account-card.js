// One account card's own refresh, ported from the approved mockup's
// refreshAccount(): the button spins and the stamp reads "checking…", then
// the stamp shows what moved ("42% → 45%") or "failed", and a success holds
// the button for a minute with a countdown on it.
import { probeLabel } from './sheet-ui.js';
import { BY_KEY, frameIn, paint } from './sprite-strip.js';

/**
 * Seconds a successful check holds the card's refresh button.
 *
 * @type {number}
 */
const COOLDOWN_SECONDS = 60;

/**
 * Alpine.data() component for one account card.
 *
 * @param {number} accountId The card's account.
 * @return {object}
 */
export function accountCard(accountId) {
  return {
    busy: false,
    failed: false,
    stamp: null,
    stampClass: '',
    left: 0,
    timer: null,
    /**
     * Paints a member's character badge (`data-char`) with that fighter's
     * idle sprite, framed small. Called from the badge's own x-init; the
     * badge is wire:ignore'd so a re-render never strips the paint.
     *
     * @param {HTMLElement} el The `.mchar` badge.
     * @return {void}
     */
    paintChar(el) {
      const key = el.dataset.char;
      if (!BY_KEY[key] || el.querySelector('i')) {
        return;
      }
      const sprite = document.createElement('i');
      el.appendChild(sprite);
      paint(sprite, key, 'idle');
      frameIn(sprite, key, { maxW: 16, maxH: 16 });
    },
    /**
     * Re-probes this account and shows the outcome in place.
     *
     * @return {Promise<void>}
     */
    async refresh() {
      if (this.busy || this.left > 0) {
        return;
      }
      this.busy = true;
      this.stamp = 'checking…';
      this.stampClass = '';
      let outcome;
      try {
        outcome = await this.$wire.refreshAccount(accountId);
      } catch {
        outcome = { status: 'failed' };
      }
      this.busy = false;
      const label = probeLabel(outcome);
      this.stamp = label.text;
      this.stampClass = label.cls;
      this.failed = outcome.status === 'failed';
      if (outcome.status === 'changed' || outcome.status === 'unchanged') {
        this.$wire.$refresh();
        this.countdown(COOLDOWN_SECONDS);
      } else if (outcome.status === 'cooldown') {
        this.countdown(outcome.seconds);
      }
    },
    /**
     * Holds the button for `seconds`, ticking the label once a second.
     *
     * @param {number} seconds
     * @return {void}
     */
    countdown(seconds) {
      clearInterval(this.timer);
      this.left = seconds;
      this.timer = setInterval(() => {
        this.left = Math.max(0, this.left - 1);
        if (this.left === 0) {
          clearInterval(this.timer);
        }
      }, 1000);
    },
    destroy() {
      clearInterval(this.timer);
    },
  };
}
