/**
 * Calls `fn(scene)` once the scene has been created, and again after every
 * restart (a rotate restarts it). bootBattlefield() wires the DOM HUD before
 * Phaser has finished preloading, so anything read off the scene then (the
 * Top Damage board's damageTotals) doesn't exist yet — rendering only at boot
 * left the board empty after a reload until the next hit.
 *
 * @param {{scene: {getScene: function(string): ?object}, events?: {once: function(string, function): void}}} game The Phaser game.
 * @param {string} key The scene key.
 * @param {function(object): void} fn Called with the scene.
 * @return {function(): void} Stops listening.
 */
export function onSceneReady(game, key, fn) {
  const scene = game.scene.getScene(key);
  if (!scene) {
    // the scene manager hasn't booted the scene yet: try again once the game is ready
    let stop = () => {};
    game.events?.once?.('ready', () => { stop = onSceneReady(game, key, fn); });

    return () => stop();
  }
  const handler = () => fn(scene);
  scene.events.on('create', handler);
  if (scene.sys?.isActive?.()) {
    fn(scene);
  }

  return () => scene.events.off('create', handler);
}
