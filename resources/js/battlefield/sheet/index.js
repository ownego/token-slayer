import { createBuddy } from './clawd-buddy.js';
import { barHeights, compact, cooldownLabel, liveTokens, readout } from './hourly-bars.js';
import { agoLabel, bestComboKey, coarseAgo, easeCount } from './sheet-ui.js';

const TAB_ORDER = ['profile', 'character'];

/**
 * Selector for elements the focus trap cycles through — kept in one place
 * so the trap and the initial-focus pick agree on what counts as
 * focusable.
 *
 * @type {string}
 */
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Wires the sheet's own keyboard/focus behavior onto its root element: tab
 * click + Left/Right arrow keys switch the active tab, Escape closes it,
 * opening moves focus inside and traps Tab/Shift+Tab within it, and
 * closing returns focus to whichever element had it before the sheet
 * opened.
 *
 * @param {HTMLElement} root the sheet's own root element
 * @param {{onClose: function(): void, onTab?: function(string): void}} handlers
 * @return {{open(opener: HTMLElement): void, close(): void, destroy(): void}}
 */
export function createSheetShell(root, { onClose, onTab }) {
  let opener = null;

  const currentTab = () => root.querySelector('.sheet-tab[aria-selected="true"]')?.dataset.tab ?? TAB_ORDER[0];

  const focusables = () => Array.from(root.querySelectorAll(FOCUSABLE));

  const onKeydown = event => {
    if (event.key === 'Escape') {
      close();

      return;
    }

    if (event.key === 'Tab') {
      const items = focusables();
      if (items.length === 0) {
        return;
      }
      const first = items[0];
      const last = items[items.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (! event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }

      return;
    }

    if ((event.key === 'ArrowLeft' || event.key === 'ArrowRight') && onTab) {
      const idx = TAB_ORDER.indexOf(currentTab());
      const delta = event.key === 'ArrowLeft' ? -1 : 1;
      onTab(TAB_ORDER[(idx + delta + TAB_ORDER.length) % TAB_ORDER.length]);
    }
  };

  const onClick = event => {
    const tabButton = event.target.closest('.sheet-tab');
    if (tabButton && onTab) {
      onTab(tabButton.dataset.tab);
    }
  };

  root.addEventListener('keydown', onKeydown);
  root.addEventListener('click', onClick);

  /**
   * Opens the sheet: remembers `opener` (focus returns here on close) and
   * moves focus to the first focusable element inside the sheet.
   *
   * @param {HTMLElement} openerEl the element that triggered the open
   * @return {void}
   */
  function open(openerEl) {
    opener = openerEl;
    focusables()[0]?.focus();
  }

  /**
   * Calls `onClose` and returns focus to whichever element opened the sheet.
   *
   * @return {void}
   */
  function close() {
    onClose();
    opener?.focus();
  }

  /** Removes the shell's own listeners. */
  function destroy() {
    root.removeEventListener('keydown', onKeydown);
    root.removeEventListener('click', onClick);
  }

  return { open, close, destroy };
}

/**
 * Alpine.data() component for the sheet's own root: wires createSheetShell
 * (tabs, Escape, focus trap) and a live token ledger fed by the shared
 * battlefield event bus's own 'hit' events (see index.js's ECHO_EVENT_MAP),
 * once the battlefield game has finished booting — the sheet is embedded
 * on the same page as the Phaser canvas, but Phaser's own boot (and the
 * bus it creates) may not have finished the instant this component's own
 * init() runs.
 *
 * @param {number} me the viewer's own user id, for filtering 'hit' events
 * @return {object}
 */
export function fighterSheetShell(me) {
  return {
    me,
    shell: null,
    tokens: { output: 0, input: 0, cacheWritten: 0, cacheRead: 0 },
    liveDamage: null,
    shownDamage: null,
    damageObserver: null,
    countRaf: null,
    offInput: null,
    cooldown: { disabled: false, text: '' },
    unbindHit: null,
    cooldownTimer: null,
    init() {
      this.shell = createSheetShell(this.$el, {
        onClose: () => this.$wire.close(),
        // $wire.open (and $wire.call('open')) resolve to the public $open bool,
        // never the open() method — reach it through its own event listener
        onTab: tab => this.$wire.dispatchSelf('open-fighter-sheet', { tab }),
      });
      this.shell.open(document.activeElement);
      this.attachHitListener();
      this.$wire.on('sheet-refresh-cooldown', ({ seconds }) => this.startCooldown(seconds));
      this.watchDamage();
      this.trackInput();
      this.$el.addEventListener('fighter-equipped', () => this.equipTransition());
      this.$el.addEventListener('sheet-open-tab', event => this.$wire.dispatchSelf('open-fighter-sheet', { tab: event.detail.tab }));
    },
    /**
     * Folds the sheet away, closes it, then pans the live battlefield
     * camera to the viewer's own fighter — the mockup's "equip lands on
     * the battlefield" moment, reusing the real canvas instead of a second
     * fake field (see camera-focus.js's focusFighter).
     *
     * @return {void}
     */
    equipTransition() {
      const sheet = this.$el.querySelector('.sheet');
      sheet?.classList.add('folding');
      setTimeout(() => {
        this.shell.close();
        window.__battlefield?.focusFighter?.(this.me);
      }, 350); // matches .sheet.folding's own transition duration in fighter-sheet.css
    },
    attachHitListener() {
      const attach = () => {
        if (!window.__battlefield?.bindBus) {
          requestAnimationFrame(attach);

          return;
        }
        this.unbindHit = window.__battlefield.bindBus(window.__battlefield.bus, {
          hit: payload => {
            this.tokens = liveTokens({ me, ...this.tokens }, payload);
            if (Number(payload.user_id) === Number(me)) {
              this.rollDamage(Number(payload.damage) || 0);
            }
          },
          'boss-killed': payload => {
            if (Number(payload.killer_user_id) === Number(me)) {
              this.bumpKills();
            }
          },
        });
      };
      attach();
    },
    /**
     * The mockup's live hit: the damage figure rolls up by the hit, the
     * panel flashes and a "+N" floats off it. A custom range is a closed
     * window, so it never moves.
     *
     * @param {number} damage The viewer's own hit.
     * @return {void}
     */
    rollDamage(damage) {
      const el = this.$el.querySelector('#dmg');
      const panel = this.$el.querySelector('.meter-panel');
      if (!el || !panel || this.$wire.period === 'custom' || damage <= 0) {
        return;
      }
      this.liveDamage = (this.liveDamage ?? (Number(el.dataset.value) || parseCompact(el.textContent))) + damage;
      this.countTo(el, this.liveDamage);
      panel.classList.add('hit');
      setTimeout(() => panel.classList.remove('hit'), 180);
      el.classList.remove('bump');
      void el.offsetWidth;
      el.classList.add('bump');
      const pop = document.createElement('span');
      pop.className = 'pop';
      pop.textContent = `+${compact(damage)}`;
      el.parentElement.appendChild(pop);
      setTimeout(() => pop.remove(), 1100);
    },
    /**
     * The mockup's eased count-up (ease-out cubic, ~450ms) from the figure
     * on screen to `to`; snaps under reduced motion.
     *
     * @param {HTMLElement} el The damage figure.
     * @param {number} to Target value.
     * @return {void}
     */
    countTo(el, to) {
      const from = this.shownDamage ?? to;
      this.shownDamage = to;
      cancelAnimationFrame(this.countRaf);
      const reduced = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduced || from === to) {
        el.textContent = compact(to);

        return;
      }
      const start = performance.now();
      const step = now => {
        const k = Math.min(1, (now - start) / 450);
        el.textContent = compact(easeCount(from, to, k));
        if (k < 1) {
          this.countRaf = requestAnimationFrame(step);
        }
      };
      this.countRaf = requestAnimationFrame(step);
    },
    /**
     * Counts the damage figure to each value a Livewire re-render prints
     * (period switch, refresh), from whatever is on screen — the server's
     * figure then replaces any live hits added on top of the old one.
     *
     * @return {void}
     */
    watchDamage() {
      const el = this.$el.querySelector('#dmg');
      this.shownDamage = el ? Number(el.dataset.value) || 0 : null;
      if (typeof MutationObserver !== 'function') {
        return;
      }
      this.damageObserver = new MutationObserver(records => {
        const changed = records.find(r => r.target.id === 'dmg');
        if (changed) {
          this.liveDamage = null;
          this.countTo(changed.target, Number(changed.target.dataset.value) || 0);
        }
      });
      this.damageObserver.observe(this.$el, { subtree: true, attributes: true, attributeFilter: ['data-value'] });
    },
    /**
     * The mockup's kill moment on the header: the chip counts the new kill
     * and bumps.
     *
     * @return {void}
     */
    bumpKills() {
      const chip = this.$el.querySelector('.kills-chip');
      const count = chip?.querySelector('b');
      if (!chip || !count) {
        return;
      }
      count.textContent = String((Number(count.textContent) || 0) + 1);
      chip.classList.remove('bump');
      void chip.offsetWidth;
      chip.classList.add('bump');
    },
    /**
     * Marks how the viewer last interacted (data-input="mouse"|"keyboard")
     * so the sheet's CSS shows focus rings for the keyboard only.
     *
     * @return {void}
     */
    trackInput() {
      const mouse = () => { this.$el.dataset.input = 'mouse'; };
      const keys = event => {
        if (event.key === 'Tab' || event.key.startsWith('Arrow')) {
          this.$el.dataset.input = 'keyboard';
        }
      };
      this.$el.addEventListener('pointerdown', mouse, true);
      this.$el.addEventListener('keydown', keys, true);
      this.offInput = () => {
        this.$el.removeEventListener('pointerdown', mouse, true);
        this.$el.removeEventListener('keydown', keys, true);
      };
    },
    startCooldown(seconds) {
      let secondsLeft = seconds;
      this.cooldown = cooldownLabel(secondsLeft);
      clearInterval(this.cooldownTimer);
      this.cooldownTimer = setInterval(() => {
        secondsLeft -= 1;
        this.cooldown = cooldownLabel(Math.max(0, secondsLeft));
        if (secondsLeft <= 0) {
          clearInterval(this.cooldownTimer);
        }
      }, 1000);
    },
    destroy() {
      this.shell?.destroy();
      this.unbindHit?.();
      clearInterval(this.cooldownTimer);
      this.damageObserver?.disconnect();
      cancelAnimationFrame(this.countRaf);
      this.offInput?.();
    },
  };
}

/**
 * Alpine.data() component for the "Last 24 hours" spark bars, ported from
 * the mockup: the heading reads the day's total until a bar is hovered or
 * pinned (click, or arrow keys), then that hour's damage. wire:ignore'd, so
 * a pinned bar survives an unrelated re-render.
 *
 * @param {Array<{hour: string, damage: number}>} buckets
 * @return {object}
 */
export function sparkChart(buckets) {
  return {
    buckets,
    hover: null,
    pinned: null,
    get bars() {
      const heights = barHeights(this.buckets);

      return this.buckets.map((b, i) => ({ hour: b.hour, height: heights[i], label: compact(b.damage) }));
    },
    get shown() {
      return this.hover ?? this.pinned;
    },
    get readoutHtml() {
      const r = readout(this.buckets, this.shown);

      return `<b>${compact(r.value)}</b> ${r.label}`;
    },
    /**
     * Arrow keys walk the bars (pinning as they go), Enter/Space toggles the
     * pin, Escape clears it.
     *
     * @param {KeyboardEvent} event
     * @param {number} i The focused bar.
     * @return {void}
     */
    key(event, i) {
      const d = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
      if (d) {
        event.preventDefault();
        event.stopPropagation();
        const next = event.target.parentElement.children[i + d];
        if (next && next.tagName === 'SPAN') {
          next.focus();
          this.pinned = i + d;
        }
      }
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        this.pinned = this.pinned === i ? null : i;
      }
      if (event.key === 'Escape' && this.pinned !== null) {
        event.stopPropagation();
        this.pinned = null;
      }
    },
  };
}

/**
 * Reads a compact figure ("1.26M") back into a number.
 *
 * @param {string} text Compact figure.
 * @return {number}
 */
function parseCompact(text) {
  const m = String(text).trim().match(/^([\d.]+)([KMB]?)$/);
  if (!m) {
    return 0;
  }

  return Number(m[1]) * ({ '': 1, K: 1e3, M: 1e6, B: 1e9 })[m[2]];
}

/**
 * Alpine.data() component for the Profile tab's Clawd buddy panel — mounts
 * createBuddy() once the panel has a real box, and reacts to the shared
 * battlefield event bus's own 'hit'/'boss-killed' events, filtered to the
 * viewer's own. `combo`/`bestToday` mirror createBuddy's own combo counter
 * (via its `onComboChange` callback) for the mockup's ×N combo + decay bar;
 * `bestToday` is client-side only, reset on reload — the same tier of
 * ephemeral cosmetic stat as the rest of this panel.
 *
 * @param {number} me the viewer's own user id
 * @return {object}
 */
export function clawdBuddyPanel(me) {
  return {
    buddy: null,
    unbindBuddy: null,
    combo: 0,
    bestToday: 0,
    init() {
      const key = bestComboKey(new Date());
      try {
        this.bestToday = Number(localStorage.getItem(key)) || 0;
      } catch {
        this.bestToday = 0;
      }
      this.buddy = createBuddy(this.$el, {
        onComboChange: c => {
          this.combo = c;
          if (c > this.bestToday) {
            this.bestToday = c;
            try {
              localStorage.setItem(key, String(c));
            } catch {
              // storage unavailable (private mode): the best still shows for this visit
            }
          }
        },
      });
      const attach = () => {
        if (!window.__battlefield?.bindBus) {
          requestAnimationFrame(attach);

          return;
        }
        this.unbindBuddy = window.__battlefield.bindBus(window.__battlefield.bus, {
          hit: payload => { if (Number(payload.user_id) === Number(me)) { this.buddy.onHit(payload.damage); } },
          'boss-killed': payload => { if (Number(payload.killer_user_id) === Number(me)) { this.buddy.onKill(); } },
        });
      };
      attach();
    },
    destroy() {
      this.buddy?.destroy();
      this.unbindBuddy?.();
    },
  };
}

/**
 * Alpine.data() component for a live "… ago" stamp: ticks from the time in
 * its own data-since (unix seconds, 0 for never), which each Livewire
 * re-render rewrites — so a refresh reads "just now". The header's refresh
 * stamp uses the default wording; the last-hit stamp uses 'coarse'.
 *
 * @param {'fine'|'coarse'} [format]
 * @return {object}
 */
export function agoTicker(format = 'fine') {
  const label = format === 'coarse' ? coarseAgo : agoLabel;

  return {
    label: '',
    timer: null,
    init() {
      this.tick();
      this.timer = setInterval(() => this.tick(), 15_000);
    },
    /**
     * Re-reads data-since and relabels.
     *
     * @return {void}
     */
    tick() {
      const since = Number(this.$el.dataset.since) || 0;
      this.label = since ? label(Date.now() / 1000 - since) : 'never';
    },
    destroy() {
      clearInterval(this.timer);
    },
  };
}
