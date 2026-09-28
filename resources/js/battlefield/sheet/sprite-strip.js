// Sprite-strip playback for the fighter sheet, ported from the approved
// mockup's own helpers (paint/strip/frameIn/frameStage/moves): each
// animation is one horizontal 100px-frame strip stepped by a CSS keyframe,
// so a sprite is a plain <span> the mockup's CSS already styles.
import { FIGHTER_TYPES } from '../config/fighters.js';
import { BOX, LABELS, ROSTER, stripUrl } from './sheet-roster.js';

/**
 * Roster entries by key.
 *
 * @type {Object<string, object>}
 */
export const BY_KEY = Object.fromEntries(ROSTER.map(c => [c.key, c]));

/**
 * One shared <style> the generated keyframes are inserted into; created on
 * first use so importing this module has no DOM side effect.
 *
 * @type {?HTMLStyleElement}
 */
let sheetStyle = null;

/**
 * Keyframe names already inserted into sheetStyle.
 *
 * @type {Set<string>}
 */
const made = new Set();

/**
 * Inserts a background-position keyframe once and returns its name.
 *
 * @param {string} name Keyframe name.
 * @param {number} from Start x offset in px.
 * @param {number} to End x offset in px.
 * @return {string}
 */
function keyframes(name, from, to) {
  if (!made.has(name)) {
    if (!sheetStyle) {
      sheetStyle = document.createElement('style');
      document.head.appendChild(sheetStyle);
    }
    sheetStyle.sheet?.insertRule(`@keyframes ${name} { from { background-position: ${from}px 0; } to { background-position: ${to}px 0; } }`, 0);
    made.add(name);
  }

  return name;
}

/**
 * "orc-rider" → "Orc rider".
 *
 * @param {string} key Fighter key.
 * @return {string}
 */
export function pretty(key) {
  return key.replace(/-/g, ' ').replace(/^./, c => c.toUpperCase());
}

/**
 * Resolves after `ms` milliseconds.
 *
 * @param {number} ms Delay.
 * @return {Promise<void>}
 */
export const wait = ms => new Promise(r => setTimeout(r, ms));

/**
 * Plays a strip on `el`: loop=true repeats, otherwise it lands on (and
 * holds) the real last frame and resolves when done.
 *
 * @param {HTMLElement} el Target element.
 * @param {string} url Strip image URL.
 * @param {number} frames Frame count.
 * @param {number} rate Frames per second.
 * @param {{loop?: boolean, reverse?: boolean, fw?: number, fh?: number}} [opts]
 * @return {Promise<void>}
 */
export function strip(el, url, frames, rate, { loop = false, reverse = false, fw = 100, fh = 100 } = {}) {
  const w = frames * fw;
  const dur = frames / rate;
  let name;
  let steps;
  if (loop) {
    name = keyframes(`kl${fw}_${frames}${reverse ? 'r' : ''}`, reverse ? -(w - fw) : 0, reverse ? fw : -w);
    steps = frames;
  } else {
    // one-shot: stop ON the last frame (ending at -w would show the empty slot past the strip)
    const last = -(w - fw);
    name = keyframes(`ko${fw}_${frames}${reverse ? 'r' : ''}`, reverse ? last : 0, reverse ? 0 : last);
    steps = Math.max(1, frames - 1);
  }
  el.style.backgroundImage = `url(${url})`;
  el.style.backgroundSize = `${w}px ${fh}px`;
  el.style.animation = 'none';
  void el.offsetWidth;
  el.style.animation = `${name} ${dur.toFixed(3)}s steps(${steps}) ${loop ? 'infinite' : '1 forwards'}`;

  return wait(dur * 1000);
}

/**
 * Plays one of a fighter's own animations on `el`.
 *
 * @param {HTMLElement} el Target element.
 * @param {string} key Fighter key.
 * @param {string} anim Animation name (idle, walk, attack1, death, …).
 * @param {{loop?: boolean, reverse?: boolean}} [opts]
 * @return {Promise<void>}
 */
export function playAnim(el, key, anim, opts = {}) {
  const a = BY_KEY[key].anims[anim];

  return strip(el, stripUrl(a.file), a.frames, a.rate, opts);
}

/**
 * Loops an animation, or with hoverOnly parks it on frame 0 and leaves the
 * loop in `--anim` for a :hover rule to start.
 *
 * @param {HTMLElement} el Target element.
 * @param {string} key Fighter key.
 * @param {string} anim Animation name.
 * @param {{hoverOnly?: boolean, reverse?: boolean}} [opts]
 * @return {void}
 */
export function paint(el, key, anim, opts = {}) {
  const a = BY_KEY[key].anims[anim];
  const w = a.frames * 100;
  const name = keyframes(`kl100_${a.frames}${opts.reverse ? 'r' : ''}`, opts.reverse ? -(w - 100) : 0, opts.reverse ? 100 : -w);
  const loop = `${name} ${(a.frames / a.rate).toFixed(2)}s steps(${a.frames}) infinite`;
  el.style.backgroundImage = `url(${stripUrl(a.file)})`;
  el.style.backgroundSize = `${w}px 100px`;
  if (opts.hoverOnly) {
    el.style.setProperty('--anim', loop);
    el.style.animation = '';
    el.style.backgroundPosition = '0 0';
  } else {
    el.style.animation = loop;
  }
}

/**
 * Fits a sprite element into a box: scale so the body fills maxW × maxH,
 * body centre on (cxPct, cyPct) of the box.
 *
 * @param {HTMLElement} el Sprite element.
 * @param {string} key Fighter key.
 * @param {{maxW: number, maxH: number, cxPct?: number, cyPct?: number, cap?: number}} box
 * @return {void}
 */
export function frameIn(el, key, { maxW, maxH, cxPct = 50, cyPct = 50, cap = 3 }) {
  const [x0, y0, x1, y1] = BOX[key];
  const sc = Math.min(cap, maxW / (x1 - x0), maxH / (y1 - y0));
  el.style.scale = sc;
  el.style.left = `calc(${cxPct}% - ${(((x0 + x1) / 2) * sc).toFixed(1)}px)`;
  el.style.top = `calc(${cyPct}% - ${(((y0 + y1) / 2) * sc).toFixed(1)}px)`;
}

/**
 * Stands a sprite on a floor line: whole-number scale for crisp pixels,
 * body centred horizontally, feet `floor` px above the bottom edge.
 *
 * @param {HTMLElement} el Sprite element.
 * @param {string} key Fighter key.
 * @param {number} sc Scale.
 * @param {number} floor Feet height above the container bottom, in px.
 * @return {void}
 */
export function standOn(el, key, sc, floor) {
  const [x0, , x1, y1] = BOX[key];
  el.style.scale = sc;
  el.style.translate = `calc(-50% + ${((50 - (x0 + x1) / 2) * sc).toFixed(1)}px) 0`;
  el.style.bottom = `${floor - (100 - y1) * sc}px`;
}

/**
 * A fighter's hotbar: idle, walk, its attacks (named by attack type), then
 * death and summon (death played backwards) — only the ones it has strips for.
 *
 * @param {string} key Fighter key.
 * @return {Array<{id: string, label: string, anim?: string, reverse?: boolean}>}
 */
export function moves(key) {
  const c = BY_KEY[key];
  const list = [{ id: 'idle', label: 'Idle' }, { id: 'walk', label: 'Walk' }];
  Object.keys(c.anims).filter(a => a.startsWith('attack')).forEach((a, i) => list.push({ id: a, label: (LABELS[c.type] || [])[i] || `Attack ${i + 1}` }));
  list.push({ id: 'death', label: 'Death' }, { id: 'summon', label: 'Summon', anim: 'death', reverse: true });

  return list.filter(m => c.anims[m.anim || m.id]);
}

/**
 * Avatar-face colours, keyed by user id so a teammate keeps one colour
 * across the account cards and the roster.
 *
 * @type {string[]}
 */
export const FACE_COLORS = ['#fb923c', '#f472b6', '#60a5fa', '#facc15', '#2dd4bf', '#34d399', '#a78bfa', '#f87171'];

/**
 * A user's avatar-face colour.
 *
 * @param {number} userId User id.
 * @return {string}
 */
export function faceColor(userId) {
  return FACE_COLORS[Number(userId) % FACE_COLORS.length];
}

/**
 * A fighter's in-game charge palette as CSS hex strings, darkest first.
 *
 * @param {string} key Fighter key.
 * @return {string[]}
 */
export function chargePalette(key) {
  const type = FIGHTER_TYPES.find(t => t.key === key);
  const colors = type?.chargeColors ?? [0x886600, 0xaa8800, 0xccaa00, 0xeecc00, 0xffee44];

  return colors.map(c => `#${c.toString(16).padStart(6, '0')}`);
}

/**
 * Paints a charge palette onto an element as --c0..--c4.
 *
 * @param {HTMLElement} el Target element.
 * @param {string} key Fighter key.
 * @return {void}
 */
export function paintCharge(el, key) {
  chargePalette(key).forEach((c, i) => el.style.setProperty(`--c${i}`, c));
}
