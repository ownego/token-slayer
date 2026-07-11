const SPRITE_CHAR_HEIGHT = 18;
// Local constant mirroring bubble.js's ACTIVITY_MAX_CHARS export.
// Kept separate to avoid transitive Phaser import into this pure module; must sync if bubble.js value changes.
const ACTIVITY_MAX_CHARS = 18;

const DAMAGE_HUD_LEFT = 12;
const DAMAGE_HUD_Y = 5;
const DAMAGE_HUD_W = 176;
const DAMAGE_HUD_H = 130;

const LEADERBOARD_PAD = 4;
const LEADERBOARD_TOP = 5;
const LEADERBOARD_W = 240;
const LEADERBOARD_H = 160;

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
 * Returns half the fighter's horizontal footprint (avatar width) in logical
 * px, for sizing the left/right edge margin. Mirrors fighter/index.js's
 * avatarPx(): avatar diameter = fsize * 0.85.
 *
 * @param {number} fsize
 * @return {number}
 */
function fighterHalfWidthPx(fsize) {
  return (fsize * 0.85) / 2 + 15;
}

/**
 * Returns the fighter's vertical extent from center to the bottom of its
 * handle label, in logical px, for sizing the bottom edge margin. Mirrors
 * fighter/index.js's legH ((SPRITE_CHAR_BOT - SPRITE_HALF_FRAME) * scale =
 * 6 * scale) and handleFontPx().
 *
 * @param {number} fsize
 * @return {number}
 */
function fighterDownReachPx(fsize) {
  const scale = fsize / SPRITE_CHAR_HEIGHT;
  const legH = Math.round(6 * scale);
  const fontPx = Math.max(10, Math.round(fsize * 0.25));
  return legH + fontPx + Math.round(fontPx / 2) + 10;
}

/**
 * Returns true if (px, py) is a valid move target in logical pixels, given
 * the layout, boss geometry, and fighter size in ctx. Checks edge padding,
 * the boss/HP-bar column, the TOP DAMAGE leaderboard panel, and the top-left
 * Damage HUD panel.
 *
 * @param {number} px
 * @param {number} py
 * @param {{layout: object, bossType: object, fsize: number}} ctx
 * @return {boolean}
 */
export function isValidMoveTarget(px, py, ctx) {
  const { layout: L, bossType, fsize } = ctx;
  const fighterH = fighterReachPx(fsize);

  // Edge padding — grows with the fighter's own footprint (avatar width,
  // handle label below the feet) so a bigger/damage-grown fighter can't clip
  // off-screen in a corner; never smaller than the flat 3% baseline.
  const halfW      = Math.max(L.logicalWidth * 0.03, fighterHalfWidthPx(fsize));
  const downReach  = Math.max(L.logicalHeight * 0.03, fighterDownReachPx(fsize));
  if (px < halfW || px > L.logicalWidth - halfW) return false;
  if (py < L.logicalHeight * 0.03 || py > L.logicalHeight - downReach) return false;

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

  // 4. Leaderboard: fixed top-right in both orientations. Neither avatar nor
  //    action bubble may overlap the panel.
  const fontPx      = Math.max(9, Math.round(fsize * 0.22));
  const bubbleHalfW = Math.ceil(ACTIVITY_MAX_CHARS * fontPx * 0.6 / 2) + 12;
  const lbLeft = L.logicalWidth - LEADERBOARD_PAD - LEADERBOARD_W;
  if (px > lbLeft - bubbleHalfW && px < lbLeft + LEADERBOARD_W + bubbleHalfW &&
      py - fighterH < LEADERBOARD_TOP + LEADERBOARD_H && py > LEADERBOARD_TOP) {
    return false;
  }

  // 5. Damage HUD: fixed top-left in both orientations.
  if (px > DAMAGE_HUD_LEFT - bubbleHalfW && px < DAMAGE_HUD_LEFT + DAMAGE_HUD_W + bubbleHalfW &&
      py - fighterH < DAMAGE_HUD_Y + DAMAGE_HUD_H && py > DAMAGE_HUD_Y) {
    return false;
  }

  return true;
}

/**
 * Returns true if (px, py) lands directly on the drawn TOP DAMAGE leaderboard
 * panel (exact rect, no fighter-reach padding). Used to make clicking the
 * panel a no-op, the same way clicking the DOM Damage HUD is a no-op because
 * that element absorbs the click before it reaches the canvas.
 *
 * @param {number} px
 * @param {number} py
 * @param {object} layout
 * @return {boolean}
 */
export function isInsideLeaderboardPanel(px, py, layout) {
  const left = layout.logicalWidth - LEADERBOARD_PAD - LEADERBOARD_W;
  return px >= left && px <= left + LEADERBOARD_W &&
    py >= LEADERBOARD_TOP && py <= LEADERBOARD_TOP + LEADERBOARD_H;
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
 * @param {{layout: object, bossType: object, fsize: number}} ctx
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

/**
 * Returns the nearest reachable point to (px, py) when the point itself falls
 * outside the valid area. Returns (px, py) unchanged if already valid.
 *
 * Two-stage: first clamps into the outer screen-edge rectangle (nearest point
 * on/in the screen bounds — correct for a plain off-screen/near-edge click);
 * if that's still blocked by an internal obstacle (boss column, leaderboard,
 * Damage HUD), walks in from the guaranteed-clear bypassY row at that same x.
 * Approaching internal obstacles from directly below/above at the same x
 * (rather than from wherever the mover currently stands) keeps the snapped
 * point on the same side the player actually aimed for, instead of landing on
 * whichever obstacle a straight line from the mover's position happens to hit
 * first. Returns null if even the safe row at that x is unreachable.
 *
 * @param {number} px
 * @param {number} py
 * @param {{layout: object, bossType: object, fsize: number}} ctx
 * @return {{x: number, y: number}|null}
 */
export function snapToValidTarget(px, py, ctx) {
  if (isValidMoveTarget(px, py, ctx)) {
    return { x: px, y: py };
  }
  const { layout: L, fsize } = ctx;
  const halfW     = Math.max(L.logicalWidth * 0.03, fighterHalfWidthPx(fsize));
  const downReach = Math.max(L.logicalHeight * 0.03, fighterDownReachPx(fsize));
  const topMin    = Math.max(L.logicalHeight * 0.03, fighterReachPx(fsize) + L.logicalHeight * 0.02);
  const cx = Math.min(Math.max(px, halfW), L.logicalWidth - halfW);
  const cy = Math.min(Math.max(py, topMin), L.logicalHeight - downReach);
  if (isValidMoveTarget(cx, cy, ctx)) {
    return { x: cx, y: cy };
  }

  const safeY = bypassY(ctx);
  if (!isValidMoveTarget(cx, safeY, ctx)) {
    return null;
  }
  return clampMoveTarget(cx, safeY, cx, cy, ctx);
}

/**
 * Returns a waypoint list from (fromX, fromY) to (toX, toY), detouring
 * through the guaranteed-clear bypassY row when a direct path is blocked
 * (e.g. by the boss/HP-bar column). Used for both the mover's own locally
 * animated route and remote fighters' `fighter-moved` echo, so every client
 * renders the same detour instead of a straight line that clips an obstacle.
 * Returns null if the destination is entirely unreachable.
 *
 * @param {number} fromX
 * @param {number} fromY
 * @param {number} toX
 * @param {number} toY
 * @param {{layout: object, bossType: object, fsize: number}} ctx
 * @return {Array<{x: number, y: number}>|null}
 */
export function planRoute(fromX, fromY, toX, toY, ctx) {
  let destX = toX, destY = toY;
  if (!isValidMoveTarget(toX, toY, ctx)) {
    const snapped = snapToValidTarget(toX, toY, ctx);
    if (!snapped) {
      const direct = clampMoveTarget(fromX, fromY, toX, toY, ctx);
      return direct ? [direct] : null;
    }
    destX = snapped.x;
    destY = snapped.y;
  }

  const direct = clampMoveTarget(fromX, fromY, destX, destY, ctx);
  const directClear = direct
    && Math.abs(direct.x - destX) < 2
    && Math.abs(direct.y - destY) < 2;

  if (directClear) {
    return [{ x: destX, y: destY }];
  }

  const safeY = bypassY(ctx);
  const wp1 = { x: fromX, y: safeY };
  const wp2 = { x: destX, y: safeY };

  // Verify every segment of the detour is clear
  if (isValidMoveTarget(wp1.x, wp1.y, ctx) && isValidMoveTarget(wp2.x, wp2.y, ctx)) {
    const allClear = (from, to) => {
      const r = clampMoveTarget(from.x, from.y, to.x, to.y, ctx);
      return r && Math.abs(r.x - to.x) < 2 && Math.abs(r.y - to.y) < 2;
    };
    if (allClear({ x: fromX, y: fromY }, wp1)
        && allClear(wp1, wp2)
        && allClear(wp2, { x: destX, y: destY })) {
      const route = [];
      if (Math.abs(fromY - safeY) > 5) route.push(wp1);
      if (Math.abs(fromX - destX)    > 5) route.push(wp2);
      route.push({ x: destX, y: destY });
      return route;
    }
  }

  return direct ? [direct] : null;
}
