const SPRITE_CHAR_HEIGHT = 18;
// Local constant mirroring bubble.js's ACTIVITY_MAX_CHARS export.
// Kept separate to avoid transitive Phaser import into this pure module; must sync if bubble.js value changes.
const ACTIVITY_MAX_CHARS = 18;

/**
 * Returns a fighter's vertical extent from feet to the top of its action
 * bubble, in logical px, for the given display size.
 *
 * @param {number} fsize
 * @return {number}
 */
function fighterReachPx(fsize) {
  const scale = fsize / SPRITE_CHAR_HEIGHT;
  const avatarUp = Math.round(12 * scale) + 38;
  const avRadius = Math.round(fsize * 0.85 * 1.06) / 2;
  return avatarUp + avRadius + 30;
}

/**
 * Returns the bottom edge of the boss/HP-bar exclusion zone, in logical px.
 *
 * @param {object} layout
 * @param {number} fsize
 * @return {number}
 */
function bossZoneBottom(layout, fsize) {
  const fontPx = Math.max(9, Math.round(fsize * 0.22));
  const bubbleHalfH = Math.ceil((fontPx + 8) / 2);
  return layout.hpBar.y + layout.hpBar.height + 10 + bubbleHalfH;
}

/**
 * Returns true if (px, py) is a valid move target in logical pixels, given
 * the layout, boss geometry, and fighter size in ctx.
 *
 * @param {number} px
 * @param {number} py
 * @param {{layout: object, bossType: object, fsize: number, isPortrait: boolean}} ctx
 * @return {boolean}
 */
export function isValidMoveTarget(px, py, ctx) {
  const { layout: L, bossType, fsize, isPortrait } = ctx;
  const fighterH = fighterReachPx(fsize);

  // Edge padding
  if (px < L.logicalWidth * 0.03 || px > L.logicalWidth * 0.97) return false;
  if (py < L.logicalHeight * 0.03 || py > L.logicalHeight * 0.97) return false;

  // 1. Action bubble must stay on-screen — prevents going so high it disappears
  if (py < fighterH + L.logicalHeight * 0.02) return false;

  // 2+3. Merged boss+HP bar exclusion — one solid column from boss sprite top to HP bar bottom.
  //      Blocks the gap between them so fighters can't sneak through the seam.
  const bossScale  = bossType.scale ?? 1;
  const bossFrameW = bossType.animFiles ? bossType.animFiles.idle.frameWidth  : (bossType.frameWidth  ?? 32);
  const bossFrameH = bossType.animFiles ? bossType.animFiles.idle.frameHeight : (bossType.frameHeight ?? 32);
  const bossHalfW  = (bossFrameW * bossScale) / 2;
  const bossHalfH  = (bossFrameH * bossScale) / 2;
  const hpHalfW    = L.hpBar.width / 2 + 15;
  const zoneHalfW  = Math.max(bossHalfW + 12, hpHalfW);
  const zoneTop    = L.boss.anchor.y - bossHalfH - 12;
  const zoneBot    = bossZoneBottom(L, fsize);
  if (Math.abs(px - L.boss.anchor.x) < zoneHalfW &&
      py - fighterH < zoneBot &&
      py > zoneTop) {
    return false;
  }

  // 4. Leaderboard: neither avatar nor action bubble may overlap the panel.
  const fontPx      = Math.max(9, Math.round(fsize * 0.22));
  const bubbleHalfW = Math.ceil(ACTIVITY_MAX_CHARS * fontPx * 0.6 / 2) + 12;
  const LB_W = 240, LB_H = 160, LB_PAD = 4, LB_TOP = 5;
  const lbLeft = isPortrait ? LB_PAD : L.logicalWidth - LB_PAD - LB_W;
  if (px > lbLeft - bubbleHalfW && px < lbLeft + LB_W + bubbleHalfW &&
      py - fighterH < LB_TOP + LB_H && py > LB_TOP) {
    return false;
  }

  return true;
}

/**
 * Returns a Y position guaranteed to clear boss sprite, HP bar, and fighter
 * height, for the given layout/fighter size in ctx.
 *
 * @param {{layout: object, fsize: number}} ctx
 * @return {number}
 */
export function bypassY(ctx) {
  const { layout: L, fsize } = ctx;
  const fighterH = fighterReachPx(fsize);
  const zoneBot  = bossZoneBottom(L, fsize);
  return Math.min(zoneBot + fighterH + 15, L.logicalHeight * 0.92);
}

/**
 * Returns the furthest valid point along the segment from (fromX, fromY) to
 * (toX, toY), or null if the source itself is invalid.
 *
 * @param {number} fromX
 * @param {number} fromY
 * @param {number} toX
 * @param {number} toY
 * @param {{layout: object, bossType: object, fsize: number, isPortrait: boolean}} ctx
 * @return {{x: number, y: number}|null}
 */
export function clampMoveTarget(fromX, fromY, toX, toY, ctx) {
  let lo = 0, hi = 1;
  for (let i = 0; i < 18; i++) {
    const mid = (lo + hi) / 2;
    const mx = fromX + (toX - fromX) * mid;
    const my = fromY + (toY - fromY) * mid;
    if (isValidMoveTarget(mx, my, ctx)) {
      lo = mid;
    } else {
      hi = mid;
    }
  }

  if (lo < 0.005) return null;
  if (lo > 0.999) return { x: toX, y: toY };
  return {
    x: fromX + (toX - fromX) * lo,
    y: fromY + (toY - fromY) * lo,
  };
}
