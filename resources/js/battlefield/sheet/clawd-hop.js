// The fighter sheet's first-open loader (fighter-sheet/skeleton.blade.php):
// Clawd bouncing on the start line, glancing about while the sheet loads,
// then leaping arms-up to the goal once it's ready (sheetFrame.finish()).
// The bounce and the leap are CSS transforms (fighter-sheet.css's fs-hop-*);
// this module only draws Clawd's pose.
import { draw } from './clawd-kit.js';

/**
 * Clawd's poses while it waits, one every GLANCE_MS: mostly facing front,
 * now and then a look either way.
 *
 * @type {string[]}
 */
const GLANCES = ['default', 'default', 'look-left', 'default', 'default', 'look-right'];

/**
 * How long each glance lasts, in ms.
 *
 * @type {number}
 */
const GLANCE_MS = 400;

/**
 * Draws Clawd in `pose` and records it on the svg.
 *
 * @param {SVGSVGElement} svg
 * @param {string} pose
 * @return {void}
 */
function drawPose(svg, pose) {
  draw(svg, { pose });
  svg.dataset.pose = pose;
}

/**
 * Alpine.data() component on the loader's svg: draws Clawd and cycles its
 * glances until the leap.
 *
 * @return {object}
 */
export function clawdHop() {
  return {
    /** @return {void} */
    init() {
      const svg = this.$el;
      let i = 0;
      drawPose(svg, GLANCES[0]);
      clearInterval(svg._glances);
      svg._glances = setInterval(() => {
        i = (i + 1) % GLANCES.length;
        drawPose(svg, GLANCES[i]);
      }, GLANCE_MS);
    },
    /** @return {void} */
    destroy() {
      clearInterval(this.$el._glances);
    },
  };
}

/**
 * Sends Clawd leaping to the goal: `.is-leaping` starts the CSS leap, and
 * Clawd throws its arms up and stops glancing.
 *
 * @param {HTMLElement} root The loader's `.ts-hop`.
 * @return {void}
 */
export function leap(root) {
  root.classList.add('is-leaping');
  const svg = root.querySelector('.ts-hop-clawd');
  if (svg) {
    clearInterval(svg._glances);
    drawPose(svg, 'arms-up');
  }
}
