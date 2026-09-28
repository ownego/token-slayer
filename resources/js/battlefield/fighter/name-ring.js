// A fighter's name under its feet and, for the viewer's own fighter, the YOU
// ring it stands on. Pure: the Phaser drawing lives in fighter/index.js.

/**
 * The name's font size, fixed regardless of the fighter's display size.
 *
 * @type {number}
 */
export const NAME_FONT_PX = 12;

/**
 * The name: plain pixel text with a dark outline, no frame behind it.
 *
 * @type {object}
 */
export const NAME_STYLE = {
  fontFamily: 'Pixelify Sans',
  fontSize: `${NAME_FONT_PX}px`,
  color: '#e8eefc',
  stroke: '#0b0716',
  strokeThickness: 3,
};

/**
 * The viewer's own name, in the approved mockup's gold.
 *
 * @type {string}
 */
export const YOU_NAME_COLOR = '#fde68a';

/**
 * The name's depth: above the fighter containers (depth 2), so the YOU ring
 * under the feet never draws over it.
 *
 * @type {number}
 */
export const NAME_DEPTH = 3;

/**
 * How long one spark takes to go round the ring, in ms.
 *
 * @type {number}
 */
const SPARK_LAP_MS = 2400;

/**
 * The YOU ring's orbiting sparks at time `t`: `count` points evenly spaced
 * round an ellipse `w` x `h` centred on (0, 0), the ones on the far side
 * (behind the feet) dimmer than the near side.
 *
 * @param {number} t Time in ms.
 * @param {number} count How many sparks.
 * @param {number} w Ellipse width.
 * @param {number} h Ellipse height.
 * @return {Array<{x: number, y: number, alpha: number}>}
 */
export function youRingSparks(t, count, w, h) {
  const base = (t / SPARK_LAP_MS) * Math.PI * 2;

  return Array.from({ length: count }, (_, i) => {
    const a = base + (i / count) * Math.PI * 2;
    const y = Math.sin(a) * (h / 2);

    return { x: Math.cos(a) * (w / 2), y, alpha: 0.55 + 0.45 * Math.sin(a) };
  });
}
