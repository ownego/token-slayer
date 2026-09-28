/**
 * Degrees-to-radians conversion factor.
 * @type {number}
 */
const RAD = Math.PI / 180;

/**
 * Sky gradient stops keyed by sun elevation (degrees): [elevation, zenith, mid, horizon] hex colours.
 * @type {Array<[number, string, string, string]>}
 */
const SKY_STOPS = [
  [-18, '#02040c', '#060b1c', '#0b1226'], [-10, '#050a1e', '#10183a', '#1c2250'], [-5, '#10193f', '#3a2f66', '#8a4a6a'],
  [-1, '#1d2b63', '#6a4a7a', '#f08a5d'], [2, '#2f4f8f', '#b87a86', '#ffb36b'], [8, '#3f74c4', '#8fb4dd', '#ffd9a0'],
  [20, '#3a7bd5', '#79b3ec', '#bfe0f7'], [60, '#2f6fd0', '#6aa8e8', '#a9d4f5']];

/**
 * Ambient light gradient stops keyed by sun elevation (degrees): [elevation, colour] hex.
 * @type {Array<[number, string]>}
 */
const AMBIENT_STOPS = [[-18, '#2a3252'], [-6, '#4a4668'], [-1, '#9a6a7a'], [4, '#c8a28c'], [15, '#b9c4d4'], [60, '#d6dde8']];

/**
 * Synodic (new-moon-to-new-moon) month length in days.
 * @type {number}
 */
const SYNODIC = 29.530588853;

/**
 * A known new-moon reference instant (2000-01-06 18:14 UTC), epoch ms.
 * @type {number}
 */
const NEW_MOON_REF = Date.UTC(2000, 0, 6, 18, 14);

/**
 * Sun position for a given instant and observer location, plus its place in
 * today's day arc (`ha`/`ha0`), so the caller can plot it without azimuth's
 * 0/360 wrap-around.
 *
 * @param {Date} date
 * @param {number} lat degrees
 * @param {number} lon degrees
 * @return {{el:number, az:number, ha:number, ha0:number}} el/az in degrees; ha/ha0 in radians
 */
export function sunPosition(date, lat, lon) {
  const n = (date.getTime() - Date.UTC(2000, 0, 1, 12)) / 86400000;
  const L0 = (280.46 + 0.9856474 * n) % 360;
  const g = ((357.528 + 0.9856003 * n) % 360) * RAD;
  const lam = (L0 + 1.915 * Math.sin(g) + 0.02 * Math.sin(2 * g)) * RAD;
  const eps = (23.439 - 0.0000004 * n) * RAD;
  const ra = Math.atan2(Math.cos(eps) * Math.sin(lam), Math.cos(lam));
  const dec = Math.asin(Math.sin(eps) * Math.sin(lam));
  const gmst = ((18.697374558 + 24.06570982441908 * n) % 24 + 24) % 24;
  const H = (gmst * 15 + lon) * RAD - ra;
  const la = lat * RAD;
  const el = Math.asin(Math.sin(la) * Math.sin(dec) + Math.cos(la) * Math.cos(dec) * Math.cos(H));
  const az = Math.atan2(-Math.sin(H), Math.tan(dec) * Math.cos(la) - Math.sin(la) * Math.cos(H));
  const ha = Math.atan2(Math.sin(H), Math.cos(H));                                   // −π..π, 0 at transit
  const ha0 = Math.acos(Math.min(1, Math.max(-1, -Math.tan(la) * Math.tan(dec))));   // rise→transit arc
  return { el: el / RAD, az: ((az / RAD) + 360) % 360, ha, ha0 };
}

/**
 * Moon phase fraction for a given instant.
 * @param {Date} date
 * @return {number} 0 new … 0.5 full … 1
 */
export function moonPhase(date) {
  const age = ((date.getTime() - NEW_MOON_REF) / 86400000) % SYNODIC;
  return ((age + SYNODIC) % SYNODIC) / SYNODIC;
}

/**
 * Moon position, approximated as the sun's position offset by how far
 * (in phase) the moon trails the sun — at full, where the sun was 12h earlier.
 *
 * @param {Date} date
 * @param {number} lat degrees
 * @param {number} lon degrees
 * @return {{el:number, az:number, ha:number, ha0:number}}
 */
export function moonPosition(date, lat, lon) {
  return sunPosition(new Date(date.getTime() - moonPhase(date) * 86400000), lat, lon);
}

/**
 * Splits a `#rrggbb` hex colour into its three channel values.
 * @param {string} h
 * @return {number[]} [r, g, b]
 */
const hexRgb = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));

/**
 * Interpolates two hex colours per channel.
 * @param {string} a
 * @param {string} b
 * @param {number} k 0..1
 * @return {string} hex colour
 */
export function mixHex(a, b, k) {
  const A = hexRgb(a), B = hexRgb(b);
  return '#' + A.map((v, i) => Math.round(v + (B[i] - v) * k).toString(16).padStart(2, '0')).join('');
}

/**
 * Interpolates a value across a `[elevation, ...colours]` stop table.
 * @param {Array<[number, ...string[]]>} stops
 * @param {number} e sun elevation, degrees
 * @return {string[]} interpolated colours at `e`
 */
function stopAt(stops, e) {
  if (e <= stops[0][0]) { return stops[0].slice(1); }
  for (let i = 1; i < stops.length; i++) {
    if (e <= stops[i][0]) {
      const k = (e - stops[i - 1][0]) / (stops[i][0] - stops[i - 1][0]);
      return stops[i].slice(1).map((c, j) => mixHex(stops[i - 1][j + 1], c, k));
    }
  }
  return stops[stops.length - 1].slice(1);
}

/**
 * Sky gradient colours for a given sun elevation.
 * @param {number} el sun elevation, degrees
 * @return {string[]} [zenith, mid, horizon] hex
 */
export function skyColorsAt(el) { return stopAt(SKY_STOPS, el); }

/**
 * Ambient light colour for a given sun elevation.
 * @param {number} el sun elevation, degrees
 * @return {string} hex colour
 */
export function ambientAt(el) { return stopAt(AMBIENT_STOPS, el)[0]; }

/**
 * Screen position of a sky body from its place in its own day arc (rise → left,
 * transit → middle, set → right) and its elevation (horizon → horizonY). Uses
 * the hour-angle arc rather than azimuth, which wraps through 0/360 near the
 * solstices at latitudes like Hanoi (21°N) and would throw the noon sun off-screen.
 *
 * @param {{ha:number, ha0:number, el:number}} p
 * @param {number} worldWidth
 * @param {number} horizonY
 * @return {{x:number, y:number}}
 */
export function skyXY(p, worldWidth, horizonY) {
  const t = Math.min(1.1, Math.max(-0.1, 0.5 + p.ha / (2 * (p.ha0 || Math.PI / 2))));
  return { x: Math.round(worldWidth * t), y: Math.round(horizonY - (p.el / 90) * (horizonY - 16)) };
}
