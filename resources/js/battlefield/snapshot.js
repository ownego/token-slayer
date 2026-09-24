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
        // Minions are cosmetic-only and were deliberately never snapshotted
        // (a reboot just re-seeds from the next live broadcast) — but now
        // that the boot payload itself seeds an initial count (see
        // Battlefield::mount()'s SubagentCountCache::many() and scene.js's
        // own agentCount seeding loop), an orientation-change reboot must
        // carry it through the SAME `state.fighters` shape too, or it
        // silently drops every fighter's live subagent count back to 0 on
        // every rotate — caught by battlefield-reviewer before ship.
        agentCount: scene.minions?.byUser.get(f.id)?.length ?? 0,
      };
    });
  }
  if (scene.damageTotals?.size > 0) {
    next.damageTotals = [...scene.damageTotals.entries()];
  }
  return next;
}
