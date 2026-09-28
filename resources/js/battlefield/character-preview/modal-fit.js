/**
 * Viewport width (CSS px) below which the loadout modal stacks the roster
 * above the preview instead of side by side. Below this the split layout
 * would have to shrink so far to fit that its text stops being readable.
 *
 * @type {number}
 */
export const STACK_BELOW_PX = 720;

/**
 * Returns which loadout layout a viewport gets. Browser zoom and a docked
 * DevTools panel both change the CSS viewport width, so they land here the
 * same way a smaller screen does.
 *
 * @param {number} viewportWidth CSS px
 * @return {'stacked'|'split'}
 */
export function loadoutLayout(viewportWidth) {
  return viewportWidth < STACK_BELOW_PX ? 'stacked' : 'split';
}

/**
 * Returns the uniform scale that fits the split loadout card (authored at a
 * fixed size) inside the viewport with `margin` clear on every side. Scaling
 * the whole card instead of letting fixed-px pieces overflow is what keeps
 * it looking identical at any zoom level, browser or window size, and it
 * never scales above the authored size so the pixel-art canvases stay crisp.
 *
 * @param {{viewportWidth: number, viewportHeight: number, contentWidth: number,
 *     contentHeight: number, margin?: number, maxScale?: number}} size
 * @return {number}
 */
export function fitScale({ viewportWidth, viewportHeight, contentWidth, contentHeight, margin = 16, maxScale = 1 }) {
  if (!contentWidth || !contentHeight) {
    return 1;
  }
  const byWidth = (viewportWidth - 2 * margin) / contentWidth;
  const byHeight = (viewportHeight - 2 * margin) / contentHeight;
  return Math.max(0.1, Math.min(maxScale, byWidth, byHeight));
}
