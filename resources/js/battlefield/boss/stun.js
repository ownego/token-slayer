/**
 * Returns true when the stun cooldown has expired and a new stun can be applied.
 *
 * @param {{ lastStunAt: number|null }} entry
 * @param {number} now  current timestamp in ms
 * @return {boolean}
 */
export function stunCooldownExpired(entry, now) {
  return !entry.lastStunAt || now - entry.lastStunAt >= 3000;
}

/**
 * Applies a timed stun visual effect (red flash + orbiting stars) to a fighter.
 *
 * @param {Phaser.Scene} scene
 * @param {object} entry
 * @return {void}
 */
export function applyStunEffect(scene, entry) {
  if (!entry.sprite?.active) return;
  const now = scene.time.now;
  const spawnStars = stunCooldownExpired(entry, now);

  // Visual-only stun: record the timestamp for star-orbit cooldown, but do NOT
  // block movement — the fighter keeps walking normally while the effect plays.
  entry.lastStunAt = now;

  if (entry.body?.active) entry.body.setTint(0xff2222);
  scene.time.delayedCall(500, () => {
    if (entry.body?.active) entry.body.clearTint();
  });

  scene.cameras.main.shake(180, 0.004);

  // Skip spawning a new star orbit if one is already active to avoid stacking.
  if (!spawnStars) return;

  const bScale = (entry.displaySize ?? 45) / 18;
  const headOffY = -Math.round(12 * bScale);
  const rx = Math.round(bScale * 10);
  const ry = Math.round(bScale * 3.5);
  const N = 6;
  const period = 2800;
  const total = 3000;

  const stunStars = [];
  for (let i = 0; i < N; i++) {
    const phase = (i / N) * Math.PI * 2;
    const s = scene.add.text(0, 0, '★', {
      fontFamily: 'Arial, sans-serif', fontSize: '11px',
      color: '#fbbf24', stroke: '#78350f', strokeThickness: 2,
    }).setOrigin(0.5).setDepth(112);
    stunStars.push({ text: s, phase });
  }

  const startAt = scene.time.now;
  const ticker = scene.time.addEvent({
    delay: 16,
    loop: true,
    callback: () => {
      const elapsed = scene.time.now - startAt;
      const baseAngle = (elapsed / period) * Math.PI * 2;
      const fadeStart = total * 0.75;
      const alpha = elapsed > fadeStart ? 1 - (elapsed - fadeStart) / (total - fadeStart) : 1;

      const cs = entry.sprite?.scaleX ?? 1;
      const cx = entry.sprite?.x ?? 0;
      const cy = (entry.sprite?.y ?? 0) + headOffY * cs;

      for (const star of stunStars) {
        const a = baseAngle + star.phase;
        const sinA = Math.sin(a);
        star.text.setPosition(cx + Math.cos(a) * rx, cy + sinA * ry);
        star.text.setDepth(sinA < 0 ? 112 : 1);
        star.text.setScale(sinA < 0 ? 1 : 0.65);
        star.text.setAlpha(alpha);
      }

      if (elapsed >= total) {
        ticker.remove();
        stunStars.forEach(s => s.text.destroy());
      }
    },
  });
}
