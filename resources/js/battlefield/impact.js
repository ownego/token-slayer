import { TIMINGS } from '@battlefield/config.js';
import { TextureKey } from '@battlefield/constants.js';
import { formatHp } from '@battlefield/format.js';
import { Boss } from '@battlefield/boss.js';

/** Handles hit-impact visuals: explosion, boss flinch, camera shake, damage popup, HP bar tween. */
export class Impact {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
  }

  /**
   * Triggers all hit-impact visuals for a boss HP change.
   *
   * @param {number} hpAfter
   * @return {void}
   */
  apply(hpAfter) {
    const bossAnchor = this.scene.layout.boss.anchor;
    const hpBar = this.scene.layout.hpBar;

    if (!this.scene.anims.exists('explosion-once')) {
      this.scene.anims.create({
        key: 'explosion-once',
        frames: this.scene.anims.generateFrameNumbers(TextureKey.EXPLOSION, { start: 0, end: 3 }),
        frameRate: 18,
      });
    }
    const burst = this.scene.add
      .sprite(bossAnchor.x, bossAnchor.y, TextureKey.EXPLOSION)
      .setScale(4);
    burst.play('explosion-once').once('animationcomplete', () => burst.destroy());

    const boss = this.scene.bossSprite;
    const baseScaleX = boss.scaleX;
    const baseScaleY = boss.scaleY;
    this.scene.tweens.add({
      targets: boss,
      scaleX: baseScaleX * 1.1,
      scaleY: baseScaleY * 0.9,
      duration: TIMINGS.flinchMs / 2,
      yoyo: true,
      ease: 'Quad.easeOut',
    });
    boss.setTint(0xffffff);
    this.scene.time.delayedCall(80, () => boss.clearTint());

    this.scene.cameras.main.shake(
      TIMINGS.cameraShake.duration,
      TIMINGS.cameraShake.intensity,
    );

    const damage = Math.max(0, this.scene.bossState.currentHp - hpAfter);
    if (damage > 0) {
      this._spawnDamagePopup(damage);
    }

    const max = this.scene.bossState.maxHp;
    const counter = { v: this.scene.bossState.currentHp };
    this.scene.tweens.add({
      targets: counter,
      v: hpAfter,
      duration: TIMINGS.hpBarMs,
      ease: 'Quad.easeOut',
      onUpdate: () => {
        this.scene.hpBarFill.setFillStyle(Boss.hpBarColor(counter.v, max));
        this.scene.hpBarFill.width = Math.round(hpBar.width * (counter.v / max));
        this.scene.hpText.setText(`${formatHp(counter.v)} / ${formatHp(max)}`);
      },
    });
    this.scene.bossState.currentHp = hpAfter;
  }

  /**
   * Spawns a floating damage number above the boss.
   *
   * @param {number} damage
   * @return {void}
   */
  _spawnDamagePopup(damage) {
    const bossAnchor = this.scene.layout.boss.anchor;
    const jitter = (Math.random() - 0.5) * 60;
    const startX = bossAnchor.x + jitter;
    const startY = bossAnchor.y - 40;
    const popup = this.scene.addSharpText(startX, startY, `-${damage.toLocaleString()}`, {
      fontFamily: 'monospace',
      fontSize: '20px',
      color: '#fca5a5',
      stroke: '#7f1d1d',
      strokeThickness: 5,
    });
    this.scene.tweens.add({
      targets: popup,
      y: startY - 80,
      alpha: 0,
      duration: 900,
      ease: 'Quad.easeOut',
      onComplete: () => popup.destroy(),
    });
  }
}
