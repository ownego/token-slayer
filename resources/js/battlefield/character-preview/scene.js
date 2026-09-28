import Phaser from 'phaser';
import { FIGHTER_TYPES } from '@battlefield/config.js';
import { TextureKey } from '@battlefield/constants.js';
import { registerFighterAnimations } from '@battlefield/fighter/animations.js';
import { ATLAS_VERSION } from '@battlefield/config/atlas-version.js';
import { skyFrame } from '@battlefield/environment/sky-layer.js';
import { findHeel, heelToLocal } from '@battlefield/shared/heel.js';
import { SPARK_EMITTER, sparkAngle } from '@battlefield/shared/sparks.js';
import { ensureSoftGlowTexture, ensureSparkStreakTexture } from '@battlefield/spark-texture.js';
import { loadAvatarTexture } from '@battlefield/fighter/avatar.js';
import { buildMoveset } from './moveset.js';
import { createSkillLoop } from './skill-loop.js';

/**
 * A fixed, pleasant late-afternoon local time (17:30) for the mini stage's
 * backdrop — the sky is decorative here, not the viewer's own live clock,
 * so it always reads as a warm, readable stage rather than whatever hour
 * happens to be live when the sheet is opened.
 *
 * @type {{lat: number, lon: number}}
 */
const STAGE_SITE = { lat: 21.03, lon: 105.85 };

/**
 * The fighter atlas's own unscaled ink height, in px, for stageScale()'s
 * division — an estimate consistent with the ~20-29px ink-height
 * convention companion sprites already use (config/companions.js), for a
 * 100px raw frame's mostly-transparent padding.
 *
 * @type {number}
 */
const FIGHTER_BODY_HEIGHT_PX = 30;

/**
 * How big to draw the fighter body on the mini stage, from the stage
 * element's own box (so a phone-sized stage gets a smaller fighter, never
 * an overflowing one): `clamp(round(max(56, min(94, H*0.17, W*0.23)) / bodyH), 2, 5)`.
 *
 * @param {number} stageWidth
 * @param {number} stageHeight
 * @param {number} bodyHeightPx the sprite's own unscaled body height in px
 * @return {number} an integer scale factor, 2..5
 */
export function stageScale(stageWidth, stageHeight, bodyHeightPx) {
  const target = Math.max(56, Math.min(94, stageHeight * 0.17, stageWidth * 0.23));

  return Math.min(5, Math.max(2, Math.round(target / bodyHeightPx)));
}

/**
 * The avatar bubble's size and how far above the head it centres, at
 * battlefield proportions (0.85 of an 18px-tall body unit, centred 38/48
 * of a body above the head) scaled by the stage's own fighter scale.
 *
 * @param {number} scale from stageScale()
 * @return {{size: number, centreAbove: number}}
 */
export function avatarFor(scale) {
  return { size: Math.round(18 * scale * 0.85), centreAbove: (18 * scale * 38) / 48 };
}

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
        `/assets/battlefield/fighters/fighters-atlas.png?v=${ATLAS_VERSION}`,
        `/assets/battlefield/fighters/fighters-atlas.json?v=${ATLAS_VERSION}`,
      );
    }
  }

  /** @return {void} */
  create() {
    this.textures.get(TextureKey.FIGHTERS)?.setFilter(Phaser.Textures.FilterMode.NEAREST);

    this.reducedMotion = typeof window !== 'undefined' && typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    ensureSparkStreakTexture(this);
    ensureSoftGlowTexture(this);

    this.backdrop = this.add.rectangle(0, 0, 1, 1, 0x1c2438).setOrigin(0);
    this.sprite = this.add.sprite(0, 0, TextureKey.FIGHTERS);
    this.avatarGlow = this.add.image(0, 0, TextureKey.SOFTGLOW).setBlendMode('ADD').setAlpha(0.5);
    this.avatarBubble = this.add.image(0, 0, TextureKey.FIGHTERS).setVisible(false);
    this.sparks = this.add.particles(0, 0, TextureKey.SPARK_STREAK, {
      ...SPARK_EMITTER,
      angle: { onEmit: () => sparkAngle(this.sprite.flipX ? -1 : 1, Math.random) },
      speed: { onEmit: () => 120 + Math.random() * 130 },
      rotate: { onEmit: () => 0, onUpdate: p => Phaser.Math.RadToDeg(Math.atan2(p.velocityY, p.velocityX)) },
      tint: 0xffffff,
      emitting: false,
    });

    this.currentKey = null;
    this.moveset = null;
    this.avatarUrl = null;
    this._sparkWait = 0;

    this._paintBackdrop();
    this._reframe();
    this.scale.on(Phaser.Scale.Events.RESIZE, () => this._reframe());

    this.skillLoop = createSkillLoop({
      playAnimation: (skill, onComplete) => this._playSkill(skill, onComplete),
      scheduleReplay: (fn, delayMs) => this.time.delayedCall(delayMs, fn),
    });

    this.game.events.emit('preview-ready');
  }

  /**
   * Paints a static, sky-tinted backdrop for a fixed, pleasant local time —
   * decorative only, never the viewer's own live clock.
   *
   * @return {void}
   */
  _paintBackdrop() {
    const { width, height } = this.scale;
    const date = new Date();
    date.setHours(17, 30, 0, 0);
    const frame = skyFrame(date, STAGE_SITE, { width, horizonY: height * 0.7 });
    this.backdrop.setSize(width, height).setFillStyle(Phaser.Display.Color.HexStringToColor(frame.colors[1]).color);
  }

  /**
   * Re-derives every stage-relative size/position from the current game
   * box (called on boot and on every `Scale.FIT` resize of the mount) —
   * sizing while the tab is hidden reads a 0×0 box, which is skipped
   * rather than producing a division-by-zero scale.
   *
   * @return {void}
   */
  _reframe() {
    const { width, height } = this.scale;
    if (width === 0 || height === 0) {
      return;
    }
    this.centerX = width / 2;
    this.centerY = height * 0.62;
    this.scaleFactor = stageScale(width, height, FIGHTER_BODY_HEIGHT_PX);
    this.sprite.setPosition(this.centerX, this.centerY).setScale(this.scaleFactor);
    const avatar = avatarFor(this.scaleFactor);
    this.avatarBubble.setPosition(this.centerX, this.centerY - avatar.centreAbove).setDisplaySize(avatar.size, avatar.size);
    this.avatarGlow.setPosition(this.avatarBubble.x, this.avatarBubble.y).setDisplaySize(avatar.size * 1.4, avatar.size * 1.4);
    this._paintBackdrop();
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
    this._cacheHeel(ftype);
    this.currentFtype = ftype;
    this.currentKey = characterKey;
    this.moveset = buildMoveset(characterKey);
    this.sprite.setFlipX(false);
    this.skillLoop.select(this.moveset.skills[0]); // idle
  }

  /**
   * Reads `ftype`'s rear-heel point once (from its idle-frame-0 alpha) and
   * caches it on the type itself, same pattern as the live battlefield's
   * own `fighter/index.js` `cacheHeel`.
   *
   * @param {object} ftype a FIGHTER_TYPES entry
   * @return {void}
   */
  _cacheHeel(ftype) {
    if (ftype.heel) {
      return;
    }
    const frame = this.textures.getFrame(TextureKey.FIGHTERS, `${ftype.key}-idle-0`);
    if (!frame) {
      return;
    }
    const { cutX, cutY, cutWidth, cutHeight, source } = frame;
    const canvas = document.createElement('canvas');
    canvas.width = cutWidth;
    canvas.height = cutHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(source.image, cutX, cutY, cutWidth, cutHeight, 0, 0, cutWidth, cutHeight);
    ftype.heel = findHeel(ctx.getImageData(0, 0, cutWidth, cutHeight).data);
  }

  /**
   * Loads the viewer's own avatar onto the stage's avatar bubble, at
   * battlefield proportions — mirrors the live fighter's own head bubble.
   *
   * @param {number|string} fighterId
   * @param {string} avatarUrl
   * @return {void}
   */
  setAvatar(fighterId, avatarUrl) {
    if (!avatarUrl) {
      return;
    }
    loadAvatarTexture(this, fighterId, avatarUrl).then(key => {
      if (this.avatarBubble?.active) {
        this.avatarBubble.setTexture(key).setVisible(true);
      }
    }).catch(() => {});
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
    this.currentSkillId = skill.id;
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
    }
  }

  /**
   * Emits grinding heel sparks while the 'walk' skill is playing, same
   * pattern as the live battlefield's own charge sparks — skipped entirely
   * under reduced motion.
   *
   * @param {number} time
   * @param {number} delta
   * @return {void}
   */
  update(time, delta) {
    if (this.reducedMotion || this.currentSkillId !== 'walk' || !this.sprite?.active) {
      return;
    }
    this._sparkWait -= delta / 1000;
    if (this._sparkWait > 0) {
      return;
    }
    this._sparkWait = 0.05;
    const heel = this.currentFtype?.heel;
    if (!heel) {
      return;
    }
    const { dx, dy } = heelToLocal(heel, this.sprite.scaleX, this.sprite.flipX ? -1 : 1);
    this.sparks.emitParticleAt(this.sprite.x + dx, this.sprite.y + dy, 1);
  }
}
