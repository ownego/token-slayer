// The Minions tab's stage: the look of the selected cell at stage size (idle until a move of the hotbar under it
// is played), and under that an "At field size" strip with the equipped fighter beside the minion, each drawn
// in the proportion the battlefield draws them. There is one stage for the whole tab (`wire:ignore`): it sits inside
// the pool's `minionPool` scope and follows its `sel` (the pick), its `board` (a minion replaced in place brings a
// new version) and its `entered` (the pane came into view), summoning each look it is given like the Fighter stage.
import { BY_KEY, strip as playStrip } from './sprite-strip.js';
import { BOX, stripUrl } from './sheet-roster.js';
import { CUSTOM_KEY, registerCustomRoster } from './custom-roster.js';
import { fieldSizeFighterScale, fieldSizeMinionScale } from './minion-preview.js';
import { lookOf, lookStyle, thumbStyle } from './minion-thumb.js';
import { movePlan, moveSet, playbackStyle } from './minion-moves.js';

/**
 * The summon's circle and burst: the necromancer strips the Fighter stage summons with.
 *
 * @type {Object<string, {url: string, frames: number, rate: number}>}
 */
const SUMMON_FX = {
  circle: { url: '/assets/battlefield/companions/necromancer/summon-circle.png', frames: 7, rate: 14 },
  burst: { url: '/assets/battlefield/companions/necromancer/appear-burst.png', frames: 6, rate: 12 },
};

/**
 * The height, in px, the stage gives the selected look's body.
 *
 * @type {number}
 */
export const STAGE_BODY_PX = 96;

/**
 * The field scale the "At field size" strip shows the fighter at: the fighter's body stands
 * `FIELD_SCALE * SPRITE_CHAR_HEIGHT` px tall and the minion half of that (`MINION_SIZE_RATIO`).
 *
 * @type {number}
 */
export const FIELD_SCALE = 3;

/**
 * How long the summon pop of a look with no death strip to rise from lasts, in ms: the CSS animation of
 * `.ms-sprite.summon` runs for this long.
 *
 * @type {number}
 */
export const SUMMON_MS = 700;

/**
 * The equipped fighter as the "At field size" strip draws it: its idle strip, how it steps, and the body box
 * the roster measured for it (the strip is scaled so that body stands as tall as the field draws a fighter).
 *
 * @param {string} key The equipped fighter's roster key.
 * @return {?{url: string, frames: number, rate: number, frame: number, bodyPx: number, footRatio: number}} Null when the roster has no such fighter.
 */
export function fighterLook(key) {
  const idle = BY_KEY[key]?.anims?.idle;
  const box = BOX[key];

  if (!idle || !box) {
    return null;
  }

  return { url: stripUrl(idle.file), frames: idle.frames, rate: idle.rate, frame: 100, bodyPx: box[3] - box[1], footRatio: box[3] / 100 };
}

/**
 * The two sprites of the "At field size" strip: the fighter and the minion beside it, each at the scale the
 * field would draw it, standing on one floor line.
 *
 * @param {?import('./minion-thumb.js').PoolLook} minion The selected look.
 * @param {?ReturnType<typeof fighterLook>} fighter The equipped fighter.
 * @param {boolean} still Hold both on their first frame.
 * @return {{fighter: ?Object<string, string|number>, minion: ?Object<string, string|number>}}
 */
export function fieldStyles(minion, fighter, still) {
  return {
    fighter: fighter
      ? lookStyle(fighter, fieldSizeFighterScale(FIELD_SCALE, fighter.bodyPx), { still, footRatio: fighter.footRatio })
      : null,
    minion: minion
      ? lookStyle(minion, fieldSizeMinionScale(FIELD_SCALE, minion.bodyPx), { still })
      : null,
  };
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
 * Alpine.data() component for the Minions tab's stage.
 *
 * @param {{look?: *, lookKey?: string, preview?: boolean, summon?: boolean, equipped?: ?string, custom?: ?object}} [initial]
 *   `look` is the cell's spec (see `lookOf`); `lookKey` the cell it is (`slot:2`), which a `custom-minions-changed`
 *   naming it as `changed` summons; `preview` marks a minion not yet in the set; `summon` plays the summon once at
 *   start (the stage was re-created by the Add that put this minion here); `equipped` and `custom` are the
 *   viewer's equipped fighter key and, when that is the custom fighter, its state.
 * @return {object}
 */
export function minionStage(initial = {}) {
  return {
    look: null,
    fighter: null,
    preview: false,
    reduced: false,
    summoning: false,
    summonTimer: null,
    summonSeq: 0,
    landed: 0,
    lookKey: null,
    lookName: null,
    shownVersion: null,
    onChanged: null,
    onFall: null,
    moveSet: null,
    moves: [],
    move: 'idle',
    phase: 'loop',
    strip: 'idle',
    twin: true,
    moveTimer: null,
    /**
     * Reads the viewer's motion preference, draws the first look, listens for the pool changing and for a Remove
     * (`minion-stage-fall`, the look falls before its slot empties), and follows the pool's pick and board.
     *
     * @return {void}
     */
    init() {
      this.reduced = prefersReducedMotion();
      this.fighter = this.equippedFighter();
      this.lookKey = initial.lookKey ?? null;
      this.lookName = this.board?.looks?.[this.lookKey]?.name ?? null;
      this.shownVersion = this.board?.looks?.[this.lookKey]?.version ?? null;
      this.showLook(initial.look, initial.preview === true);
      this.onChanged = event => this.onPoolChanged(event.detail);
      window.addEventListener('custom-minions-changed', this.onChanged);
      this.onFall = () => {
        if (!this.reduced) {
          this.play('fall');
        }
      };
      window.addEventListener('minion-stage-fall', this.onFall);
      if (typeof this.$watch === 'function') {
        this.$watch('sel', cell => this.showCell(cell));
        this.$watch('entered', () => this.summon());
      }
      this.$wire?.$watch?.('board', () => this.onBoard());

      if (initial.summon === true) {
        this.summon();
      }
    },
    /**
     * Puts a cell of the pool's board on the stage and summons it. A cell with nothing to draw (an empty slot)
     * leaves the stage as it was; the pool hides it.
     *
     * @param {string} cell The cell (`default:<key>`, `slot:<n>`, `overflow`).
     * @return {void}
     */
    showCell(cell) {
      const shown = this.board?.looks?.[cell];

      if (!shown?.spec) {
        return;
      }
      this.lookKey = cell;
      this.lookName = shown.name;
      this.shownVersion = shown.version;
      this.showLook(shown.spec, shown.preview === true);
      this.summon();
    },
    /**
     * Draws the cell on the stage again when the server's board brings it in another version (a minion
     * replaced in its slot, a new preview).
     *
     * @return {void}
     */
    onBoard() {
      const shown = this.board?.looks?.[this.sel];

      if (shown?.spec && shown.version !== this.shownVersion) {
        this.showCell(this.sel);
      }
    },
    /**
     * Stops listening and cancels a pending summon end and the move being played.
     *
     * @return {void}
     */
    destroy() {
      window.removeEventListener('custom-minions-changed', this.onChanged);
      window.removeEventListener('minion-stage-fall', this.onFall);
      clearTimeout(this.summonTimer);
      clearTimeout(this.moveTimer);
      this.summonSeq++;
    },
    /**
     * The equipped fighter for the field-size strip: a roster fighter as is, the custom fighter after it
     * has been put into the sheet's roster lookups. Another fighter's registration is left alone.
     *
     * @return {?ReturnType<typeof fighterLook>}
     */
    equippedFighter() {
      const key = initial.equipped;

      if (typeof key !== 'string') {
        return null;
      }
      if (key === CUSTOM_KEY) {
        registerCustomRoster(BY_KEY, BOX, initial.custom ?? null);
      }

      return fighterLook(key);
    },
    /**
     * Puts a look on the stage, replacing the one that was there. It stands idle: the move being played
     * on the look before it is cut short.
     *
     * @param {*} spec The cell's spec (see `lookOf`).
     * @param {boolean} [preview] Whether the look is a minion that has not been added yet.
     * @return {void}
     */
    showLook(spec, preview = false) {
      this.look = lookOf(spec);
      this.preview = preview;
      this.moveSet = moveSet(spec);
      this.moves = this.moveSet?.moves ?? [];
      this.settle();
    },
    /**
     * Plays a move of the hotbar on the stage sprite. Idle and Walk repeat; Attack plays once and Fall lies
     * down, stays down and gets up, then the stage goes back to Idle. Another move cuts the one playing short;
     * the move already playing is left alone. Under reduced motion nothing plays: the sprite stands on the
     * first frame of the move's strip, and the move still changes.
     *
     * @param {string} id The move (`idle`, `walk`, `attack`, `fall`).
     * @return {void}
     */
    play(id) {
      const plan = movePlan(this.moveSet, id);

      if (plan.length === 0 || (id === this.move && this.phase !== 'loop')) {
        return;
      }
      clearTimeout(this.moveTimer);
      this.move = id;
      this.beat(plan, 0);
    },
    /**
     * Starts one beat of a move and, unless it is a loop, schedules the next one (or the return to Idle).
     *
     * @param {Array<import('./minion-moves.js').MoveBeat>} plan The move's beats.
     * @param {number} index The beat to start.
     * @return {void}
     */
    beat(plan, index) {
      const { phase, strip, ms } = plan[index];

      this.phase = phase;
      this.strip = strip;
      this.twin = !this.twin;

      if (ms === null || this.reduced) {
        return;
      }
      this.moveTimer = setTimeout(() => (index + 1 < plan.length ? this.beat(plan, index + 1) : this.settle()), ms);
    },
    /**
     * Goes back to standing idle, dropping any move still playing.
     *
     * @return {void}
     */
    settle() {
      clearTimeout(this.moveTimer);
      this.move = 'idle';
      this.phase = 'loop';
      this.strip = 'idle';
    },
    /**
     * The sprite's style: the strip of the move being played at stage size, with the variables that play it
     * (see `playbackStyle`; every beat swaps between twin keyframes so the animation restarts), held on its first frame under reduced motion.
     *
     * @return {Object<string, string|number>}
     */
    get spriteStyle() {
      const strip = this.moveSet?.strips[this.strip] ?? this.look;

      return strip ? { ...thumbStyle(strip, STAGE_BODY_PX, this.reduced), ...playbackStyle(this.phase, strip, this.twin) } : {};
    },
    /**
     * The "At field size" strip's two sprites.
     *
     * @return {{fighter: ?Object<string, string|number>, minion: ?Object<string, string|number>}}
     */
    get field() {
      return fieldStyles(this.look, this.fighter, this.reduced);
    },
    /**
     * Reacts to the pool changing: summons this look when the change says it was the one just added or replaced.
     *
     * @param {?{changed?: ?string}} detail The `custom-minions-changed` detail.
     * @return {void}
     */
    onPoolChanged(detail) {
      if (detail?.changed && detail.changed === (this.sel ?? this.lookKey)) {
        this.summon();
      }
    },
    /**
     * Summons the look on the stage, the way the Fighter stage summons a fighter: the necromancer circle and
     * burst under it, the look rising (its death strip played backwards when it gets up from one, else a pop),
     * then a small landing. A newer summon (a quick second pick) cuts this one short before it lands. Nothing
     * plays under reduced motion: the toast and the lit cell say it.
     *
     * @return {void}
     */
    summon() {
      const seq = ++this.summonSeq;
      clearTimeout(this.summonTimer);
      this.summoning = false;

      if (this.reduced) {
        return;
      }
      this.playFx();
      const rise = this.moveSet?.fall?.getUp ? movePlan(this.moveSet, 'fall').at(-1) : null;

      if (rise) {
        clearTimeout(this.moveTimer);
        this.move = 'idle';
        this.beat([rise], 0);
        clearTimeout(this.moveTimer);
      } else {
        this.summoning = true;
      }
      this.summonTimer = setTimeout(() => {
        this.summoning = false;
        if (seq !== this.summonSeq) {
          return;
        }
        this.settle();
        this.land();
      }, rise ? rise.ms : SUMMON_MS);
    },
    /**
     * Plays the circle and the burst strips under the stage sprite.
     *
     * @return {void}
     */
    playFx() {
      ['circle', 'burst'].forEach(name => {
        const el = this.$el?.querySelector?.(`.fx-${name}`);

        if (!el) {
          return;
        }
        const fx = SUMMON_FX[name];
        el.style.opacity = 1;
        playStrip(el, fx.url, fx.frames, fx.rate).then(() => { el.style.opacity = 0; });
      });
    },
    /**
     * The landing at the end of a summon: a squash on the sprite.
     *
     * @return {void}
     */
    land() {
      this.landed++;
      this.$el?.querySelector?.('.ms-stage .ms-sprite')?.animate?.([
        { transform: 'scale(1.18, 0.82)' },
        { transform: 'scale(1, 1)' },
      ], { duration: 260, easing: 'cubic-bezier(.34,1.56,.64,1)' });
    },
  };
}
