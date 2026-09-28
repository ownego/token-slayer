import { expect, test } from 'vitest';
import { LAYOUTS } from '@battlefield/config.js';
import { isValidMoveTarget, bypassY, clampMoveTarget, snapToValidTarget, planRoute, moveOrigin } from '@battlefield/move-geometry.js';
import { horizonYFor } from '@battlefield/layout.js';

const BOSS_TYPE = { frameWidth: 32, frameHeight: 32, scale: 4 };
const landscapeCtx = { layout: LAYOUTS.landscape, bossType: BOSS_TYPE, fsize: 48 };
const portraitCtx  = { layout: LAYOUTS.portrait,  bossType: BOSS_TYPE, fsize: 48 };

test('rejects points near the outer edge', () => {
  expect(isValidMoveTarget(5, 300, landscapeCtx)).toBe(false);   // px well inside the left/right margin
  expect(isValidMoveTarget(955, 300, landscapeCtx)).toBe(false); // px well inside the left/right margin
  expect(isValidMoveTarget(480, 5, landscapeCtx)).toBe(false);   // py < 3% of 540 (top margin is unchanged)
});

test('left/right edge margin grows with fighter size beyond the flat 3% floor', () => {
  const grownCtx = { ...landscapeCtx, fsize: 63 };
  expect(isValidMoveTarget(35, 460, grownCtx)).toBe(false);
  expect(isValidMoveTarget(45, 460, grownCtx)).toBe(true);
});

test('bottom edge margin grows with fighter size to keep the handle label on-screen', () => {
  const grownCtx = { ...landscapeCtx, fsize: 63 };
  expect(isValidMoveTarget(300, 510, grownCtx)).toBe(false);
  expect(isValidMoveTarget(300, 480, grownCtx)).toBe(true);
});

test('smallest fighter tier keeps the old flat-3% margin unchanged (no regression)', () => {
  const smallCtx = { ...landscapeCtx, fsize: 27 };
  expect(isValidMoveTarget(29, 460, smallCtx)).toBe(true);
  expect(isValidMoveTarget(20, 460, smallCtx)).toBe(false);
});

test('accepts an open point clear of the boss column and leaderboard', () => {
  expect(isValidMoveTarget(480, 470, landscapeCtx)).toBe(true);
});

test('rejects a point inside the boss/HP-bar exclusion column', () => {
  expect(isValidMoveTarget(480, 200, landscapeCtx)).toBe(false);
});

// The old hardcoded LEADERBOARD_*/DAMAGE_HUD_* rects are gone — obstacles
// now come from live HUD zones passed in by the caller (Task 11's
// domToWorld, refreshed from the DOM every 500ms). With none passed
// (the default, `zones: []`), a point that used to sit inside those fixed
// rects is open ground; passing a matching zone is what rejects it.
test('click-to-move rejects a target inside a live HUD zone and accepts the old leaderboard corner', () => {
  const zones = [{ l: 0, r: 300, t: 100, b: 250 }];
  expect(isValidMoveTarget(100, 180, { ...landscapeCtx, zones })).toBe(false);
  expect(isValidMoveTarget(900, 250, landscapeCtx)).toBe(true);  // was the Phaser board's fixed corner
  expect(isValidMoveTarget(100, 200, landscapeCtx)).toBe(true);  // was the Phaser Damage HUD's fixed corner
});

test('a zone is padded by the fighter\'s own action-bubble width, not just its bare rect', () => {
  const zones = [{ l: 700, r: 900, t: 100, b: 300 }];
  // 650 sits left of the bare rect (l=700) but well inside its action-bubble
  // padding, and within the zone's vertical band — still rejected.
  expect(isValidMoveTarget(650, 200, { ...landscapeCtx, zones })).toBe(false);
  // Far enough left (and clear of the boss column) that even the padding
  // doesn't reach.
  expect(isValidMoveTarget(300, 200, { ...landscapeCtx, zones })).toBe(true);
});

test('bypassY clears the boss/HP-bar column with margin', () => {
  expect(bypassY(landscapeCtx)).toBeCloseTo(468.5, 1);
});

test('clampMoveTarget returns the destination unchanged when the path is clear', () => {
  const result = clampMoveTarget(200, 470, 700, 470, landscapeCtx);
  expect(result.x).toBeCloseTo(700, 1);
  expect(result.y).toBeCloseTo(470, 1);
});

test('clampMoveTarget stops at the boss zone boundary when the path crosses it', () => {
  const result = clampMoveTarget(300, 200, 480, 200, landscapeCtx);
  expect(result.x).toBeCloseTo(365, 0);
  expect(result.y).toBeCloseTo(200, 1);
});

test('clampMoveTarget returns null when the source itself is invalid', () => {
  const result = clampMoveTarget(480, 200, 480, 250, landscapeCtx);
  expect(result).toBeNull();
});

test('snapToValidTarget returns the point unchanged when already valid', () => {
  const result = snapToValidTarget(480, 470, landscapeCtx);
  expect(result.x).toBeCloseTo(480, 1);
  expect(result.y).toBeCloseTo(470, 1);
});

test('snapToValidTarget pulls an invalid destination down to the nearest open point on the same column', () => {
  // The exact geometry the old hardcoded TOP DAMAGE leaderboard rect used to
  // occupy, now supplied as a live HUD zone instead.
  const zones = [{ l: 716, r: 956, t: 5, b: 165 }];
  const ctx = { ...landscapeCtx, zones };
  const result = snapToValidTarget(750, 250, ctx);
  expect(result.x).toBeCloseTo(750, 1);
  expect(result.y).toBeCloseTo(286.5, 0);
  expect(isValidMoveTarget(result.x, result.y, ctx)).toBe(true);
});

test('snapToValidTarget resolves a click past the edge margin AND inside the Damage HUD zone', () => {
  const result = snapToValidTarget(10, 250, landscapeCtx);
  expect(result).not.toBeNull();
  expect(isValidMoveTarget(result.x, result.y, landscapeCtx)).toBe(true);
});

test('snapToValidTarget returns null only in a genuinely unreachable degenerate case', () => {
  const degenerateCtx = { ...landscapeCtx, fsize: 2000 };
  const result = snapToValidTarget(480, 250, degenerateCtx);
  expect(result).toBeNull();
});

test('snapToValidTarget clamps a click past the LEFT edge margin to the nearest boundary point', () => {
  const grownCtx = { ...landscapeCtx, fsize: 63 }; // halfW ~41.8
  const result = snapToValidTarget(5, 460, grownCtx);
  expect(result.x).toBeCloseTo(41.8, 0);
  expect(result.y).toBeCloseTo(460, 1);
  expect(isValidMoveTarget(result.x, result.y, grownCtx)).toBe(true);
});

test('snapToValidTarget clamps a click past the BOTTOM edge margin to the nearest boundary point', () => {
  const grownCtx = { ...landscapeCtx, fsize: 63 }; // downReach ~55
  const result = snapToValidTarget(300, 530, grownCtx);
  expect(result.x).toBeCloseTo(300, 1);
  expect(result.y).toBeCloseTo(485, 0);
  expect(isValidMoveTarget(result.x, result.y, grownCtx)).toBe(true);
});

test('snapToValidTarget clamps a click past BOTH edges (corner) to the nearest boundary point', () => {
  const result = snapToValidTarget(5, 530, landscapeCtx); // fsize=48: halfW ~35.4, downReach ~44
  expect(result.x).toBeCloseTo(35.4, 0);
  expect(result.y).toBeCloseTo(496, 0);
  expect(isValidMoveTarget(result.x, result.y, landscapeCtx)).toBe(true);
});

test('planRoute returns a single direct waypoint when the path is clear', () => {
  const route = planRoute(200, 470, 700, 470, landscapeCtx);
  expect(route).toHaveLength(1);
  expect(route[0].x).toBeCloseTo(700, 1);
  expect(route[0].y).toBeCloseTo(470, 1);
});

test('planRoute detours through bypassY when a same-height move would otherwise cross the boss column', () => {
  // Regression: a straight tween from mid-left to mid-right at boss height used to
  // clamp at the boss's left edge instead of routing around, so remote viewers saw
  // the fighter stop next to the boss instead of the detour the mover animated locally.
  const route = planRoute(300, 200, 620, 200, landscapeCtx);
  expect(route).toHaveLength(3);
  expect(route[0].x).toBeCloseTo(300, 1);
  expect(route[0].y).toBeCloseTo(468.5, 1);
  expect(route[1].x).toBeCloseTo(620, 1);
  expect(route[1].y).toBeCloseTo(468.5, 1);
  expect(route[2].x).toBeCloseTo(620, 1);
  expect(route[2].y).toBeCloseTo(200, 1);
});

test('moveOrigin keeps the live sprite position when it is a valid standing spot', () => {
  expect(moveOrigin({ x: 700, y: 470 }, { x: 300, y: 470 }, landscapeCtx)).toEqual({ x: 700, y: 470 });
});

// A melee dash leaves the sprite inside the boss column for a few frames; a
// route planned from there can never leave (planRoute returns null), so the
// fighter's resting home is the only origin that can still reach anywhere.
test('moveOrigin falls back to the resting home when the sprite is mid-dash inside the boss column', () => {
  expect(moveOrigin({ x: 480, y: 200 }, { x: 300, y: 470 }, landscapeCtx)).toEqual({ x: 300, y: 470 });
});

test('moveOrigin keeps the sprite position when the home is no better (both blocked)', () => {
  expect(moveOrigin({ x: 480, y: 200 }, { x: 480, y: 210 }, landscapeCtx)).toEqual({ x: 480, y: 200 });
});

test('moveOrigin tolerates a fighter with no recorded home yet', () => {
  expect(moveOrigin({ x: 480, y: 200 }, null, landscapeCtx)).toEqual({ x: 480, y: 200 });
});

test.each([
  ['landscape', landscapeCtx],
  ['portrait', portraitCtx],
])('%s: a fighter can never stand with its feet above the line at the foot of the mountains', (_, ctx) => {
  const horizon = horizonYFor(ctx.layout);
  const legH = Math.round(6 * (ctx.fsize / 18));
  const x = ctx.layout.logicalWidth * 0.15; // clear of the boss column and any HUD zone
  expect(isValidMoveTarget(x, horizon - legH - 4, ctx)).toBe(false);
  expect(isValidMoveTarget(x, horizon - legH + 20, ctx)).toBe(true);
});

test('a click on the sky snaps down onto the ground, not somewhere above the mountains', () => {
  const horizon = horizonYFor(landscapeCtx.layout);
  const snapped = snapToValidTarget(150, 80, landscapeCtx);
  expect(snapped.y + Math.round(6 * (48 / 18))).toBeGreaterThanOrEqual(horizon);
});
