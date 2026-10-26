// The Minions tab's pool in the browser (`livewire/custom-minion-editor.blade.php`): which cell is on the stage and
// which looks the player has ticked and not saved yet. Picking and ticking never wait for the server, like the
// Fighter tab's roster; Save sends the whole choice to `CustomMinionEditor::save()` and, when the player's minions
// are out, folds the sheet onto the field the way Equip does. The server's view of the pool is `$wire.board`:
// `saved` (look => ticked as stored, for every look a tick can change) and `looks` (look => its spec, version,
// preview flag, name, slot and minion hash), refilled by every render.

/**
 * How long after a written Save the sheet folds onto the field: the Save button's flash lands first.
 *
 * @type {number}
 */
export const SAVE_FOLD_DELAY_MS = 400;

/**
 * How long the stage stays up after Remove so the minion's fall plays before its slot empties.
 *
 * @type {number}
 */
export const LEAVE_MS = 700;

/**
 * Whether a look is in the choice: the player's own tick when they toggled it, else the stored one.
 *
 * @param {Object<string, boolean>} saved Look => ticked as stored.
 * @param {Object<string, {ticked: boolean, hash: ?string}>} pending The ticks toggled and not saved.
 * @param {string} look The cell (`default:<key>`, `slot:<n>`).
 * @return {boolean}
 */
export function tickedOf(saved, pending, look) {
  return pending[look]?.ticked ?? saved[look] ?? false;
}

/**
 * The ticks not saved after the player toggles one look. A look toggled back to what is stored drops out, so
 * the choice is unsaved only while it differs. A look no tick can change (an empty slot, a preview) is left alone.
 *
 * @param {Object<string, boolean>} saved Look => ticked as stored.
 * @param {Object<string, {ticked: boolean, hash: ?string}>} pending The ticks toggled and not saved.
 * @param {Object<string, {hash: ?string}>} looks Look => what the server drew there.
 * @param {string} look The cell toggled.
 * @return {Object<string, {ticked: boolean, hash: ?string}>}
 */
export function toggled(saved, pending, looks, look) {
  if (!(look in saved)) {
    return pending;
  }
  const next = { ...pending };
  const ticked = !tickedOf(saved, pending, look);

  if (ticked === saved[look]) {
    delete next[look];
  } else {
    next[look] = { ticked, hash: looks[look]?.hash ?? null };
  }

  return next;
}

/**
 * The ticks not saved that still mean something after the server drew the pool again: a look that is gone, a
 * slot whose minion was replaced since it was toggled, and a tick that now equals the stored one are dropped.
 *
 * @param {Object<string, boolean>} saved Look => ticked as stored.
 * @param {Object<string, {ticked: boolean, hash: ?string}>} pending The ticks toggled and not saved.
 * @param {Object<string, {hash: ?string}>} looks Look => what the server drew there.
 * @return {Object<string, {ticked: boolean, hash: ?string}>}
 */
export function pruned(saved, pending, looks) {
  return Object.fromEntries(Object.entries(pending).filter(([look, tick]) => look in saved
    && (looks[look]?.hash ?? null) === tick.hash
    && tick.ticked !== saved[look]));
}

/**
 * Whether the choice differs from what is stored.
 *
 * @param {Object<string, boolean>} saved Look => ticked as stored.
 * @param {Object<string, {ticked: boolean, hash: ?string}>} pending The ticks toggled and not saved.
 * @return {boolean}
 */
export function isUnsaved(saved, pending) {
  return Object.entries(pending).some(([look, tick]) => look in saved && tick.ticked !== saved[look]);
}

/**
 * Whether every look a tick can change is out of the choice (the swarm then falls back to the defaults).
 *
 * @param {Object<string, boolean>} saved Look => ticked as stored.
 * @param {Object<string, {ticked: boolean, hash: ?string}>} pending The ticks toggled and not saved.
 * @return {boolean}
 */
export function nothingTicked(saved, pending) {
  const tickable = Object.keys(saved);

  return tickable.length > 0 && tickable.every(look => !tickedOf(saved, pending, look));
}

/**
 * What Save sends: the hash of every minion the tab drew (by slot), the default keys left out and the slots
 * left out.
 *
 * @param {Object<string, boolean>} saved Look => ticked as stored.
 * @param {Object<string, {ticked: boolean, hash: ?string}>} pending The ticks toggled and not saved.
 * @param {Object<string, {slot: ?number, hash: ?string}>} looks Look => what the server drew there.
 * @return {{hashes: Object<number, string>, defaultsOff: Array<string>, slotsOff: Array<number>}}
 */
export function choicePayload(saved, pending, looks) {
  const hashes = {};
  const defaultsOff = [];
  const slotsOff = [];

  Object.keys(saved).forEach(look => {
    const ticked = tickedOf(saved, pending, look);

    if (look.startsWith('default:')) {
      if (!ticked) {
        defaultsOff.push(look.slice('default:'.length));
      }

      return;
    }
    const { slot, hash } = looks[look] ?? {};
    hashes[slot] = hash;
    if (!ticked) {
      slotsOff.push(slot);
    }
  });

  return { hashes, defaultsOff, slotsOff };
}

/**
 * Whether the viewer asked for less motion.
 *
 * @return {boolean}
 */
function prefersReducedMotion() {
  return typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Whether the viewer's own minions are on the field right now, so a Save has something to show there.
 *
 * @return {boolean}
 */
function minionsOut() {
  const scene = window.__battlefield?.scene;
  const me = scene?.currentUserId;
  const byUser = scene?.minions?.byUser;

  return (byUser?.get(me)?.length ?? byUser?.get(Number(me))?.length ?? byUser?.get(String(me))?.length ?? 0) > 0;
}

/**
 * Shows a line in the sheet's toast, as a `sheet-toast` from the server does.
 *
 * @param {string} message The line.
 * @return {void}
 */
function toast(message) {
  window.dispatchEvent(new CustomEvent('sheet-toast', { detail: { message } }));
}

/**
 * Alpine.data() component on the Minions tab's `.cme-body`.
 *
 * @param {{selected?: string}} [initial] The cell the server opened the tab on.
 * @return {object}
 */
export function minionPool(initial = {}) {
  return {
    sel: initial.selected ?? '',
    pending: {},
    saving: false,
    replacing: null,
    asking: null,
    removing: false,
    leaving: false,
    entered: 0,
    onReset: null,
    observer: null,
    /**
     * Follows the server's board and selection, listens for another tab's change and replays the entrance
     * each time the pane comes into view.
     *
     * @return {void}
     */
    init() {
      this.$wire.$watch('board', () => {
        this.pending = pruned(this.board.saved, this.pending, this.board.looks);
        const replaced = this.board.looks[`slot:${this.replacing}`];
        if (this.replacing !== null && (!replaced?.spec || replaced.preview)) {
          this.replacing = null;
        }
      });
      this.$wire.$watch('selected', cell => {
        if (cell && cell !== this.sel) {
          this.sel = cell;
        }
      });
      this.onReset = () => {
        const dropped = isUnsaved(this.board.saved, this.pending);
        this.pending = {};
        if (dropped) {
          toast('Your minions changed in another tab. Check the pool, then save again.');
        }
      };
      window.addEventListener('minion-pool-reset', this.onReset);
      this.watchVisibility();
    },
    /**
     * Stops listening.
     *
     * @return {void}
     */
    destroy() {
      window.removeEventListener('minion-pool-reset', this.onReset);
      this.observer?.disconnect();
    },
    /**
     * The server's view of the pool.
     *
     * @return {{saved: Object<string, boolean>, looks: Object<string, object>}}
     */
    get board() {
      return this.$wire.board ?? { saved: {}, looks: {} };
    },
    /**
     * Whether the choice differs from what is stored.
     *
     * @return {boolean}
     */
    get unsaved() {
      return isUnsaved(this.board.saved, this.pending);
    },
    /**
     * Whether every look is out of the choice.
     *
     * @return {boolean}
     */
    get nothingTicked() {
      return nothingTicked(this.board.saved, this.pending);
    },
    /**
     * Whether the cell on the stage has a look to draw (an empty slot has none).
     *
     * @return {boolean}
     */
    get hasStage() {
      return this.leaving || Boolean(this.board.looks[this.sel]?.spec);
    },
    /**
     * Whether the upload area shows: for an empty slot, and for the slot whose minion is being replaced.
     *
     * @return {boolean}
     */
    get makeOpen() {
      const cell = this.board.looks[this.sel];

      return Boolean(cell && cell.slot !== null && !cell.preview && (!cell.spec || this.sel === `slot:${this.replacing}`));
    },
    /**
     * Whether a look is ticked in the choice.
     *
     * @param {string} look The cell.
     * @return {boolean}
     */
    ticked(look) {
      return tickedOf(this.board.saved, this.pending, look);
    },
    /**
     * Whether a look is summoned with the choice as it stands: ticked, or a default look while nothing is.
     *
     * @param {string} look The cell.
     * @return {boolean}
     */
    inPool(look) {
      return this.ticked(look) || (look.startsWith('default:') && this.nothingTicked);
    },
    /**
     * Whether the player toggled a look away from what is stored.
     *
     * @param {string} look The cell.
     * @return {boolean}
     */
    isChanged(look) {
      return look in pruned(this.board.saved, this.pending, this.board.looks);
    },
    /**
     * Puts a cell on the stage at once and closes a Replace or a Remove question left open on another cell. The
     * server learns the cell with the next request.
     *
     * @param {string} look The cell.
     * @return {void}
     */
    pick(look) {
      if (look !== this.sel) {
        this.replacing = null;
        this.asking = null;
      }
      this.sel = look;
      this.$wire.$set('selected', look, false);
      // the stage sits under the pool on a tablet and a phone; beside it, `nearest` scrolls nothing
      this.$el.querySelector('.cme-panel')?.scrollIntoView?.({ block: 'nearest', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
    },
    /**
     * Opens the Replace of a minion: the upload area and Use my fighter now aim at its slot.
     *
     * @param {number} slot The minion's slot.
     * @return {void}
     */
    startReplace(slot) {
      this.asking = null;
      this.replacing = slot;
      this.focusLater('cme-upload-');
    },
    /**
     * Closes the Replace: the minion stays as it is.
     *
     * @return {void}
     */
    cancelReplace() {
      const slot = this.replacing;
      this.replacing = null;
      this.focusLater(`cme-replace-open-${slot}-`);
    },
    /**
     * Asks before a Remove, in the panel.
     *
     * @param {number} slot The minion's slot.
     * @return {void}
     */
    ask(slot) {
      this.replacing = null;
      this.asking = slot;
      this.focusLater(`cme-remove-cancel-${slot}-`);
    },
    /**
     * Drops the Remove question and puts focus back on the button that opened it.
     *
     * @return {void}
     */
    dismiss() {
      const slot = this.asking;
      this.asking = null;
      this.focusLater(`cme-remove-open-${slot}-`);
    },
    /**
     * Removes a minion: the stage plays its fall while the request runs and stays up until the fall has played,
     * then the slot shows empty. A second press while one is running does nothing.
     *
     * @param {number} slot The minion's slot.
     * @param {string} hash The version the cell showed, so a minion another tab replaced is not removed.
     * @return {Promise<void>}
     */
    async remove(slot, hash) {
      if (this.removing) {
        return;
      }
      this.removing = true;
      this.leaving = true;
      window.dispatchEvent(new CustomEvent('minion-stage-fall'));
      setTimeout(() => { this.leaving = false; }, LEAVE_MS);

      try {
        await this.$wire.removeMinion(slot, hash);
      } finally {
        this.removing = false;
        this.asking = null;
      }
    },
    /**
     * Builds the chosen sheet into a preview, aimed at the slot being replaced, else into the first empty one.
     *
     * @return {Promise<void>}
     */
    build() {
      return this.$wire.build(this.replacing);
    },
    /**
     * Copies the player's custom fighter into a preview, aimed like a build.
     *
     * @return {Promise<void>}
     */
    useFighter() {
      return this.$wire.useFighter(this.replacing);
    },
    /**
     * Moves focus to one of the tab's elements once it is shown. The id is a constant prefix and a slot, then
     * the component's id.
     *
     * @param {string} prefix The id up to the component id.
     * @return {void}
     */
    focusLater(prefix) {
      this.$nextTick(() => document.getElementById(`${prefix}${this.$wire.$id ?? ''}`)?.focus());
    },
    /**
     * Ticks a look in or out of the choice. Nothing is sent.
     *
     * @param {string} look The cell.
     * @return {void}
     */
    toggle(look) {
      this.pending = toggled(this.board.saved, this.pending, this.board.looks, look);
    },
    /**
     * Drops the choice not saved.
     *
     * @return {void}
     */
    reset() {
      this.pending = {};
    },
    /**
     * Saves the choice. A second press while one is in flight does nothing. A refusal keeps the choice (the
     * server says why in the tab's alert). A written save flashes the button and, when the player's minions are
     * out, folds the sheet onto the field after `SAVE_FOLD_DELAY_MS`; the element is captured before the wait,
     * since the tab is gone once the sheet closes.
     *
     * @return {Promise<void>}
     */
    async saveChoice() {
      if (this.saving || !this.unsaved) {
        return;
      }
      const { hashes, defaultsOff, slotsOff } = choicePayload(this.board.saved, this.pending, this.board.looks);
      const host = this.$el;
      this.saving = true;
      let written = false;

      try {
        written = await this.$wire.save(hashes, defaultsOff, slotsOff);
      } finally {
        this.saving = false;
      }
      if (!written) {
        return;
      }
      this.pending = {};
      const button = host.querySelector('[id^="cme-save-"]');
      button?.classList.remove('flash');
      void button?.offsetWidth;
      button?.classList.add('flash');

      if (minionsOut()) {
        setTimeout(() => host.dispatchEvent(new CustomEvent('fighter-equipped', { bubbles: true })), SAVE_FOLD_DELAY_MS);
      } else {
        toast('Pool saved. Your next subagents summon from it.');
      }
    },
    /**
     * Pops the pool cells in one after another and asks the stage to summon its look. Nothing moves under reduced
     * motion. The class comes off when a cell's pop ends, so a later re-render never replays it.
     *
     * @return {void}
     */
    enter() {
      if (prefersReducedMotion()) {
        return;
      }
      this.$el.querySelectorAll('.pool-cell').forEach(cell => {
        cell.classList.remove('enter');
        void cell.offsetWidth;
        cell.classList.add('enter');
        cell.addEventListener('animationend', () => cell.classList.remove('enter'), { once: true });
      });
      this.entered++;
    },
    /**
     * Replays the entrance each time the pane comes into view: the sheet opening, the Character tab, the Minions
     * sub-tab. The wrapper has no box of its own (`display: contents`), so its parent is watched.
     *
     * @return {void}
     */
    watchVisibility() {
      const target = this.$el.parentElement;
      if (typeof IntersectionObserver !== 'function' || !target) {
        return;
      }
      let visible = false;
      this.observer = new IntersectionObserver(entries => {
        const now = entries.some(entry => entry.isIntersecting);
        if (now && !visible) {
          this.enter();
        }
        visible = now;
      });
      this.observer.observe(target);
    },
  };
}
