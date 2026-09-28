// The Profile tab's two period pickers, ported from the approved mockup's
// own keyboard behaviour: the hero's period tabs (roving ←/→ focus that
// picks as it goes, Enter/Space opens "More", Escape closes it and hands
// focus back) and the By-model dropdown (↑/↓ highlight, Enter picks,
// Escape closes).
import { rovingIndex } from './sheet-ui.js';

/**
 * Where the hero's last-picked period is remembered between visits.
 *
 * @type {string}
 */
const PERIOD_KEY = 'ts:profile-period';

/**
 * Alpine.data() component for the hero's period tabs.
 *
 * @param {string} current The period the server rendered.
 * @return {object}
 */
export function periodTabs(current) {
  return {
    more: false,
    custom: false,
    init() {
      let saved = null;
      try {
        saved = localStorage.getItem(PERIOD_KEY);
      } catch {
        saved = null;
      }
      if (saved && saved !== 'custom' && saved !== current) {
        this.$wire.setPeriod(saved);
      }
    },
    /**
     * Switches to a period and remembers it.
     *
     * @param {string} period
     * @return {void}
     */
    pick(period) {
      try {
        localStorage.setItem(PERIOD_KEY, period);
      } catch {
        // storage unavailable: the pick still applies for this visit
      }
      this.$wire.setPeriod(period);
      this.more = false;
    },
    /**
     * Keyboard on a tab: arrows move to (and pick) the next tab; Enter or
     * Space on "More" toggles its menu.
     *
     * @param {KeyboardEvent} event
     * @return {void}
     */
    nav(event) {
      const tabs = [...this.$el.querySelectorAll('.periods > .period')];
      const i = tabs.indexOf(event.target);
      if (i < 0) {
        return; // a key typed inside "More"'s own menu (the date inputs)
      }
      const next = rovingIndex(i, event.key, tabs.length);
      if (next !== null) {
        event.preventDefault();
        const tab = tabs[next];
        tab.focus();
        if (tab.id !== 'dd') {
          this.pick(tab.dataset.p);
        }

        return;
      }
      if (event.target.id === 'dd' && (event.key === 'Enter' || event.key === ' ')) {
        event.preventDefault();
        this.more = !this.more;
      }
    },
    /**
     * Escape: closes "More" and hands focus back to it.
     *
     * @return {void}
     */
    escape() {
      if (this.more) {
        this.more = false;
        this.$el.querySelector('#dd')?.focus();
      }
    },
  };
}

/**
 * Alpine.data() component for the By-model period dropdown.
 *
 * @param {string[]} options The periods it offers, in order.
 * @param {string} current The period the server rendered.
 * @return {object}
 */
export function modelPicker(options, current) {
  return {
    open: false,
    active: Math.max(0, options.indexOf(current)),
    /**
     * Opens or closes the menu, highlighting the current period on open.
     *
     * @param {boolean} on
     * @return {void}
     */
    toggle(on) {
      this.open = on;
      if (on) {
        this.active = Math.max(0, options.indexOf(current));
      }
    },
    /**
     * Picks a period and closes.
     *
     * @param {string} period
     * @return {void}
     */
    choose(period) {
      this.$wire.setModelPeriod(period);
      this.open = false;
    },
    /**
     * ↑/↓ open the menu or move its highlight, Enter/Space picks, Escape closes.
     *
     * @param {KeyboardEvent} event
     * @return {void}
     */
    keys(event) {
      const next = rovingIndex(this.active, event.key, options.length, { vertical: true });
      if (next !== null) {
        event.preventDefault();
        event.stopPropagation();
        if (this.open) {
          this.active = next;
        } else {
          this.toggle(true);
        }

        return;
      }
      if ((event.key === 'Enter' || event.key === ' ') && this.open) {
        event.preventDefault();
        this.choose(options[this.active]);
      } else if (event.key === 'Escape' && this.open) {
        event.stopPropagation();
        this.open = false;
      }
    },
  };
}
