import { TIMINGS } from '@battlefield/config.js';
import { BusEvent, TextureKey } from '@battlefield/constants.js';
import { bus } from '@battlefield/bus.js';
import { createHpCounter } from '@battlefield/shared/hp-counter.js';

/**
 * A tiny object pool: `take()` reuses a released item or calls `make()` for
 * a fresh one, `release()` returns it for the next `take()`. Used for the
 * floating damage-number Text objects — a burst of hits used to create and
 * destroy one Phaser Text per hit.
 *
 * @template T
 * @param {function(): T} make
 * @return {{take: function(): T, release: function(T): void, size: function(): number}}
 */
export function createTextPool(make) {
  const pool = [];
  return {
    take() {
      return pool.pop() ?? make();
    },
    release(item) {
      pool.push(item);
    },
    size() {
      return pool.length;
    },
  };
}

/** Handles hit-impact visuals: explosion, boss/bat flinch, camera shake, damage popup, HP bar tween. */
export class Impact {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
    // Floating damage numbers are reused instead of destroyed per hit — a
    // burst of hits used to create/destroy one Phaser Text every time.
    this.damagePool = createTextPool(() => scene.addSharpText(0, 0, '', {
      fontFamily: 'monospace',
      fontStyle: 'bold',
      fontSize: '20px',
    }, 3).setVisible(false));
    // One retargeted tween drives the HP bar/text — see hp-counter.js's own
    // docblock for why a burst of hits must never stack more than one.
    this.hp = createHpCounter({
      initial: scene.bossState.currentHp,
      startTween: ({ from, to, onUpdate }) => {
        const box = { v: from };
        return scene.tweens.add({
          targets: box,
          v: to,
          duration: TIMINGS.hpBarMs,
          ease: 'Quad.easeOut',
          onUpdate: () => onUpdate(box.v),
        });
      },
      // Ticks the DOM boss plate (hud/boss-plate.js) via the bus instead of
      // drawing Phaser text/rectangles directly — the plate replaced them.
      render: value => {
        bus.emit(BusEvent.BOSS_HP_TICK, { hp: value, max: scene.bossState.maxHp });
      },
    });
  }

  /**
   * Triggers all hit-impact visuals for a boss HP change.
   *
   * @param {number} hpAfter
   * @param {{sprite: Phaser.GameObjects.Sprite, x: number, y: number}|null} [target=null]
   *   When present, the hit visually landed on this entity (a bat) instead
   *   of the boss — the explosion/flinch/tint render there instead, and the
   *   boss itself is left untouched. The HP bar always still reflects the
   *   real boss HP regardless.
   * @param {number|string|null} [userId=null] the hitter — styles the damage
   *   popup as YOU (gold, larger) when it matches `scene.currentUserId`.
   * @return {void}
   */
  apply(hpAfter, target = null, userId = null) {
    const bossAnchor = this.scene.layout.boss.anchor;
    const impactX = target?.x ?? bossAnchor.x;
    const impactY = target?.y ?? bossAnchor.y;

    if (!this.scene.anims.exists('explosion-once')) {
      this.scene.anims.create({
        key: 'explosion-once',
        frames: this.scene.anims.generateFrameNumbers(TextureKey.EXPLOSION, { start: 0, end: 3 }),
        frameRate: 18,
      });
    }
    const burst = this.scene.add
      .sprite(impactX, impactY, TextureKey.EXPLOSION)
      .setScale(target ? 2 : 4);
    burst.play('explosion-once').once('animationcomplete', () => burst.destroy());

    if (!target) {
      const boss = this.scene.bossSprite;
      this._flinchBoss(boss);
      boss.setTint(0xffffff);
      this.scene.time.delayedCall(80, () => boss.clearTint());
    }

    this.scene.cameras.main.shake(
      TIMINGS.cameraShake.duration,
      TIMINGS.cameraShake.intensity,
    );

    const damage = Math.max(0, this.scene.bossState.currentHp - hpAfter);
    if (damage > 0) {
      const isYou = userId != null && this.scene.currentUserId != null && Number(userId) === Number(this.scene.currentUserId);
      this._spawnDamagePopup(damage, impactX, impactY, isYou);
    }

    this.hp.set(hpAfter);
    this.scene.bossState.currentHp = hpAfter;
  }

  /**
   * Squash-and-stretches the boss around its rest scale.
   *
   * The rest scale is captured only when no flinch is running on this sprite:
   * a hit landing mid-yoyo would otherwise read the half-squashed scale as its
   * baseline and leave the boss there, and a burst of hits ratchets it flatter
   * until a reload. A still-running flinch is stopped and snapped back first.
   * Not killTweensOf — that would also kill the boss patrol tween.
   *
   * @param {Phaser.GameObjects.Sprite} boss
   * @return {void}
   */
  _flinchBoss(boss) {
    const running = this._flinch?.boss === boss ? this._flinch : null;
    if (running) {
      running.tween.stop();
      boss.scaleX = running.restX;
      boss.scaleY = running.restY;
    }
    const restX = boss.scaleX;
    const restY = boss.scaleY;
    const tween = this.scene.tweens.add({
      targets: boss,
      scaleX: restX * 1.1,
      scaleY: restY * 0.9,
      duration: TIMINGS.flinchMs / 2,
      yoyo: true,
      ease: 'Quad.easeOut',
      onComplete: () => {
        if (this._flinch?.tween === tween) {
          this._flinch = null;
        }
      },
    });
    this._flinch = { boss, tween, restX, restY };
  }

  /**
   * Spawns a floating damage number above the given point, from the pool
   * (`this.damagePool`) instead of a fresh Text object. The current user's
   * own hits stand out: larger, gold, thicker stroke.
   *
   * @param {number} damage
   * @param {number} x
   * @param {number} y
   * @param {boolean} [isYou=false]
   * @return {void}
   */
  _spawnDamagePopup(damage, x, y, isYou = false) {
    const jitter = (Math.random() - 0.5) * 60;
    const startX = x + jitter;
    const startY = y - 40;
    const popup = this.damagePool.take();
    popup
      .setText(`-${damage.toLocaleString()}`)
      .setColor(isYou ? '#fbbf24' : '#fca5a5')
      .setStroke(isYou ? '#78350f' : '#7f1d1d', isYou ? 7 : 6)
      .setScale(isYou ? 1.3 : 1)
      .setPosition(startX, startY)
      .setAlpha(1)
      .setVisible(true);
    this.scene.tweens.add({
      targets: popup,
      y: startY - 44,
      alpha: 0,
      duration: 850,
      ease: 'Quad.easeOut',
      onComplete: () => {
        popup.setVisible(false);
        this.damagePool.release(popup);
      },
    });
  }
}
