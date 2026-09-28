/** Milliseconds between animation-sequence ticks (Claude Code 2.1.283's `Dre` table). */
export const TICK_MS = 60;

/** Quadrant glyph rows per pose, ported verbatim from Claude Code 2.1.283's `Dre` table. */
export const POSES = {
  'default':    { r1L: ' ▐', r1E: '▛███▛█', r1R: '',       r2L: '▝▜', r2R: '█▀' },
  'look-left':  { r1L: ' ▐', r1E: '▟███▟█', r1R: '',       r2L: '▝▜', r2R: '█▀' },
  'look-right': { r1L: ' ▐', r1E: '█▟███▟', r1R: '',       r2L: '▝▜', r2R: '█▀' },
  'arms-up':    { r1L: '▗▟', r1E: '▛███▛█', r1R: '▄', r2L: ' ▜', r2R: '█▘' },
};

/** The two static legs rows shared by every pose. */
const LEGS = ' ▝▝   ▝▝ ';

/** Quadrant-block glyph → its four sub-pixel on/off flags (top-left, top-right, bottom-left, bottom-right). */
const Q = { ' ': [0,0,0,0], '▐': [0,1,0,1], '▌': [1,0,1,0], '█': [1,1,1,1], '▛': [1,1,1,0], '▜': [1,1,0,1],
  '▟': [0,1,1,1], '▙': [1,0,1,1], '▝': [0,1,0,0], '▘': [1,0,0,0], '▗': [0,0,0,1], '▖': [0,0,1,0], '▀': [1,1,0,0], '▄': [0,0,1,1] };

/**
 * Repeats one pose frame `n` times, at an optional crouch offset and x shift.
 * @param {string} pose
 * @param {number} offset
 * @param {number} n
 * @param {number} [x]
 * @return {{pose:string, offset:number, x:number}[]}
 */
const a = (pose, offset, n, x) => Array.from({ length: n }, () => ({ pose, offset, x }));

/**
 * The two-frame crouch-and-dust-poof that opens a jump.
 * @param {number} [x]
 * @return {{pose:string, offset:number, x:number, poof:string}[]}
 */
const z = x => [{ pose: 'default', offset: 1, x, poof: 'dot' }, { pose: 'default', offset: 1, x, poof: 'wave' }];

/** The jump sequence: crouch+dust, arms-up ×3, default, twice — reused standalone and inside `celebrate`. */
const JUMP = [...z(), ...a('arms-up', 0, 3), ...a('default', 0, 1), ...z(), ...a('arms-up', 0, 3), ...a('default', 0, 1)];

/** Named pose sequences, each an ordered list of `{pose, offset, x?, poof?}` frames at `TICK_MS` per tick. */
export const SEQUENCES = Object.freeze({
  jump: JUMP,
  look: [...a('look-right', 0, 5), ...a('look-left', 0, 5), ...a('default', 0, 1)],
  idle: [...a('default', 0, 12), ...a('look-right', 0, 5), ...a('look-left', 0, 5)],
  spin: [...a('look-left', 0, 2), ...a('look-right', 0, 2), ...a('look-left', 0, 2), ...a('arms-up', 0, 3), ...a('default', 0, 1)],
  skip: [...a('default', 1, 1, -9), ...a('arms-up', 0, 2, -6), ...a('default', 0, 1, -6), ...a('default', 1, 1, -6),
         ...a('arms-up', 0, 2, -3), ...a('default', 0, 1, -3), ...a('default', 1, 1, -3), ...a('arms-up', 0, 2, 0), ...z(0), ...a('default', 0, 1, 0)],
  celebrate: [...JUMP, ...a('default', 1, 3)],
});

/**
 * Quadrant pixels of one pose (18 wide × 6 tall; each glyph cell is 2×2).
 * @param {string} pose
 * @return {{x:number, y:number, on:boolean}[]} on=false marks eye cells (drawn dark)
 */
export function poseRects(pose) {
  const p = POSES[pose] ?? POSES.default;
  const out = [];
  const put = (segs, row) => {
    let col = 0;
    for (const [txt, bg] of segs) {
      for (const ch of txt) {
        (Q[ch] ?? Q[' ']).forEach((on, k) => {
          if (on || bg) { out.push({ x: col * 2 + (k % 2), y: row * 2 + (k > 1 ? 1 : 0), on: !!on }); }
        });
        col++;
      }
    }
  };
  put([[p.r1L, 0], [p.r1E, 1], [p.r1R, 0]], 0);
  put([[p.r2L, 0], ['█████', 1], [p.r2R, 0]], 1);
  put([[LEGS, 0]], 2);
  return out;
}

/**
 * Which of the 3×2 zones a point falls into within a rect.
 * @param {{x:number, y:number}} point
 * @param {{left:number, top:number, width:number, height:number}} rect
 * @return {{col:number, row:number}}
 */
export function zoneOf(point, rect) {
  const col = Math.min(2, Math.max(0, Math.floor((point.x - rect.left) / (rect.width / 3))));
  return { col, row: point.y < rect.top + rect.height / 2 ? 0 : 1 };
}

/**
 * What Clawd does for a pointer zone: eyes stay horizontal in both rows; one
 * column over hops, two columns over somersaults.
 * @param {number} spot 0..2 current column
 * @param {{col:number, row:number}} zone
 * @return {{look:string, move:string, to:number}}
 */
export function clawdStep(spot, zone) {
  const d = zone.col - spot;
  if (d === 0) { return { look: 'default', move: 'none', to: spot }; }
  return { look: d > 0 ? 'look-right' : 'look-left', move: Math.abs(d) === 2 ? 'flip' : 'hop', to: zone.col };
}
