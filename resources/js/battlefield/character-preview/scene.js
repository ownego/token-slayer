import Phaser from 'phaser';
import { FIGHTER_TYPES } from '@battlefield/config.js';
import { TextureKey } from '@battlefield/constants.js';
import { ensureSparkTexture } from '@battlefield/spark-texture.js';
import { registerFighterAnimations } from '@battlefield/fighter/animations.js';
import { buildMoveset } from './moveset.js';
import { createSkillLoop } from './skill-loop.js';
import { getProjectileLaunchDelay } from './projectile-launch.js';
import { spawnPreviewProjectile } from './preview-projectile.js';

/**
 * Standalone Phaser scene powering the character-select modal's animated
 * preview. Fully decoupled from the live battlefield scene — no shared
 * camera, layout, or boss anchor.
 */
export class CharacterPreviewScene extends Phaser.Scene {
  constructor() {
    super('character-preview');
  }

  /** @return {void} */
  preload() {
    if (!this.textures.exists(TextureKey.FIGHTERS)) {
      this.load.atlas(
        TextureKey.FIGHTERS,
        '/assets/battlefield/fighters/fighters-atlas.png',
        '/assets/battlefield/fighters/fighters-atlas.json',
      );
    }
    if (!this.textures.exists(TextureKey.FIREBALL)) {
      this.load.spritesheet(TextureKey.FIREBALL, '/assets/battlefield/fx/fireball.png', { frameWidth: 16, frameHeight: 16 });
    }
  }

  /** @return {void} */
  create() {
    this.textures.get(TextureKey.FIGHTERS)?.setFilter(Phaser.Textures.FilterMode.NEAREST);
    ensureSparkTexture(this);

    const { width, height } = this.sys.game.config;
    this.centerX = width / 2;
    this.centerY = height / 2;
    this.projectileTarget = { x: this.centerX + 95, y: this.centerY - 25 };

    this.sprite = this.add.sprite(this.centerX, this.centerY, TextureKey.FIGHTERS).setScale(2.4);
    this.currentKey = null;
    this.moveset = null;

    this.skillLoop = createSkillLoop({
      playAnimation: (skill, onComplete) => this._playSkill(skill, onComplete),
      scheduleReplay: (fn, delayMs) => this.time.delayedCall(delayMs, fn),
    });

    this.game.events.emit('preview-ready');
  }

  /**
   * Switches the preview to a new character and resets to its idle skill.
   *
   * @param {string} characterKey - a FighterCharacter enum value
   * @return {void}
   */
  setCharacter(characterKey) {
    if (this.currentKey === characterKey) {
      return;
    }
    const ftype = FIGHTER_TYPES.find(ft => ft.key === characterKey);
    if (!ftype) {
      return;
    }
    registerFighterAnimations(this, ftype);
    this.currentKey = characterKey;
    this.moveset = buildMoveset(characterKey);
    this.sprite.setFlipX(false);
    this.skillLoop.select(this.moveset.skills[0]); // idle
  }

  /**
   * Selects a skill by id from the current character's moveset.
   *
   * @param {string} skillId - e.g. 'idle', 'walk', 'attack1', 'death'
   * @return {void}
   */
  selectSkill(skillId) {
    const skill = this.moveset?.skills.find(s => s.id === skillId);
    if (skill) {
      this.skillLoop.select(skill);
    }
  }

  /**
   * @return {{key: string, attackType: string, skills: Array<object>}|null}
   */
  getMoveset() {
    return this.moveset;
  }

  /**
   * @param {object} skill - one entry from buildMoveset().skills
   * @param {Function} onComplete
   * @return {void}
   */
  _playSkill(skill, onComplete) {
    this.sprite.play(skill.animKey);
    if (skill.loop) {
      return;
    }
    this.sprite.off(Phaser.Animations.Events.ANIMATION_COMPLETE);
    this.sprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, onComplete);

    if (skill.effectAnimKey) {
      const effect = this.add.sprite(this.centerX, this.centerY, TextureKey.FIGHTERS)
        .setScale(this.sprite.scaleX)
        .setBlendMode(Phaser.BlendModes.ADD)
        .setDepth(3)
        .play(skill.effectAnimKey);
      effect.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => effect.destroy());

      this._pendingProjectileTimer?.remove(false);
      this._pendingProjectileTimer = this.time.delayedCall(getProjectileLaunchDelay(skill.durationMs), () => {
        spawnPreviewProjectile(this, {
          fromX: this.centerX,
          fromY: this.centerY,
          toX: this.projectileTarget.x,
          toY: this.projectileTarget.y,
          attackType: this.moveset.attackType,
        });
      });
    }
  }
}
