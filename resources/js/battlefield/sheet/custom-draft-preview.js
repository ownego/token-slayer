// The draft preview of the custom fighter editor: a built-but-not-used fighter shown on the
// sheet's own show stage (scale 4), with the move hotbar and a play/pause and step control, at
// the frame rate the game plays the strips at. The editor (a Livewire component) renders the
// markup; this Alpine component plays it.
import { BOX } from './sheet-roster.js';
import { BY_KEY, frameIn, moves, paint, playAnim, standOn } from './sprite-strip.js';
import { DRAFT_KEY, registerCustomRoster } from './custom-roster.js';

/**
 * The preview sprite's scale on the stage: the show stage's own, a whole number so the pixels stay crisp.
 * @type {number}
 */
const STAGE_SCALE = 4;

/**
 * How far above the stage's bottom edge the feet stand, in px (the show stage's floor line).
 * @type {number}
 */
const STAGE_FLOOR = 16;

/**
 * The frame to show after stepping one way or the other, wrapping at both ends.
 *
 * @param {number} frame The frame on show.
 * @param {number} frames How many frames the strip has.
 * @param {number} direction 1 for the next frame, -1 for the one before.
 * @return {number}
 */
export function stepFrame(frame, frames, direction) {
  return (frame + direction + frames) % frames;
}

/**
 * The background offset, in px, that puts one 100 px frame of a strip on show.
 *
 * @param {number} frame The frame index.
 * @return {number}
 */
export function frameOffset(frame) {
  return frame === 0 ? 0 : -frame * 100;
}

/**
 * Alpine component for the draft preview. The draft is registered next to the fighter in
 * use under its own roster key, so the sheet's sprite helpers (`moves`, `paint`, `strip`)
 * play it exactly as they play any fighter, and it is taken out again when the preview goes.
 *
 * @param {object} spec The draft as the `custom_character` payload (version, url_base, animations, name).
 * @return {object} The Alpine data object.
 */
export function customDraftPreview(spec) {
  return {
    moves: [],
    active: null,
    playing: true,
    frame: 0,
    entry: null,
    init() {
      registerCustomRoster(BY_KEY, BOX, spec, DRAFT_KEY);
      this.entry = BY_KEY[DRAFT_KEY];
      this.moves = moves(DRAFT_KEY);
      this.$nextTick(() => {
        standOn(this.$refs.sprite, DRAFT_KEY, STAGE_SCALE, STAGE_FLOOR);
        this.select(this.moves[0]);
      });
    },
    destroy() {
      // a newer draft may already have registered itself (the new section is added before the old one goes)
      if (BY_KEY[DRAFT_KEY] === this.entry) {
        registerCustomRoster(BY_KEY, BOX, null, DRAFT_KEY);
      }
    },
    /**
     * Puts a move's strip on the stage, looping at the game's rate.
     *
     * @param {{id: string, anim?: string, reverse?: boolean}} move The hotbar move.
     * @return {void}
     */
    select(move) {
      this.active = move.id;
      this.frame = 0;
      this.playing = true;
      this.$refs.sprite.style.animationPlayState = '';
      playAnim(this.$refs.sprite, DRAFT_KEY, move.anim ?? move.id, { loop: true, reverse: move.reverse });
    },
    /**
     * Paints a hotbar button's looping thumbnail.
     *
     * @param {HTMLElement} el The thumbnail span.
     * @param {{id: string, anim?: string, reverse?: boolean}} move The hotbar move.
     * @return {void}
     */
    thumb(el, move) {
      paint(el, DRAFT_KEY, move.anim ?? move.id, { reverse: move.reverse });
      frameIn(el, DRAFT_KEY, { maxW: 44, maxH: 32, cyPct: 52 });
    },
    /**
     * Pauses the loop where it is, or plays it on (from its first frame again after the
     * frames were stepped through by hand).
     *
     * @return {void}
     */
    toggle() {
      if (!this.playing && this.$refs.sprite.style.animation === 'none') {
        this.select(this.currentMove());

        return;
      }
      this.playing = !this.playing;
      this.$refs.sprite.style.animationPlayState = this.playing ? 'running' : 'paused';
    },
    /**
     * Pauses and shows the next or the previous frame of the strip on show.
     *
     * @param {number} direction 1 for the next frame, -1 for the one before.
     * @return {void}
     */
    step(direction) {
      const move = this.currentMove();
      const anim = BY_KEY[DRAFT_KEY].anims[move.anim ?? move.id];
      const sprite = this.$refs.sprite;
      this.playing = false;
      this.frame = stepFrame(this.frame, anim.frames, direction);
      sprite.style.animation = 'none';
      // a reversed move (Summon) plays its strip from the last frame to the first
      sprite.style.backgroundPosition = `${frameOffset(move.reverse ? anim.frames - 1 - this.frame : this.frame)}px 0`;
    },
    /**
     * The hotbar move on show.
     *
     * @return {{id: string, anim?: string, reverse?: boolean}}
     */
    currentMove() {
      return this.moves.find(m => m.id === this.active);
    },
  };
}
