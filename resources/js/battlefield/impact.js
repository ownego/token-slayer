import { TIMINGS } from './config.js';
import { formatHp } from './format.js';

export function applyImpact(scene, hpAfter) {
  const bossAnchor = scene.layout.boss.anchor;
  const hpBar = scene.layout.hpBar;

  if (!scene.anims.exists('explosion-once')) {
    scene.anims.create({
      key: 'explosion-once',
      frames: scene.anims.generateFrameNumbers('explosion', { start: 0, end: 3 }),
      frameRate: 18,
    });
  }
  const burst = scene.add
    .sprite(bossAnchor.x, bossAnchor.y, 'explosion')
    .setScale(4);
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
  boss.setTint(0xffffff);
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
  const counter = { v: scene.bossState.currentHp };
  scene.tweens.add({
    targets: counter,
    v: hpAfter,
    duration: TIMINGS.hpBarMs,
    ease: 'Quad.easeOut',
    onUpdate: () => {
      scene.hpBarFill.width = Math.round(hpBar.width * (counter.v / max));
      scene.hpText.setText(`${formatHp(counter.v)} / ${formatHp(max)}`);
    },
  });
  scene.bossState.currentHp = hpAfter;
}

function spawnDamagePopup(scene, damage) {
  const bossAnchor = scene.layout.boss.anchor;
  const jitter = (Math.random() - 0.5) * 60;
  const startX = bossAnchor.x + jitter;
  const startY = bossAnchor.y - 40;
  const popup = scene.addSharpText(startX, startY, `-${damage.toLocaleString()}`, {
    fontFamily: 'monospace',
    fontSize: '20px',
    color: '#fca5a5',
    stroke: '#7f1d1d',
    strokeThickness: 5,
  });
  scene.tweens.add({
    targets: popup,
    y: startY - 80,
    alpha: 0,
    duration: 900,
    ease: 'Quad.easeOut',
    onComplete: () => popup.destroy(),
  });
}
