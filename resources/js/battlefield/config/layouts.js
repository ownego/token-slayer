/**
 * The two authored worlds. layoutFor() derives the live layout from these:
 * the short side always stays 540 and every coordinate here is the
 * reference the derived layout shifts from.
 *
 * @type {{landscape: object, portrait: object}}
 */
export const LAYOUTS = {
  landscape: {
    logicalWidth: 960,
    logicalHeight: 540,
    boss: { anchor: { x: 480, y: 180 }, scale: 4, name: { x: 480, y: 100 } },
    hpBar: { x: 480, y: 300, width: 200, height: 12 },
    fighters: { rowXRange: [80, 880], rowY: 460, perRowMax: 14 },
    bats: { centerX: 480, centerY: 140, radiusX: 220, radiusY: 45, hopRadius: 70 },
    necromancer: { anchor: { x: 65, y: 190 }, wanderRadiusX: 30, wanderRadiusY: 20 },
  },
  portrait: {
    logicalWidth: 540,
    logicalHeight: 960,
    boss: { anchor: { x: 270, y: 310 }, scale: 5, name: { x: 270, y: 200 } },
    hpBar: { x: 270, y: 430, width: 280, height: 12 },
    fighters: { rowXRange: [50, 490], rowY: 820, perRowMax: 10 },
    bats: { centerX: 270, centerY: 255, radiusX: 140, radiusY: 40, hopRadius: 55 },
    necromancer: { anchor: { x: 55, y: 320 }, wanderRadiusX: 25, wanderRadiusY: 20 },
  },
};

/**
 * Range the long side of the world may stretch to. 960 is the authored 16:9
 * world; past 1240 (about 2.3:1) the sprites would read too small on the
 * screen, so a wider screen keeps a thin letterbox instead.
 *
 * @type {{min: number, max: number}}
 */
const LONG_SIDE = { min: 960, max: 1240 };

/**
 * Range the short side may stretch to on a screen squarer than 16:9 (a 4:3
 * tablet): the long side stays 960 and the short side grows instead of
 * leaving letterbox bars, up to 720 (exactly 4:3).
 *
 * @type {{min: number, max: number}}
 */
const SHORT_SIDE = { min: 540, max: 720 };

/**
 * Logical px kept between the bottom of the HUD band and the top of the boss
 * block.
 *
 * @type {number}
 */
const HUD_GAP = 12;

/**
 * Logical px from the boss anchor up to the top of its block (the sprite plus
 * the bats circling it), the same in both authored worlds: portrait's name
 * line sits 110 above its anchor.
 *
 * @type {number}
 */
const BOSS_BLOCK_ABOVE_ANCHOR = 110;

/**
 * Most logical px of the authored portrait fighter area a short phone (no
 * extra height) may give up to keep the boss clear of the HUD.
 *
 * @type {number}
 */
const PORTRAIT_MAX_SQUEEZE = 140;

/**
 * Most logical px a landscape boss block may drop below a boss plate that
 * reaches into it: the fighter rows start at 425.
 *
 * @type {number}
 */
const LANDSCAPE_MAX_DROP = 60;

/**
 * Logical px of horizontal space one fighter slot takes in a row — the
 * authored landscape row (800px) holds 14, the portrait one (440px) 8.
 *
 * @type {{landscape: number, portrait: number}}
 */
const SLOT_PX = { landscape: 57, portrait: 55 };

/**
 * Relative size change of a side below which a resize keeps the current
 * layout. A phone's URL bar showing or hiding moves the height by ~7%.
 *
 * @type {number}
 */
const RELAYOUT_SIZE_TOLERANCE = 0.08;

/**
 * Logical px the portrait HUD band may grow past the current boss shift
 * before the scene relayouts — sub-pixel rounding, not a real panel change.
 * Any real growth relayouts: it means a panel now covers the boss.
 *
 * @type {number}
 */
const RELAYOUT_GROW_TOLERANCE = 2;

/**
 * Logical px the portrait HUD band may shrink before the scene relayouts to
 * pull the boss back up. A small gap under the panels costs nothing; a scene
 * restart costs a visible reboot.
 *
 * @type {number}
 */
const RELAYOUT_SHRINK_TOLERANCE = 60;

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

/**
 * Returns a copy of an authored layout moved by (dx, dy) — every anchor
 * shifts, the fighter row spans the new width — in a world W×H.
 *
 * @param {object} base A LAYOUTS entry.
 * @param {{mode: string, W: number, H: number, dx: number, dy: number, rowDy: number}} frame
 *   `rowDy` moves the fighter rows, which may differ from the boss's `dy`.
 * @return {object}
 */
function placed(base, { mode, W, H, dx, dy, rowDy }) {
  const [rowStart, rowEnd] = base.fighters.rowXRange;
  const rowXRange = [rowStart, W - (base.logicalWidth - rowEnd)];

  return {
    ...base,
    mode,
    shiftY: dy,
    fighterShiftY: rowDy,
    logicalWidth: W,
    logicalHeight: H,
    boss: {
      ...base.boss,
      anchor: { x: base.boss.anchor.x + dx, y: base.boss.anchor.y + dy },
      name: { x: base.boss.name.x + dx, y: base.boss.name.y + dy },
    },
    hpBar: { ...base.hpBar, x: base.hpBar.x + dx, y: base.hpBar.y + dy },
    fighters: {
      ...base.fighters,
      rowXRange,
      rowY: base.fighters.rowY + rowDy,
      perRowMax: Math.floor((rowXRange[1] - rowXRange[0]) / SLOT_PX[mode]),
    },
    bats: { ...base.bats, centerX: base.bats.centerX + dx, centerY: base.bats.centerY + dy },
    necromancer: { ...base.necromancer, anchor: { x: base.necromancer.anchor.x, y: base.necromancer.anchor.y + dy } },
  };
}

/**
 * The layout for a screen: the world takes the screen's own aspect so
 * Scale.FIT fills it with no letterbox bars. The authored world's short side
 * (540) stays when the screen is longer than 16:9 and its long side grows (up
 * to 1240); on a squarer screen (a tablet) the long side stays 960 and the
 * short side grows instead (up to 720). Everything stays centred across the
 * extra width; extra height in landscape is shared above and below.
 *
 * Then the boss block drops below the HUD band — the panels stacked over the
 * top of the canvas, `hudBand` logical px tall — when it reaches into it: in
 * portrait by up to the extra height plus PORTRAIT_MAX_SQUEEZE, in landscape
 * by up to LANDSCAPE_MAX_DROP. `shiftY` is the total vertical move, which
 * moves the boss block; `fighterShiftY` is what fighterDisplayConfig moves
 * the fighter rows by (in portrait the boss drop plus half the floor left
 * over, in landscape only the centring).
 *
 * @param {{width: number, height: number, hudBand?: number}} screen Available
 *   CSS px of the mount, and the HUD's top band in logical px (0 when unknown).
 * @return {object} A LAYOUTS-shaped layout plus `mode` and `shiftY`.
 */
export function layoutFor({ width, height, hudBand = 0 }) {
  const mode = width < height ? 'portrait' : 'landscape';
  const base = LAYOUTS[mode];
  const long = Math.max(width, height) / Math.min(width, height);
  const authored = LONG_SIDE.min / SHORT_SIDE.min;
  const longSide = long >= authored ? clamp(Math.round(SHORT_SIDE.min * long), LONG_SIDE.min, LONG_SIDE.max) : LONG_SIDE.min;
  const shortSide = long >= authored ? SHORT_SIDE.min : clamp(Math.round(LONG_SIDE.min / long), SHORT_SIDE.min, SHORT_SIDE.max);
  const W = mode === 'portrait' ? shortSide : longSide;
  const H = mode === 'portrait' ? longSide : shortSide;
  const dx = (W - base.logicalWidth) / 2;
  const centredY = mode === 'landscape' ? (H - base.logicalHeight) / 2 : 0;

  const blockTop = base.boss.anchor.y - BOSS_BLOCK_ABOVE_ANCHOR + centredY;
  const wanted = hudBand > 0 ? Math.round(hudBand + HUD_GAP - blockTop) : 0;
  const maxDrop = mode === 'portrait' ? H - base.logicalHeight + PORTRAIT_MAX_SQUEEZE : LANDSCAPE_MAX_DROP;
  const dy = centredY + clamp(wanted, 0, maxDrop);
  // portrait rows follow the boss down, then sit in the middle of whatever
  // extra floor the drop left over (a tall phone would otherwise bunch them
  // under the boss above an empty bottom third); landscape rows stay on the
  // floor — 540 tall, they would drop off the bottom — and only follow the
  // centring
  const spareFloor = Math.max(0, H - base.logicalHeight - dy);
  const rowDy = mode === 'portrait' ? dy + Math.round(spareFloor / 2) : centredY;

  return placed(base, { mode, W, H, dx, dy, rowDy });
}

/**
 * Whether a resize moved the screen far enough from the current layout to
 * restart the scene on a new one. Small height changes (a phone's URL bar)
 * keep the current layout; Scale.FIT absorbs them with a sliver of margin.
 * A portrait HUD band that grew into the boss always relayouts; one that
 * shrank only does past RELAYOUT_SHRINK_TOLERANCE.
 *
 * @param {object} current The layout the scene runs on.
 * @param {object} next layoutFor() of the new screen.
 * @return {boolean}
 */
export function needsRelayout(current, next) {
  if (current.mode !== next.mode) {
    return true;
  }
  const changed = (a, b) => Math.abs(a / b - 1) > RELAYOUT_SIZE_TOLERANCE;

  const shiftDelta = (next.shiftY ?? 0) - (current.shiftY ?? 0);

  return changed(next.logicalWidth, current.logicalWidth)
    || changed(next.logicalHeight, current.logicalHeight)
    || shiftDelta > RELAYOUT_GROW_TOLERANCE
    || shiftDelta < -RELAYOUT_SHRINK_TOLERANCE;
}
