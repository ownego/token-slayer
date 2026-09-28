// Clawd as Claude Code draws it — pose table + sequence table + 60ms tick —
// ported verbatim from the approved mockup's own ClawdKit. Draws into an
// <svg> as 1×1 quadrant cells classed `b` (body) / `e` (eyes), which the
// sheet's CSS colours.

/**
 * Per-pose glyph rows: row 1 left/eyes/right, row 2 left/right.
 *
 * @type {Object<string, {r1L: string, r1E: string, r1R: string, r2L: string, r2R: string}>}
 */
const POSE = {
  'default': { r1L: ' ▐', r1E: '▛███▛█', r1R: '', r2L: '▝▜', r2R: '█▀' },
  'look-left': { r1L: ' ▐', r1E: '▟███▟█', r1R: '', r2L: '▝▜', r2R: '█▀' },
  'look-right': { r1L: ' ▐', r1E: '█▟███▟', r1R: '', r2L: '▝▜', r2R: '█▀' },
  'arms-up': { r1L: '▗▟', r1E: '▛███▛█', r1R: '▄', r2L: ' ▜', r2R: '█▘' },
};

/**
 * The legs row, shared by every pose.
 *
 * @type {string}
 */
const LEGS = ' ▝▝   ▝▝ ';

/**
 * Block glyph → lit quadrants [UL, UR, LL, LR].
 *
 * @type {Object<string, number[]>}
 */
const Q = {
  ' ': [0, 0, 0, 0], '▐': [0, 1, 0, 1], '▌': [1, 0, 1, 0], '█': [1, 1, 1, 1], '▛': [1, 1, 1, 0], '▜': [1, 1, 0, 1],
  '▟': [0, 1, 1, 1], '▙': [1, 0, 1, 1], '▝': [0, 1, 0, 0], '▘': [1, 0, 0, 0], '▗': [0, 0, 0, 1], '▖': [0, 0, 1, 0],
  '▀': [1, 1, 0, 0], '▄': [0, 0, 1, 1],
};

/**
 * Milliseconds per frame.
 *
 * @type {number}
 */
export const TICK = 60;

const a = (pose, offset, n, x) => Array.from({ length: n }, () => ({ pose, offset, x }));
const z = x => [{ pose: 'default', offset: 1, x, poof: 'dot' }, { pose: 'default', offset: 1, x, poof: 'wave' }];
const JUMP = [...z(), ...a('arms-up', 0, 3), ...a('default', 0, 1), ...z(), ...a('arms-up', 0, 3), ...a('default', 0, 1)];
const LOOK = [...a('look-right', 0, 5), ...a('look-left', 0, 5), ...a('default', 0, 1)];
const IDLE = [...a('default', 0, 12), ...a('look-right', 0, 5), ...a('look-left', 0, 5)];
const SPIN = [...a('look-left', 0, 2), ...a('look-right', 0, 2), ...a('look-left', 0, 2), ...a('arms-up', 0, 3), ...a('default', 0, 1)];
const SKIP = [...a('default', 1, 1, -9), ...a('arms-up', 0, 2, -6), ...a('default', 0, 1, -6), ...a('default', 1, 1, -6),
  ...a('arms-up', 0, 2, -3), ...a('default', 0, 1, -3), ...a('default', 1, 1, -3), ...a('arms-up', 0, 2, 0), ...z(0), ...a('default', 0, 1, 0)];

/**
 * Named frame sequences.
 *
 * @type {Object<string, Array<{pose: string, offset: number, x?: number, poof?: string}>>}
 */
export const SEQ = { JUMP, LOOK, IDLE, SPIN, SKIP, CELEBRATE: [...JUMP, ...a('default', 1, 3)] };

/**
 * Entrance sequences a crew Clawd picks from when the Profile tab shows.
 *
 * @type {Array<Array<object>>}
 */
export const ENTRANCES = [SKIP, JUMP, LOOK, SPIN];

/**
 * Reactions a click on a crew Clawd picks from.
 *
 * @type {Array<Array<object>>}
 */
export const ON_CLICK = [JUMP, LOOK];

/**
 * Draws one frame into `svg`, shifted by the frame's x plus `svg._base`
 * columns and down by its offset.
 *
 * @param {SVGSVGElement} svg Target svg.
 * @param {{pose: string, offset?: number, x?: number, poof?: string}} f Frame.
 * @return {void}
 */
export function draw(svg, f) {
  const p = POSE[f.pose];
  const dx = ((f.x || 0) + (svg._base || 0)) * 2;
  const dy = (f.offset || 0) * 2;
  const out = [];
  const put = (segs, row) => {
    let col = 0;
    for (const [txt, bg] of segs) {
      for (const ch of txt) {
        const q = Q[ch] || Q[' '];
        q.forEach((lit, k) => {
          if (!lit && !bg) {
            return;
          }
          const x = col * 2 + (k % 2) + dx;
          const y = row * 2 + (k > 1 ? 1 : 0) + dy;
          out.push(`<rect class="${lit ? 'b' : 'e'}" x="${x}" y="${y}" width="1" height="1"/>`);
        });
        col++;
      }
    }
  };
  put([[p.r1L, 0], [p.r1E, 1], [p.r1R, 0]], 0);
  put([[p.r2L, 0], ['█████', 1], [p.r2R, 0]], 1);
  put([[LEGS, 0]], 2);
  if (f.poof && f.offset > 0) {
    const g = f.poof === 'dot' ? '·' : '~';
    out.push(`<text class="poof" x="0.2" y="5.8">${g}</text>`);
    out.push(`<text class="poof" x="16" y="5.8">${g}</text>`);
  }
  svg.innerHTML = out.join('');
}

/**
 * Plays a sequence on a Clawd element (its svg on `el._svg`), then `then`.
 * Under reduced motion it just stands still.
 *
 * @param {HTMLElement} el Clawd host with `_svg` set.
 * @param {Array<object>} seq Frames.
 * @param {function(): void} [then] Called after the last frame.
 * @return {void}
 */
export function play(el, seq, then) {
  clearTimeout(el._t);
  const reduced = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced) {
    draw(el._svg, { pose: 'default', offset: 0 });

    return;
  }
  let i = 0;
  const step = () => {
    if (i >= seq.length) {
      if (then) {
        then();
      } else {
        draw(el._svg, { pose: 'default', offset: 0 });
      }

      return;
    }
    draw(el._svg, seq[i++]);
    el._t = setTimeout(step, TICK);
  };
  step();
}
