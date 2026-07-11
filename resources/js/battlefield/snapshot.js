/**
 * Capture the live scene into a serializable state object so the game can be
 * destroyed and re-booted (e.g. on orientation change) without losing what
 * the player was looking at. Must stay in the same shape as the initial
 * data-battlefield-state payload.
 */
export function snapshotState(currentState, scene) {
  if (!scene) {
    return currentState;
  }
  const next = { ...currentState };
  if (scene.bossState) {
    next.boss = {
      number: scene.bossState.number,
      name: scene.bossState.name,
      currentHp: scene.bossState.currentHp,
      maxHp: scene.bossState.maxHp,
    };
  }
  if (scene.leaderboard) {
    next.leaderboard = scene.leaderboard.getRanked().map(([userId, damage, handle]) => ({
      userId,
      damage,
      handle,
    }));
  }
  next.currentUserId = scene.currentUserId ?? currentState.currentUserId ?? null;
  if (scene.fighters?.size > 0) {
    next.fighters = [...scene.fighters.values()].map(f => {
      const charge = scene.charges.get(f.id);
      const pos = f.pos
        ? { x: f.pos.x / scene.layout.logicalWidth, y: f.pos.y / scene.layout.logicalHeight }
        : null;
      return {
        id: f.id,
        handle: f.handleText,
        avatarUrl: f.avatarUrl,
        character: f.ftype?.key ?? null,
        charging: charge ? { activity: charge.activity ?? '' } : null,
        position: pos,
      };
    });
  }
  if (scene.damageTotals?.size > 0) {
    next.damageTotals = [...scene.damageTotals.entries()];
  }
  return next;
}
