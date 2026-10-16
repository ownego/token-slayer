import { ROSTER, stripUrl } from '@battlefield/sheet/sheet-roster.js';

/**
 * The existing fighter whose animation alpha forms the empty-stage shadow.
 *
 * @type {object}
 */
const REFERENCE_FIGHTER = ROSTER.find(fighter => fighter.key === 'lancer');

/**
 * The four source-sheet rows, paired with a real fighter animation.
 * Source pose counts describe the uploaded sheet, not the reference strip.
 *
 * @type {Array<{key: string, label: string, sourcePoses: string, row: number, caption: string, animation: string}>}
 */
const SOURCE_ROWS = [
  { key: 'idle', label: 'Idle', sourcePoses: '2–4', row: 1, animation: 'idle', caption: 'Use 2–4 poses. The final idle pose gently bobs in game.' },
  { key: 'walk', label: 'Walk', sourcePoses: '4', row: 2, animation: 'walk', caption: 'Alternate arms and legs across four steps.' },
  { key: 'attack', label: 'Attack', sourcePoses: '3', row: 3, animation: 'attack1', caption: 'Wind up, strike, then follow through.' },
  { key: 'death', label: 'Death', sourcePoses: '3', row: 4, animation: 'death', caption: 'Fall through three distinct poses.' },
];

/**
 * Reference animation data in the order the builder reads source rows.
 * The preview plays every reference frame; source pose counts remain separate.
 *
 * @type {Array<{key: string, label: string, sourcePoses: string, row: number, caption: string, rate: number, strip: string, stripFrames: number, sequence: number[]}>}
 */
export const prototypeRows = SOURCE_ROWS.map(({ animation, ...source }) => {
  const reference = REFERENCE_FIGHTER.anims[animation];
  const sequence = Array.from({ length: reference.frames }, (_, frame) => frame);

  return { ...source, rate: reference.rate, strip: stripUrl(reference.file), stripFrames: reference.frames, sequence };
});

/**
 * Returns the reference fighter frame at a playback tick.
 *
 * @param {string} action The selected source-sheet row.
 * @param {number} tick The elapsed animation frame.
 * @return {{spriteFrame: number, poseNumber: number}} The strip frame and displayed count.
 */
export function prototypeFrame(action, tick) {
  const row = prototypeRows.find(candidate => candidate.key === action) ?? prototypeRows[0];
  const frame = action === 'death'
    ? Math.min(Math.abs(tick), row.sequence.length - 1)
    : Math.abs(tick) % row.sequence.length;
  return { spriteFrame: row.sequence[frame], poseNumber: frame + 1 };
}

/**
 * Alpine controller for the empty Forge's motion example.
 *
 * @return {object} Client-only selection and playback state.
 */
export function posePrototype() {
  return {
    rows: prototypeRows,
    action: 'walk',
    tick: 0,
    playing: true,
    motionReduced: false,
    timer: null,
    motionQuery: null,
    motionChanged: null,
    observer: null,
    get ended() {
      return this.action === 'death' && this.tick >= this.row.sequence.length - 1;
    },
    get row() {
      return prototypeRows.find(candidate => candidate.key === this.action);
    },
    get frame() {
      return prototypeFrame(this.action, this.tick);
    },
    get poseNumber() {
      return this.frame.poseNumber;
    },
    /**
     * Start playback only while the example is visible and motion is allowed.
     *
     * @return {void}
     */
    init() {
      this.motionQuery = globalThis.matchMedia?.('(prefers-reduced-motion: reduce)') ?? null;
      this.motionReduced = this.motionQuery?.matches ?? false;
      this.motionChanged = () => {
        this.motionReduced = this.motionQuery?.matches ?? false;
        this._syncPlayback();
      };
      this.motionQuery?.addEventListener?.('change', this.motionChanged);
      const targets = [this.$el?.closest('.showcase'), this.$el?.closest('.tab-panel'), this.$el?.closest('.fs'), this.$el?.closest('[x-data="sheetFrame()"]')].filter(Boolean);
      if (targets.length && typeof MutationObserver === 'function') {
        this.observer = new MutationObserver(() => this._syncPlayback());
        targets.forEach(target => this.observer.observe(target, { attributes: true, attributeFilter: ['class', 'style'] }));
      }
      globalThis.document?.addEventListener?.('visibilitychange', this.motionChanged);
      this._syncPlayback();
    },
    /**
     * Select another action and begin its example at the first pose.
     *
     * @param {string} action The source-sheet row key.
     * @return {void}
     */
    select(action) {
      if (!prototypeRows.some(row => row.key === action)) {
        return;
      }
      this.action = action;
      this.tick = 0;
      this.playing = true;
      this._syncPlayback();
    },
    /**
     * Pause or resume the example; replay Death from its first pose.
     *
     * @return {void}
     */
    togglePlayback() {
      if (this.motionReduced) {
        return;
      }
      if (this.ended) {
        this.tick = 0;
        this.playing = true;
      } else {
        this.playing = !this.playing;
      }
      this._syncPlayback();
    },
    /**
     * Match playback to visibility and the user's motion preference.
     *
     * @return {void}
     */
    _syncPlayback() {
      clearInterval(this.timer);
      this.timer = null;
      const showcase = this.$el?.closest('.showcase');
      const tab = this.$el?.closest('.tab-panel');
      const sheet = this.$el?.closest('.fs');
      const frame = this.$el?.closest('[x-data="sheetFrame()"]');
      const visible = (!showcase || (showcase.classList.contains('blank') && showcase.classList.contains('editing')))
        && (!tab || tab.classList.contains('is-active'))
        && sheet?.style.display !== 'none'
        && frame?.style.display !== 'none'
        && globalThis.document?.visibilityState !== 'hidden';
      if (visible && this.playing && !this.ended && !this.motionReduced) {
        this.timer = setInterval(() => {
          this.tick += 1;
          if (this.ended) {
            this._syncPlayback();
          }
        }, 1000 / this.row.rate);
      }
    },
    /**
     * Release playback and listeners when Livewire removes the sheet.
     *
     * @return {void}
     */
    destroy() {
      clearInterval(this.timer);
      this.motionQuery?.removeEventListener?.('change', this.motionChanged);
      globalThis.document?.removeEventListener?.('visibilitychange', this.motionChanged);
      this.observer?.disconnect();
    },
  };
}
