// Clawd's cameos in the sky: now and then the mascot peeks shyly over the
// front mountain range or hops along behind it, and all the while dances on
// one of the sky's own drifting clouds.
// Pure plans (keyframes) and their interpolation; environment/index.js draws
// them. A ridge cameo is drawn between the two mountain ranges, so the front
// range itself hides whatever of Clawd is below its skyline.

/**
 * A plan's keyframe. `y` is Clawd's feet (the sprite's bottom edge); `pose`
 * holds from this key until the next one; `lift` arcs the segment arriving
 * at this key that many px above the straight line; `alpha` defaults to 1.
 *
 * @typedef {{t: number, x: number, y: number, pose: string, lift?: number, alpha?: number}} CameoKey
 */

/**
 * Clawd's width in multiples of its height (the pose art is 18 × 6 cells).
 *
 * @type {number}
 */
const ASPECT = 3;

/**
 * How much of Clawd's height stays below the skyline while it peeks: just
 * the eyes and the top of its head show.
 *
 * @type {number}
 */
const PEEK_SUNK = 0.6;

/**
 * How much of Clawd's height stays below the skyline between hops.
 *
 * @type {number}
 */
const REST_SUNK = 0.55;

/**
 * Delay before Clawd next shows behind the mountains, in ms: the first
 * within 8s of loading, then every 10-25s — a regular, not a rare sighting.
 *
 * @param {boolean} first
 * @param {function(): number} rnd 0..1 generator.
 * @return {number}
 */
export function nextCameoDelay(first, rnd) {
  return first ? 3000 + rnd() * 5000 : 10000 + rnd() * 15000;
}

/**
 * Which Clawd shows next behind the mountains: two of them take turns — the
 * shy one that only peeks over a crest, and the one that hops along — the
 * shy one first.
 *
 * @param {?string} previous The last cameo played, or null.
 * @return {string} 'peek' or 'hop'
 */
export function nextCameoKind(previous) {
  return previous === 'peek' ? 'hop' : 'peek';
}

/**
 * The frame of a plan at `ms`: position eased between the two keys around
 * it (arced by the arriving key's `lift`), the pose and alpha of the last
 * key reached.
 *
 * @param {{duration: number, keys: CameoKey[]}} plan
 * @param {number} ms
 * @return {{x: number, y: number, pose: string, alpha: number}}
 */
export function cameoFrame(plan, ms) {
  const { keys } = plan;
  const last = keys[keys.length - 1];
  if (ms >= last.t) {
    return { x: last.x, y: last.y, pose: last.pose, alpha: last.alpha ?? 1 };
  }
  let i = 0;
  while (i < keys.length - 2 && keys[i + 1].t <= ms) {
    i++;
  }
  const a = keys[i];
  const b = keys[i + 1];
  const s = Math.max(0, Math.min(1, (ms - a.t) / (b.t - a.t || 1)));
  const alphaA = a.alpha ?? 1;
  const alphaB = b.alpha ?? 1;

  return {
    x: a.x + (b.x - a.x) * s,
    y: a.y + (b.y - a.y) * s - 4 * (b.lift ?? 0) * s * (1 - s),
    pose: a.pose,
    alpha: alphaA + (alphaB - alphaA) * s,
  };
}

/**
 * The skyline's extremes under Clawd's body at `x`.
 *
 * @param {function(number): number} skyline y of the ridge at a screen x.
 * @param {number} x0
 * @param {number} x1
 * @return {{top: number, bottom: number}} top = highest ridge point (smallest y), bottom = lowest.
 */
function ridgeSpan(skyline, x0, x1) {
  let top = Infinity;
  let bottom = -Infinity;
  for (let x = Math.round(Math.min(x0, x1)); x <= Math.round(Math.max(x0, x1)); x++) {
    const y = skyline(x);
    top = Math.min(top, y);
    bottom = Math.max(bottom, y);
  }

  return { top, bottom };
}

/**
 * Whether Clawd's body at `x` is on screen and clear of every avoided span
 * (the castle standing on the ridge).
 *
 * @param {number} x
 * @param {{width: number, height: number, avoid: Array<{x0: number, x1: number}>}} box
 * @return {boolean}
 */
function freeAt(x, box) {
  const half = (box.height * ASPECT) / 2;
  if (x - half < 0 || x + half > box.width) {
    return false;
  }

  return box.avoid.every(a => x + half + 20 < a.x0 || x - half - 20 > a.x1);
}

/**
 * A random free x, nudged onto the highest ridge point near it so a peek
 * comes over a crest rather than out of a valley wall.
 *
 * @param {{width: number, height: number, skyline: function(number): number, avoid: Array<{x0: number, x1: number}>}} box
 * @param {function(): number} rnd
 * @return {number}
 */
function freeCrest(box, rnd) {
  const w = box.height * ASPECT;
  for (let tries = 0; tries < 40; tries++) {
    let x = Math.round(w + rnd() * (box.width - 2 * w));
    for (let step = 0; step < 30; step++) {
      const left = box.skyline(x - 3) < box.skyline(x) ? x - 3 : null;
      const right = box.skyline(x + 3) < box.skyline(x) ? x + 3 : null;
      const next = left ?? right;
      if (next === null || !freeAt(next, box)) {
        break;
      }
      x = next;
    }
    if (freeAt(x, box)) {
      return x;
    }
  }

  return Math.round(box.width * 0.25);
}

/**
 * Where Clawd's feet sit to be fully hidden behind the ridge anywhere in
 * [x0, x1].
 *
 * @param {object} box
 * @param {number} x0
 * @param {number} x1
 * @return {number}
 */
function hiddenY(box, x0, x1) {
  const half = (box.height * ASPECT) / 2;

  return ridgeSpan(box.skyline, x0 - half, x1 + half).bottom + box.height + 1;
}

/**
 * A shy peek: Clawd rises until only its head clears a crest, looks one way
 * and the other, ducks, and sometimes tries again a little further along.
 *
 * @param {{width: number, height: number, skyline: function(number): number, avoid: Array<{x0: number, x1: number}>}} box
 *   skyline = the front range's ridge y per screen x; height = Clawd's drawn height.
 * @param {function(): number} rnd 0..1 generator.
 * @return {{duration: number, keys: CameoKey[]}}
 */
export function peekPlan(box, rnd) {
  const h = box.height;
  const x1 = freeCrest(box, rnd);
  const peekAt = x => box.skyline(x) + h * PEEK_SUNK;
  const firstLook = rnd() < 0.5 ? 'look-left' : 'look-right';
  const secondLook = firstLook === 'look-left' ? 'look-right' : 'look-left';
  const keys = [
    { t: 0, x: x1, y: hiddenY(box, x1, x1), pose: 'default' },
    { t: 900, x: x1, y: peekAt(x1), pose: firstLook },
    { t: 1700, x: x1, y: peekAt(x1), pose: secondLook },
    { t: 2500, x: x1, y: peekAt(x1), pose: 'default' },
    { t: 3000, x: x1, y: peekAt(x1), pose: 'default' },
    { t: 3250, x: x1, y: hiddenY(box, x1, x1), pose: 'default' },
  ];
  const x2 = x1 + (rnd() < 0.5 ? -1 : 1) * h * ASPECT * (2 + rnd() * 2);
  if (rnd() < 0.6 && freeAt(x2, box) && box.avoid.every(a => Math.max(x1, x2) < a.x0 || Math.min(x1, x2) > a.x1)) {
    const deep = hiddenY(box, x1, x2);
    keys.push(
      { t: 3400, x: x1, y: deep, pose: 'default' },
      { t: 4600, x: x2, y: deep, pose: 'default' },
      { t: 5600, x: x2, y: peekAt(x2), pose: secondLook },
      { t: 6600, x: x2, y: peekAt(x2), pose: 'default' },
      { t: 7000, x: x2, y: peekAt(x2), pose: 'default' },
      { t: 7250, x: x2, y: hiddenY(box, x2, x2), pose: 'default' },
    );
  }

  return { duration: keys[keys.length - 1].t, keys };
}

/**
 * Hops along behind the ridge: Clawd pops up, looks where it's heading, then
 * hops three to five times, only its head showing between hops and its whole
 * body clearing the crest at each apex, and ducks away at the end.
 *
 * @param {{width: number, height: number, skyline: function(number): number, avoid: Array<{x0: number, x1: number}>}} box
 * @param {function(): number} rnd 0..1 generator.
 * @return {{duration: number, keys: CameoKey[]}}
 */
export function hopPlan(box, rnd) {
  const h = box.height;
  const rest = x => box.skyline(x) + h * REST_SUNK;
  let xs = [];
  for (let tries = 0; tries < 60 && xs.length < 4; tries++) {
    const start = freeCrest(box, rnd);
    const dir = start < box.width / 2 ? 1 : -1;
    const hops = 3 + Math.floor(rnd() * 3);
    xs = [start];
    for (let k = 0; k < hops; k++) {
      const next = Math.round(xs[xs.length - 1] + dir * h * ASPECT * (1.2 + rnd() * 0.6));
      if (!freeAt(next, box) || box.avoid.some(a => Math.max(next, xs[xs.length - 1]) >= a.x0 && Math.min(next, xs[xs.length - 1]) <= a.x1)) {
        break;
      }
      xs.push(next);
    }
  }
  const dir = xs.length > 1 && xs[1] < xs[0] ? 'look-left' : 'look-right';
  const keys = [
    { t: 0, x: xs[0], y: hiddenY(box, xs[0], xs[0]), pose: 'default' },
    { t: 700, x: xs[0], y: rest(xs[0]), pose: dir },
  ];
  let t = 1400;
  for (let k = 1; k < xs.length; k++) {
    const from = xs[k - 1];
    const to = xs[k];
    // the apex clears the highest crest between the two spots by a little
    const straight = (rest(from) + rest(to)) / 2;
    const lift = straight - (ridgeSpan(box.skyline, from, to).top - h * 0.1);
    keys.push(
      { t, x: from, y: rest(from) + h * 0.12, pose: 'default' },
      { t: t + 120, x: from, y: rest(from), pose: 'arms-up' },
      { t: t + 620, x: to, y: rest(to), pose: 'default', lift },
    );
    t += 900;
  }
  const end = xs[xs.length - 1];
  keys.push(
    { t: t + 300, x: end, y: rest(end), pose: 'default' },
    { t: t + 600, x: end, y: hiddenY(box, end, end), pose: 'default' },
  );

  return { duration: t + 600, keys };
}

/**
 * Clawd's little dance on a cloud, as one seamless loop: it stands at the
 * first spot looking about, hops spot to spot to the last (a crouch, arms up
 * through the air, a small bounce on each landing) and back again, ending
 * where and how it started. Positions are relative to the cloud's top
 * centre: `offsets` are the spots' x on it.
 *
 * @param {number[]} offsets
 * @param {{seatY: number, riderH: number}} opts seatY = where Clawd's feet sit on a cloud.
 * @param {function(): number} rnd 0..1 generator.
 * @return {{duration: number, keys: CameoKey[]}}
 */
export function dancePlan(offsets, { seatY, riderH }, rnd) {
  const seat = (t, x, pose, extra = {}) => ({ t, x, y: seatY, pose, ...extra });
  const path = [...offsets.keys(), ...[...offsets.keys()].reverse().slice(1)].map(k => offsets[k]);
  const keys = [];
  let t = 0;
  path.forEach((x, k) => {
    if (k === 0) {
      keys.push(seat(t, x, 'default'));
    } else {
      const from = path[k - 1];
      keys.push(
        { ...seat(t, from, 'default'), y: seatY + 2 },
        seat(t + 120, from, 'arms-up'),
        seat(t + 700, x, 'default', { lift: riderH * 1.6 }),
        { ...seat(t + 820, x, 'default'), y: seatY + 2 },
        seat(t + 940, x, 'default'),
      );
      t += 940;
    }
    // a look about before the next hop
    const look = rnd() < 0.5 ? 'look-left' : 'look-right';
    keys.push(seat(t + 1200, x, look), seat(t + 2000, x, 'default'));
    t += 2000 + Math.round(rnd() * 1500);
    keys.push(seat(t, x, 'default'));
  });

  return { duration: t, keys };
}

/**
 * Which of the sky's own drifting clouds Clawd dances on: the lowest one
 * wide enough for it (2.5× its width) — the low band is the one the HUD
 * panels leave most in view — or the widest when none is wide enough.
 *
 * @param {Array<{y: number, w: number}>} clouds centre y and width, screen px.
 * @param {number} riderW Clawd's width.
 * @return {number} the cloud's index
 */
export function pickRideCloud(clouds, riderW) {
  const roomy = clouds.map((c, i) => ({ ...c, i })).filter(c => c.w >= riderW * 2.5);
  if (!roomy.length) {
    return clouds.reduce((best, c, i) => (c.w > clouds[best].w ? i : best), 0);
  }

  return roomy.reduce((best, c) => (c.y > best.y ? c : best)).i;
}

/**
 * The height Clawd's cloud drifts at: the sky's clouds float in the top band
 * the HUD panels cover (the boss plate and the side panels reach about a
 * third of the way down to the horizon), so the one Clawd dances on is
 * lowered to half the horizon, just under them — never raised.
 *
 * @param {number} y The cloud's own centre y.
 * @param {number} horizonY
 * @return {number}
 */
export function rideCloudY(y, horizonY) {
  return Math.max(y, Math.round(horizonY * 0.5));
}
