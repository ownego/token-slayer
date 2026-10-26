// The moves a look of the Minions tab's stage can be made to play (Idle, Walk, Attack, Fall) and how each
// one is played: pure and Phaser-free, so the strips, the Fall timing and the CSS variables that drive the
// playback can be tested without a browser. A default look takes its strips from `MINION_TYPES` (the server
// does not hold them); a custom look from the animation table of its spec. The stage (`minion-stage.js`)
// owns the timers; this module only says what to play and for how long.
import { MINION_FALL_HOLD_MS, MINION_TYPES } from '@battlefield/config.js';
import { CUSTOM_FRAME, stripUrls } from '@battlefield/fighter/custom-fighter.js';
import { lookOf } from './minion-thumb.js';

/**
 * The moves in the order the hotbar shows them: the id a move is played by, which is also the key of its
 * strip in a `MoveSet`, and its label.
 *
 * @type {Array<{id: string, label: string}>}
 */
export const MOVE_LIST = [
  { id: 'idle', label: 'Idle' },
  { id: 'walk', label: 'Walk' },
  { id: 'attack', label: 'Attack' },
  { id: 'fall', label: 'Fall' },
];

/**
 * One strip of a look, ready for `lookStyle`: where it is served from, how it steps, and the frame and body
 * it was drawn with.
 *
 * @typedef {{url: string, frames: number, rate: number, frame: number, bodyPx: number}} MoveStrip
 */

/**
 * What a look can play: the moves it has a strip for (in hotbar order), those strips, and how its Fall goes.
 *
 * @typedef {{
 *   moves: Array<{id: string, label: string}>,
 *   strips: Object<string, MoveStrip>,
 *   fall: {holdMs: number, getUp: boolean},
 * }} MoveSet
 */

/**
 * One beat of a move: `loop` repeats its strip until told otherwise, `once` plays it forwards and holds the
 * last frame, `back` plays it backwards and holds the first. `ms` is how long the beat lasts before the next
 * one starts (null for a loop, which lasts until another move is played).
 *
 * @typedef {{phase: 'loop'|'once'|'back', strip: string, ms: ?number}} MoveBeat
 */

/**
 * Whether an animation table entry can be played.
 *
 * @param {*} entry `{frames, rate}` off a spec's animation table.
 * @return {boolean}
 */
function playable(entry) {
  return Boolean(entry) && Number.isFinite(entry.frames) && entry.frames > 0 && Number.isFinite(entry.rate) && entry.rate > 0;
}

/**
 * The strip of one animation file of a default minion type.
 *
 * @param {?{file: string, frameWidth: number, count: number, rate: number}} file An `animFiles` entry.
 * @param {number} bodyPx The look's measured body height.
 * @return {?MoveStrip}
 */
function defaultStrip(file, bodyPx) {
  return file ? { url: file.file, frames: file.count, rate: file.rate, frame: file.frameWidth, bodyPx } : null;
}

/**
 * The strips and the Fall of a default minion type: its first attack (the field picks one at random), and
 * the animation its `reaction` plays when it is hit. A type whose reaction is a plain `hurt` lies down for
 * no time and does not get up, as on the field.
 *
 * @param {string} key The default look's key.
 * @param {number} bodyPx The look's measured body height.
 * @return {{strips: Object<string, ?MoveStrip>, fall: {holdMs: number, getUp: boolean}}}
 */
function defaultMoves(key, bodyPx) {
  const type = MINION_TYPES.find(candidate => candidate.key === key);
  const files = type?.animFiles ?? {};
  const reaction = type?.reaction ?? {};

  return {
    strips: {
      walk: defaultStrip(files.walk, bodyPx),
      attack: defaultStrip(files[type?.attacks?.[0]?.anim], bodyPx),
      fall: defaultStrip(files[reaction.anim ?? 'death'], bodyPx),
    },
    fall: { holdMs: reaction.holdMs ?? 0, getUp: reaction.getUp === true },
  };
}

/**
 * The strips and the Fall of a custom look: its `animations` table (`idle`, `walk`, `attack`, `death`)
 * served from its `url_base`. A custom minion always lies for `MINION_FALL_HOLD_MS` and gets up.
 *
 * @param {*} spec A custom look's spec (snake_case off the page, camelCase off a snapshot).
 * @param {number} bodyPx The look's measured body height.
 * @return {{strips: Object<string, ?MoveStrip>, fall: {holdMs: number, getUp: boolean}}}
 */
function customMoves(spec, bodyPx) {
  const urls = stripUrls({ urlBase: spec.url_base ?? spec.urlBase });
  const table = spec.animations ?? {};
  const strip = state => (playable(table[state])
    ? { url: urls[state], frames: table[state].frames, rate: table[state].rate, frame: CUSTOM_FRAME, bodyPx }
    : null);

  return {
    strips: { walk: strip('walk'), attack: strip('attack'), fall: strip('death') },
    fall: { holdMs: MINION_FALL_HOLD_MS, getUp: true },
  };
}

/**
 * What a look can play. Every look in the pool has an idle strip, so every look has Idle; the other moves
 * appear only when the look has a strip for them.
 *
 * @param {*} spec A cell's spec (see `lookOf`).
 * @return {?MoveSet} Null when the spec names nothing drawable.
 */
export function moveSet(spec) {
  const idle = lookOf(spec);

  if (!idle) {
    return null;
  }
  const { strips, fall } = spec.kind === 'default' ? defaultMoves(spec.key, idle.bodyPx) : customMoves(spec, idle.bodyPx);
  const owned = Object.fromEntries(Object.entries({ idle, ...strips }).filter(([, strip]) => strip));

  return { moves: MOVE_LIST.filter(move => owned[move.id]), strips: owned, fall };
}

/**
 * How long a strip plays through once, in ms: the game's own pace (frames at rate).
 *
 * @param {MoveStrip} strip The strip.
 * @return {number}
 */
export function stripMs(strip) {
  return Math.round(strip.frames / strip.rate * 1000);
}

/**
 * The beats a move is played in. Idle and Walk loop. Attack plays once, then the stage returns to Idle. Fall
 * plays the strip once and keeps its last frame for the hold, then (a look that gets up) plays it backwards,
 * then the stage returns to Idle, as the reaction does on the field.
 *
 * @param {?MoveSet} set What the look can play.
 * @param {string} id The move.
 * @return {Array<MoveBeat>} Empty when the look does not have that move.
 */
export function movePlan(set, id) {
  const strip = set?.strips[id];

  if (!strip || !set.moves.some(move => move.id === id)) {
    return [];
  }
  if (id === 'idle' || id === 'walk') {
    return [{ phase: 'loop', strip: id, ms: null }];
  }
  if (id === 'attack') {
    return [{ phase: 'once', strip: id, ms: stripMs(strip) }];
  }
  const down = [{ phase: 'once', strip: id, ms: stripMs(strip) + set.fall.holdMs }];

  return set.fall.getUp ? [...down, { phase: 'back', strip: id, ms: stripMs(strip) }] : down;
}

/**
 * The CSS variables that play a strip in one phase. They set the animation of `.ms-stage .ms-sprite`
 * (`fighter-sheet.css`): a loop repeats forever; `once` and `back` run one time and hold their last frame,
 * stepping through every frame for the same time as the game (`jump-none` lands on the last frame instead of
 * the empty slot past the strip).
 *
 * A browser does not restart a running animation when only its duration or timing changes, so two one-shots
 * in a row (Attack, then Fall) must not share a keyframes name. Each has a twin (`-b`) with the same frames, and
 * the stage uses the other one of the pair for every new beat.
 *
 * @param {'loop'|'once'|'back'} phase The beat's phase.
 * @param {MoveStrip} strip The strip being played.
 * @param {boolean} [twin] Whether to use the twin keyframes of a one-shot, so it restarts the one before.
 * @return {Object<string, string|number>}
 */
export function playbackStyle(phase, strip, twin = false) {
  if (phase === 'loop') {
    return { '--ms-anim': 'fs-pool-play', '--ms-ease': `steps(${strip.frames})`, '--ms-iter': 'infinite' };
  }

  return {
    '--ms-anim': `${phase === 'back' ? 'fs-ms-back' : 'fs-ms-once'}${twin ? '-b' : ''}`,
    '--ms-ease': `steps(${Math.max(2, strip.frames)}, jump-none)`,
    '--ms-iter': '1',
  };
}
