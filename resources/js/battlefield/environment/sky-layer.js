import { sunPosition, moonPosition, moonPhase, skyColorsAt, ambientAt, skyXY, mixHex } from '@battlefield/shared/sky.js';

/**
 * Clamps a value to [0, 1].
 *
 * @param {number} v
 * @return {number}
 */
function clamp01(v) {
  return Math.min(1, Math.max(0, v));
}

/**
 * Computes one frame of the living sky's pure state: gradient colours,
 * ambient light, and the sun/moon's on-screen position and visibility — for
 * a given instant, observer site, and world box. Phaser-free so it can be
 * driven straight off `Date.now()`, a staged clock, or a fixed test instant.
 *
 * @param {Date} date
 * @param {{lat: number, lon: number}} site
 * @param {{width: number, horizonY: number}} box
 * @return {{
 *   colors: string[],
 *   ambient: string,
 *   sun: {x: number, y: number, visible: boolean, warm: number},
 *   moon: {x: number, y: number, visible: boolean, phase: number},
 *   night: number,
 *   twilight: number,
 *   day: number,
 *   elevation: number,
 * }}
 */
export function skyFrame(date, site, box) {
  const { width, horizonY } = box;
  const sun = sunPosition(date, site.lat, site.lon);
  const moon = moonPosition(date, site.lat, site.lon);
  const el = sun.el;

  const night = clamp01((-el - 4) / 10);
  const twilight = clamp01(1 - Math.abs(el + 2) / 8);
  const day = clamp01((el + 2) / 12);
  const warm = clamp01(1 - el / 20);

  const sunXY = skyXY(sun, width, horizonY);
  const moonXY = skyXY(moon, width, horizonY);

  return {
    colors: skyColorsAt(el),
    ambient: ambientAt(el),
    sun: { x: sunXY.x, y: sunXY.y, visible: el > -4, warm },
    moon: { x: moonXY.x, y: moonXY.y, visible: moon.el > -2, phase: moonPhase(date) },
    night,
    twilight,
    day,
    elevation: el,
  };
}

/**
 * Where and how brightly to draw the moon. A risen moon is drawn where it
 * really is; once the sun is down, a moon that hasn't risen yet still peeks
 * over the back mountains — a third of the way across, clear of the Team
 * Damage panel's corner, its lower part tucked behind the ridge — so dusk
 * always has one; it then climbs its real path once it rises. A risen moon
 * is never drawn lower than that peek (just after rising its plotted point
 * is still behind the mountains), nor behind a HUD panel: it slides
 * sideways just clear of one. By day a moon below the horizon stays hidden.
 *
 * @param {{sun: {visible: boolean}, moon: {x: number, y: number, visible: boolean}, day: number}} frame skyFrame's result.
 * @param {{width: number, horizonY: number}} box
 * @param {function(number): number} ridgeY The back range's skyline y at a given x.
 * @param {number} radius The moon's drawn radius.
 * @param {Array<{l: number, r: number, t: number, b: number}>} zones HUD panels, in world space.
 * @return {{x: number, y: number, alpha: number}}
 */
export function moonDisplay(frame, box, ridgeY, radius, zones = []) {
  const dark = 1 - frame.day;
  if (frame.moon.visible) {
    let x = Math.round(frame.moon.x);
    for (const z of zones) {
      const y = Math.min(frame.moon.y, ridgeY(x) - radius * 0.35);
      if (x + radius > z.l && x - radius < z.r && y + radius > z.t && y - radius < z.b) {
        const left = Math.round(z.l - radius);
        const right = Math.round(z.r + radius);
        x = (left >= radius && x - left < right - x) || right > box.width - radius ? left : right;
      }
    }

    return { x, y: Math.min(frame.moon.y, ridgeY(x) - radius * 0.35), alpha: 0.35 + 0.65 * dark };
  }
  if (frame.sun.visible && frame.day > 0.5) {
    return { x: frame.moon.x, y: frame.moon.y, alpha: 0 };
  }
  const x = Math.round(box.width * 0.34);

  return { x, y: Math.round(ridgeY(x) - radius * 0.35), alpha: 0.55 + 0.45 * dark };
}

export { mixHex };
