// The Minions tab's stage: the look of the selected cell at stage size (idle until a move of the hotbar under it
// is played), and under that an "At field size" strip with the equipped fighter beside the minion, each drawn
// in the proportion the battlefield draws them. The blade re-creates the stage element (a new `wire:key`) whenever the selected
// cell or its version changes, so one instance shows one look; `showLook` is what `init` calls, and what
// a caller can use to swap the look in place.
import { BY_KEY } from './sprite-strip.js';
import { BOX, stripUrl } from './sheet-roster.js';
import { CUSTOM_KEY, registerCustomRoster } from './custom-roster.js';
import { fieldSizeFighterScale, fieldSizeMinionScale } from './minion-preview.js';
import { lookOf, lookStyle, thumbStyle } from './minion-thumb.js';
import { movePlan, moveSet, playbackStyle } from './minion-moves.js';

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
 * How long the summon pop lasts, in ms: the CSS animation of `.ms-sprite.summon` runs for this long.
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
    summoned: false,
    summonTimer: null,
    onChanged: null,
    moveSet: null,
    moves: [],
    move: 'idle',
    phase: 'loop',
    strip: 'idle',
    twin: true,
    moveTimer: null,
    /**
     * Reads the viewer's motion preference, draws the first look and listens for the pool changing.
     *
     * @return {void}
     */
    init() {
      this.reduced = prefersReducedMotion();
      this.fighter = this.equippedFighter();
      this.showLook(initial.look, initial.preview === true);
      this.onChanged = event => this.onPoolChanged(event.detail);
      window.addEventListener('custom-minions-changed', this.onChanged);

      if (initial.summon === true) {
        this.summon();
      }
    },
    /**
     * Stops listening and cancels a pending summon end and the move being played.
     *
     * @return {void}
     */
    destroy() {
      window.removeEventListener('custom-minions-changed', this.onChanged);
      clearTimeout(this.summonTimer);
      clearTimeout(this.moveTimer);
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
      if (initial.lookKey && detail?.changed === initial.lookKey) {
        this.summon();
      }
    },
    /**
     * Plays the summon pop once. Nothing under reduced motion: the toast and the lit cell say it.
     *
     * @return {void}
     */
    summon() {
      if (this.summoned) {
        return;
      }
      this.summoned = true;

      if (this.reduced) {
        return;
      }
      this.summoning = true;
      this.summonTimer = setTimeout(() => {
        this.summoning = false;
      }, SUMMON_MS);
    },
  };
}
