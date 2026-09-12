import Phaser from 'phaser';

/**
 * Sends a heal orb from (x, y) to the Necromancer's current position, then
 * triggers its heal-glow + bolt-the-boss reaction on arrival. Reads the
 * Necromancer's position live (not a snapshot) since the orb travel time
 * means it could still be mid-wander when this starts. No-ops if the
 * Necromancer fixture isn't present/active.
 *
 * @param {Phaser.Scene} scene
 * @param {number} x
 * @param {number} y
 * @return {void}
 */
export function castHealOrbToNecromancer(scene, x, y) {
  const necromancer = scene.necromancer;
  if (!necromancer?.sprite?.active) {
    return;
  }

  const g = scene.add.graphics().setDepth(3).setBlendMode(Phaser.BlendModes.ADD);
  const draw = (px, py, scale) => {
    g.clear();
    g.fillStyle(0x22c55e, 0.9);
    g.fillCircle(px, py, 9 * scale);
    g.fillStyle(0xbbf7d0, 0.95);
    g.fillCircle(px, py, 4 * scale);
  };

  const state = { t: 0 };
  const durationMs = 380;
  scene.tweens.add({
    targets: state,
    t: 1,
    duration: durationMs,
    ease: 'Sine.easeIn',
    onUpdate: () => {
      if (!necromancer.sprite?.active) return;
      const nx = necromancer.sprite.x;
      const ny = necromancer.sprite.y;
      draw(x + (nx - x) * state.t, y + (ny - y) * state.t, 1 - state.t * 0.3);
    },
    onComplete: () => {
      g.destroy();
      necromancer.receiveHealAndBoltBoss?.();
    },
  });
}
