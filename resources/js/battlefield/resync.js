const DEFAULT_EPSILON = 0.01;

/**
 * Returns the server-authoritative positions this client's scene has drifted
 * from — the fix for Reverb's lack of event replay: a `FighterMoved` broadcast
 * that fires while this tab's WebSocket is disconnected (backgrounded tab,
 * laptop sleep, brief network drop) is lost forever for this one client, with
 * no other signal that it happened. Called after an Echo reconnect with a
 * fresh snapshot from the server so the small number of genuinely stale
 * fighters can be corrected silently, without touching anyone already in sync.
 *
 * @param {Array<{id: number|string, x: number, y: number, waypointMoving: boolean}>} localFighters Normalized [0..1] position currently shown for each fighter in the scene.
 * @param {Array<{user_id: number|string, x: number, y: number}>} serverPositions Normalized [0..1] authoritative positions from the resync response.
 * @param {number} [epsilon] Normalized-fraction tolerance below which a difference is treated as float noise, not real drift.
 * @return {Array<{user_id: number|string, x: number, y: number}>}
 */
export function driftedPositions(localFighters, serverPositions, epsilon = DEFAULT_EPSILON) {
  const localById = new Map(localFighters.map(f => [f.id, f]));

  return serverPositions.filter(server => {
    const local = localById.get(server.user_id);
    if (!local || local.waypointMoving) {
      return false;
    }
    return Math.abs(local.x - server.x) >= epsilon || Math.abs(local.y - server.y) >= epsilon;
  });
}
