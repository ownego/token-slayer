import { BOSS_ANCHOR, TIMINGS, HP_BAR } from './config.js';

export function applyImpact(scene, hpAfter) {
  if (!scene.anims.exists('explosion-once')) {
    scene.anims.create({
      key: 'explosion-once',
      frames: scene.anims.generateFrameNumbers('explosion', { start: 0, end: 3 }),
      frameRate: 18,
    });
  }
  const burst = scene.add
    .sprite(BOSS_ANCHOR.x, BOSS_ANCHOR.y, 'explosion')
    .setScale(2);
  burst.play('explosion-once').once('animationcomplete', () => burst.destroy());

  const boss = scene.bossSprite;
  const baseScaleX = boss.scaleX;
  const baseScaleY = boss.scaleY;
  scene.tweens.add({
    targets: boss,
    scaleX: baseScaleX * 1.1,
    scaleY: baseScaleY * 0.9,
    duration: TIMINGS.flinchMs / 2,
    yoyo: true,
    ease: 'Quad.easeOut',
  });
  boss.setTintFill(0xffffff);
  scene.time.delayedCall(80, () => boss.clearTint());

  scene.cameras.main.shake(
    TIMINGS.cameraShake.duration,
    TIMINGS.cameraShake.intensity,
  );

  const damage = Math.max(0, scene.bossState.currentHp - hpAfter);
  if (damage > 0) {
    spawnDamagePopup(scene, damage);
  }

  const max = scene.bossState.maxHp;
  const targetWidth = HP_BAR.width * (hpAfter / max);
  scene.tweens.add({
    targets: scene.hpBarFill,
    width: targetWidth,
    duration: TIMINGS.hpBarMs,
    ease: 'Quad.easeOut',
  });
  const counter = { v: scene.bossState.currentHp };
  scene.tweens.add({
    targets: counter,
    v: hpAfter,
    duration: TIMINGS.hpBarMs,
    ease: 'Quad.easeOut',
    onUpdate: () => scene.hpText.setText(`${Math.round(counter.v)} / ${max}`),
  });
  scene.bossState.currentHp = hpAfter;
}

function spawnDamagePopup(scene, damage) {
  const jitter = (Math.random() - 0.5) * 30;
  const startX = BOSS_ANCHOR.x + jitter;
  const startY = BOSS_ANCHOR.y - 20;
  const popup = scene.addSharpText(startX, startY, `-${damage.toLocaleString()}`, {
    fontFamily: 'monospace',
    fontSize: '10px',
    color: '#fca5a5',
    stroke: '#7f1d1d',
    strokeThickness: 3,
  });
  scene.tweens.add({
    targets: popup,
    y: startY - 40,
    alpha: 0,
    duration: 900,
    ease: 'Quad.easeOut',
    onComplete: () => popup.destroy(),
  });
}
