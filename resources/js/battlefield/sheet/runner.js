// The game-style loader: the viewer's own fighter running along a pixel track
// (resources/views/partials/runner.blade.php), kicking sparks off its heel in
// its own charge colours, shown while the arena loads and while the fighter
// sheet's first render is on its way.
//
// Everything that moves runs on transform/opacity, so the compositor keeps it
// smooth while Phaser blocks the main thread loading the arena (measured on
// staging: 350-530ms long tasks while the loader shows). A background-position
// walk, a width fill or a rAF canvas all froze for those stretches.
import { BOX, ROSTER, stripUrl } from './sheet-roster.js';
import { BY_KEY, frameIn, paintCharge } from './sprite-strip.js';

/**
 * The runner's body box, in px: the sprite's body is fitted into it.
 *
 * @type {number}
 */
const BODY_PX = 40;

/**
 * How far down the body box the body's centre sits, as a fraction: a little
 * below the middle, so the feet land on the track under the box.
 *
 * @type {number}
 */
const BODY_CY = 0.6;

/**
 * How many spark pixels one heel throws, each on its own path and delay.
 *
 * @type {number}
 */
const SPARKS = 6;

/**
 * The fighter a guest, or an unknown key, runs as.
 *
 * @param {?string} key
 * @return {string}
 */
function runnerKey(key) {
  return BY_KEY[key] ? key : ROSTER[0].key;
}

/**
 * The scale the body is drawn at to fit the box (frameIn's own formula).
 *
 * @param {string} key
 * @return {number}
 */
function bodyScale(key) {
  const [x0, y0, x1, y1] = BOX[key];

  return Math.min(3, BODY_PX / (x1 - x0), BODY_PX / (y1 - y0));
}

/**
 * Makes sure a transform-stepped walk keyframe for `frames` frames exists
 * and returns its name.
 *
 * @param {number} frames
 * @return {string}
 */
function walkKeyframes(frames) {
  const name = `ts-walk-${frames}`;
  let sheet = document.getElementById('ts-runner-keyframes');
  if (!sheet) {
    sheet = document.createElement('style');
    sheet.id = 'ts-runner-keyframes';
    document.head.appendChild(sheet);
  }
  if (!sheet.textContent.includes(`@keyframes ${name} `)) {
    sheet.textContent += `@keyframes ${name} { to { transform: translateX(-${frames * 100}px); } }\n`;
  }

  return name;
}

/**
 * Where the runner's back heel is inside a `w` x `h` body box: the measured
 * heel when known, else just behind the body's middle at its feet.
 *
 * @param {string} key The fighter's character key.
 * @param {number} w Box width.
 * @param {number} h Box height.
 * @param {?{x: number, y: number}} heel The heel in frame px, or null.
 * @return {{x: number, y: number}}
 */
export function runnerHeel(key, w, h, heel) {
  const [x0, y0, x1, y1] = BOX[key];
  const sc = bodyScale(key);
  const hl = heel || { x: (x0 + x1) / 2 - 3, y: y1 };

  return {
    x: w * 0.5 + (hl.x - (x0 + x1) / 2) * sc,
    y: h * BODY_CY + (hl.y - (y0 + y1) / 2) * sc,
  };
}

/**
 * Draws a fighter running into the runner's body box: its walk strip stepped
 * by transform inside a one-frame clip, fitted to the box, in its own charge
 * colours, with a burst of spark pixels at its back heel. A guest or an
 * unknown key runs as the roster's first fighter. Idempotent.
 *
 * @param {HTMLElement} el The `.ts-runner-body` box.
 * @param {?string} key The fighter's character key.
 * @return {void}
 */
export function paintRunner(el, key) {
  const fighter = runnerKey(key);
  const walk = BY_KEY[fighter].anims.walk;
  el.replaceChildren();

  const clip = document.createElement('span');
  clip.className = 'ts-runner-clip';
  const strip = document.createElement('i');
  strip.className = 'ts-runner-strip';
  strip.style.width = `${walk.frames * 100}px`;
  strip.style.backgroundImage = `url(${stripUrl(walk.file)})`;
  strip.style.animation = `${walkKeyframes(walk.frames)} ${(walk.frames / walk.rate).toFixed(2)}s steps(${walk.frames}) infinite`;
  clip.appendChild(strip);
  frameIn(clip, fighter, { maxW: BODY_PX, maxH: BODY_PX, cyPct: BODY_CY * 100 });
  el.appendChild(clip);

  const heel = runnerHeel(fighter, BODY_PX, BODY_PX, null);
  const sparks = document.createElement('span');
  sparks.className = 'ts-runner-sparks';
  sparks.style.left = `${heel.x.toFixed(1)}px`;
  sparks.style.top = `${heel.y.toFixed(1)}px`;
  for (let i = 0; i < SPARKS; i++) {
    sparks.appendChild(document.createElement('b'));
  }
  el.appendChild(sparks);

  paintCharge(el, fighter);
}

/**
 * Moves a runner to `progress` (0..1) of its bar: sets the `--p` variable the
 * cover and the body translate by, so the compositor slides them there.
 *
 * @param {?HTMLElement} el Any element inside the runner (the arena passes its fill).
 * @param {number} progress
 * @return {void}
 */
export function setRunnerProgress(el, progress) {
  el?.closest('.ts-runner')?.style.setProperty('--p', String(Math.max(0, Math.min(1, progress))));
}

/**
 * Alpine.data() component on a runner's body box: paints the fighter named
 * by its `data-char` as soon as the box is on the page.
 *
 * @return {object}
 */
export function runnerSprite() {
  return {
    /** @return {void} */
    init() {
      paintRunner(this.$el, this.$el.dataset.char);
    },
  };
}
