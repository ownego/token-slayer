import { expect, test } from 'vitest';
import { LAYOUTS } from '@battlefield/config.js';
import { isValidMoveTarget, bypassY, clampMoveTarget, snapToValidTarget, isInsideLeaderboardPanel, planRoute } from '@battlefield/move-geometry.js';

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

test('rejects a point inside the landscape leaderboard panel (right side)', () => {
  expect(isValidMoveTarget(750, 250, landscapeCtx)).toBe(false);
});

test('leaderboard stays on the right side in both orientations (fixed, does not flip)', () => {
  expect(isValidMoveTarget(750, 250, landscapeCtx)).toBe(false); // right side, landscape
  expect(isValidMoveTarget(450, 250, portraitCtx)).toBe(false);  // right side, portrait
});

test('Damage HUD stays on the left side in both orientations (fixed, does not flip)', () => {
  expect(isValidMoveTarget(100, 200, landscapeCtx)).toBe(false); // left side, landscape
  expect(isValidMoveTarget(100, 200, portraitCtx)).toBe(false);  // left side, portrait
});

test('isInsideLeaderboardPanel detects a click landing directly on the drawn panel rect', () => {
  expect(isInsideLeaderboardPanel(800, 100, LAYOUTS.landscape)).toBe(true);
  expect(isInsideLeaderboardPanel(700, 100, LAYOUTS.landscape)).toBe(false); // just left of the panel
  expect(isInsideLeaderboardPanel(800, 200, LAYOUTS.landscape)).toBe(false); // just below the panel
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
  const result = snapToValidTarget(750, 250, landscapeCtx);
  expect(result.x).toBeCloseTo(750, 1);
  expect(result.y).toBeCloseTo(286.5, 0);
  expect(isValidMoveTarget(result.x, result.y, landscapeCtx)).toBe(true);
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
