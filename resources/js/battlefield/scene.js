import Phaser from 'phaser';
import { BG_COLOR, LAYOUTS, TIMINGS, BOSS_TYPES, FIGHTER_TYPES } from './config.js';
import { computeFighterPositions, damageScaleMultiplier, fighterDisplayConfig } from './layout.js';
import { bus } from './bus.js';
import { ATTACK_HANDLERS } from './attacks.js';
import { applyImpact } from './impact.js';
import { createLeaderboard, showMvpCard } from './leaderboard.js';
import { formatHp } from './format.js';

// Tiny RPG sprites — original 100×100 frames
// Visible character occupies rows 38-56 of the 100px frame
const SPRITE_CHAR_HEIGHT = 18;   // 56 - 38
const SPRITE_HALF_FRAME  = 50;   // 100 / 2
const SPRITE_CHAR_TOP    = 38;
const SPRITE_CHAR_BOT    = 56;

const ACTIVITY_MAX_CHARS = 18;
function truncateActivity(activity, maxChars = ACTIVITY_MAX_CHARS) {
  if (!activity || activity.length <= maxChars) {
    return activity ?? '';
  }
  return activity.slice(0, maxChars - 1) + '…';
}

const HANDLE_MAX_CHARS = 12;
function truncateHandle(handle, maxChars = HANDLE_MAX_CHARS) {
  if (!handle || handle.length <= maxChars) {
    return handle ?? '';
  }
  return handle.slice(0, maxChars - 1) + '…';
}

function handleFontPx(displaySize) {
  return Math.max(10, Math.round(displaySize * 0.25));
}

function chargeParticleColors(ftype) {
  switch (ftype) {
    case 'werewolf':
    case 'werebear':
      // purple/blue — wild feral energy
      return [0x4400aa, 0x6600cc, 0x8833dd, 0xaa55ee, 0xcc88ff];
    case 'orc':
    case 'armored-orc':
    case 'elite-orc':
    case 'orc-rider':
      // green — orcish power
      return [0x005500, 0x117700, 0x33aa00, 0x55cc11, 0x88ee44];
    case 'skeleton':
    case 'armored-skeleton':
    case 'greatsword-skeleton':
      // cold blue/white — undead
      return [0x003366, 0x0055aa, 0x1188cc, 0x44bbee, 0xaaddff];
    case 'slime':
      // acid green
      return [0x336600, 0x558800, 0x88bb00, 0xaadd00, 0xddff44];
    case 'archer':
      // golden/yellow — swift energy
      return [0x886600, 0xaa8800, 0xccaa00, 0xeecc00, 0xffee44];
    default:
      // fire orange/red — knight, axeman, soldier, swordsman, etc.
      return [0x991100, 0xcc3300, 0xdd6600, 0xee9900, 0xffbb00];
  }
}

function avatarPx(displaySize) {
  return Math.round(displaySize * 0.85);
}

export class BattlefieldScene extends Phaser.Scene {
  constructor() {
    super('battlefield');
  }

  preload() {
    // Single atlas covers all 138 fighter strips
    if (!this.textures.exists('fighters')) {
      this.load.atlas(
        'fighters',
        '/assets/battlefield/fighters/fighters-atlas.png',
        '/assets/battlefield/fighters/fighters-atlas.json',
      );
    }
    for (const boss of BOSS_TYPES) {
      if (!this.textures.exists(boss.key))
        this.load.spritesheet(boss.key, boss.file, { frameWidth: boss.frameWidth, frameHeight: boss.frameHeight });
    }
    if (!this.textures.exists('fireball'))
      this.load.spritesheet('fireball', '/assets/battlefield/fx/fireball.png', { frameWidth: 16, frameHeight: 16 });
    if (!this.textures.exists('explosion'))
      this.load.spritesheet('explosion', '/assets/battlefield/fx/explosion.png', { frameWidth: 32, frameHeight: 32 });
    const loaderBar = document.getElementById('bf-loader-bar');
    const loader    = document.getElementById('bf-loader');
    this.load.on('progress', v => { if (loaderBar) loaderBar.style.width = Math.round(v * 100) + '%'; });
    this.load.on('complete', () => {
      if (loader) loader.style.display = 'none';
      const pixelArtKeys = [...BOSS_TYPES.filter(b => b.pixelArt !== false).map(b => b.key), 'fireball', 'explosion'];
      for (const key of pixelArtKeys) {
        this.textures.get(key).setFilter(Phaser.Textures.FilterMode.NEAREST);
      }
      // Fighter atlas: NEAREST filter + register all animations from named frames
      this.textures.get('fighters')?.setFilter(Phaser.Textures.FilterMode.NEAREST);
      for (const ft of FIGHTER_TYPES) {
        for (const [state, anim] of Object.entries(ft.animations)) {
          const animKey = `${ft.key}-${state}`;
          if (!this.anims.exists(animKey)) {
            this.anims.create({
              key: animKey,
              frames: this.anims.generateFrameNames('fighters', {
                prefix: `${ft.key}-${state}-`,
                start: 0,
                end: anim.frames - 1,
              }),
              frameRate: anim.rate,
              repeat: (state === 'idle' || state === 'walk') ? -1 : 0,
            });
          }
        }
        for (let i = 0; i < (ft.attacks?.length ?? 0); i++) {
          const atk = ft.attacks[i];
          const atkKey = `${ft.key}-attack${i + 1}`;
          const effKey = `${ft.key}-effect${i + 1}`;
          if (!this.anims.exists(atkKey)) {
            this.anims.create({
              key: atkKey,
              frames: this.anims.generateFrameNames('fighters', {
                prefix: `${ft.key}-attack${i + 1}-`,
                start: 0,
                end: atk.frames - 1,
              }),
              frameRate: atk.rate,
              repeat: 0,
            });
          }
          if (atk.effectFrames && !this.anims.exists(effKey)) {
            this.anims.create({
              key: effKey,
              frames: this.anims.generateFrameNames('fighters', {
                prefix: `${ft.key}-effect${i + 1}-`,
                start: 0,
                end: atk.effectFrames - 1,
              }),
              frameRate: atk.rate,
              repeat: 0,
            });
          }
        }
      }
    });
  }

  bossTypeFor(number) {
    return BOSS_TYPES[number % BOSS_TYPES.length];
  }

  bossTextureFor(number) {
    return this.bossTypeFor(number).key;
  }

  ensureBossIdleAnim(textureKey) {
    const bossType = BOSS_TYPES.find(b => b.key === textureKey);
    const idleKey = `${textureKey}-idle`;
    if (!this.anims.exists(idleKey)) {
      this.anims.create({
        key: idleKey,
        frames: this.anims.generateFrameNumbers(textureKey, { start: bossType?.idleStart ?? 0, end: bossType?.idleEnd ?? 3 }),
        frameRate: 6,
        repeat: -1,
      });
    }
    if (bossType?.spawnStart != null && !this.anims.exists(`${textureKey}-spawn`)) {
      this.anims.create({
        key: `${textureKey}-spawn`,
        frames: this.anims.generateFrameNumbers(textureKey, { start: bossType.spawnStart, end: bossType.spawnEnd }),
        frameRate: bossType.spawnFrameRate ?? 8,
        repeat: 0,
      });
    }
    if (bossType?.attackStart != null && !this.anims.exists(`${textureKey}-attack`)) {
      this.anims.create({
        key: `${textureKey}-attack`,
        frames: this.anims.generateFrameNumbers(textureKey, { start: bossType.attackStart, end: bossType.attackEnd }),
        frameRate: bossType.attackFrameRate ?? 8,
        repeat: 0,
      });
    }
    if (bossType?.moveStart != null && !this.anims.exists(`${textureKey}-move`)) {
      this.anims.create({
        key: `${textureKey}-move`,
        frames: this.anims.generateFrameNumbers(textureKey, { start: bossType.moveStart, end: bossType.moveEnd }),
        frameRate: bossType.moveFrameRate ?? 8,
        yoyo: bossType.moveYoyo ?? false,
        repeat: -1,
      });
    }
    return idleKey;
  }

  playBossReact() {
    if (!this.bossSprite?.active) return;
    const key = this.bossSprite.texture?.key;
    const attackKey = `${key}-attack`;
    if (!this.anims.exists(attackKey)) return;
    this.bossSprite.play(attackKey);
    this.bossSprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
      if (!this.bossSprite?.active) return;
      const resumeKey = this.anims.exists(`${key}-move`) ? `${key}-move` : `${key}-idle`;
      this.bossSprite.play(resumeKey);
    });
  }

  startBossPatrol() {
    const range = 120;
    const sprite = this.bossSprite;
    const anchorY = this.layout.boss.anchor.y;
    const leftX = this.layout.boss.anchor.x - range / 2;
    const rightX = this.layout.boss.anchor.x + range / 2;
    sprite.x = leftX;
    sprite.setFlipX(true);
    const bossType = BOSS_TYPES.find(b => b.key === sprite.texture?.key);
    const moveKey = `${sprite.texture?.key}-move`;
    if (this.anims.exists(moveKey) && sprite.anims.currentAnim?.key !== moveKey) {
      const anim = this.anims.get(moveKey);
      // Start from last frame so yoyo begins by sinking — avoids jump from idle (frame 19) to move start (frame 4)
      sprite.play({ key: moveKey, startFrame: anim ? anim.frames.length - 1 : 0 });
    }
    this.tweens.add({
      targets: sprite,
      x: rightX,
      duration: 2400,
      ease: 'Sine.easeInOut',
      yoyo: true,
      repeat: -1,
      onYoyo: () => sprite.setFlipX(false),
      onRepeat: () => sprite.setFlipX(true),
    });
    if (bossType?.float) {
      const { amplitude, duration } = bossType.float;
      sprite.y = anchorY;
      this.tweens.add({
        targets: sprite,
        y: anchorY - amplitude,
        duration,
        ease: 'Sine.easeInOut',
        yoyo: true,
        repeat: -1,
      });
    }
  }

  create() {
    this.isShuttingDown = false;
    this.mode = this.game.registry.get('mode') ?? 'landscape';
    this.layout = LAYOUTS[this.mode];
    const L = this.layout;

    this.add.rectangle(L.logicalWidth / 2, L.logicalHeight / 2, L.logicalWidth, L.logicalHeight, BG_COLOR);

    this.makeSparkTexture();
    this.add.image(L.logicalWidth / 2, L.logicalHeight / 2, this.makeVignetteTexture());

    const state = this.game.registry.get('initialState');
    this.bossState = { ...state.boss };

    const initialType = this.bossTypeFor(state.boss.number);
    const initialKey = initialType.key;
    const initialAnim = this.ensureBossIdleAnim(initialKey);
    this.bossSprite = this.add
      .sprite(L.boss.anchor.x, L.boss.anchor.y, initialKey)
      .setScale(initialType.scale)
      .setDepth(5)
      .play(initialAnim);
    this.startBossPatrol();

    this.bossNameText = this.addSharpText(L.boss.name.x, L.boss.name.y, this.bossLabel(state.boss), {
      fontFamily: 'monospace',
      fontSize: '28px',
      color: '#ffffff',
      stroke: '#0f172a',
      strokeThickness: 8,
    }).setDepth(5);

    this.hpBarBg = this.add
      .rectangle(L.hpBar.x, L.hpBar.y, L.hpBar.width, L.hpBar.height, 0x334155)
      .setOrigin(0.5);

    this.hpBarFill = this.add
      .rectangle(
        L.hpBar.x - L.hpBar.width / 2,
        L.hpBar.y,
        Math.round(L.hpBar.width * (state.boss.currentHp / state.boss.maxHp)),
        L.hpBar.height,
        this.hpBarColor(state.boss.currentHp, state.boss.maxHp)
      )
      .setOrigin(0, 0.5);

    this.hpBarBorder = this.add
      .rectangle(L.hpBar.x, L.hpBar.y, L.hpBar.width, L.hpBar.height)
      .setOrigin(0.5)
      .setFillStyle()
      .setStrokeStyle(1, 0x94a3b8, 1);

    this.hpText = this.addSharpText(L.hpBar.x, L.hpBar.y + 24, `${formatHp(state.boss.currentHp)} / ${formatHp(state.boss.maxHp)}`, {
      fontFamily: 'monospace',
      fontSize: '22px',
      color: '#ffffff',
      stroke: '#0f172a',
      strokeThickness: 6,
    }, 3);

    this.fighters = new Map();
    this.damageTotals = new Map();
    const config = fighterDisplayConfig(state.fighters.length, this.mode);
    const positions = computeFighterPositions(
      state.fighters.length,
      L.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );
    state.fighters.forEach((f, i) => this.addFighter(f, positions[i], config));

    // Restore damage-based fighter sizes captured by snapshotState on reboot
    for (const [userId, damage] of state.damageTotals ?? []) {
      this.damageTotals.set(userId, damage);
      this.rescaleFighterByDamage(userId);
    }

    this.leaderboard = createLeaderboard(this);
    this.leaderboard.seed(state.leaderboard ?? []);

    this.charges = new Map();
    // Synthesizes the live `fighter-charging` payload shape — keep in sync with FighterCharging::broadcastWith().
    for (const f of state.fighters) {
      if (f.charging) {
        this.handleCharging({
          user_id: f.id,
          activity: f.charging.activity,
        });
      }
    }
    this._busHandlers = {
      'hit': payload => this.handleHit(payload),
      'boss-spawned': payload => this.handleBossSpawned(payload),
      'boss-killed': payload => this.handleBossKilled(payload),
      'fighter-charging': payload => this.handleCharging(payload),
      'fighter-idled': payload => this.handleIdled(payload),
      'fighter-joined': payload => this.handleFighterJoined(payload),
    };
    for (const [evt, fn] of Object.entries(this._busHandlers)) {
      bus.on(evt, fn);
    }
    this.events.once('shutdown', () => {
      this.isShuttingDown = true;
      for (const [evt, fn] of Object.entries(this._busHandlers)) {
        bus.off(evt, fn);
      }
      this.leaderboard?.destroy?.();
    });

    this.events.emit('ready');
    this.game.events.emit('ready');
  }

  bossLabel(boss) {
    const name = boss?.name;
    if (typeof name === 'string' && name.length > 0) {
      return name.toUpperCase();
    }
    return `BOSS #${boss?.number ?? '?'}`;
  }

  addSharpText(x, y, content, style, resolution = 2) {
    const text = this.add.text(x, y, content, style).setOrigin(0.5).setResolution(resolution);
    text.texture.setFilter(Phaser.Textures.FilterMode.LINEAR);
    const originalSetText = text.setText.bind(text);
    text.setText = (...args) => {
      const result = originalSetText(...args);
      text.texture.setFilter(Phaser.Textures.FilterMode.LINEAR);
      return result;
    };
    return text;
  }

  hpBarColor(current, max) {
    const pct = current / max;
    if (pct > 0.5) return 0x22c55e;
    if (pct > 0.25) return 0xf59e0b;
    return 0xef4444;
  }

  makeVignetteTexture() {
    const { logicalWidth: W, logicalHeight: H } = this.layout;
    const key = `bf-vignette-${this.mode}`;
    if (!this.textures.exists(key)) {
      const canvas = document.createElement('canvas');
      canvas.width = W;
      canvas.height = H;
      const ctx = canvas.getContext('2d');
      const grad = ctx.createRadialGradient(W / 2, H / 2, H * 0.18, W / 2, H / 2, H * 0.88);
      grad.addColorStop(0, 'rgba(0,0,0,0)');
      grad.addColorStop(1, 'rgba(0,0,0,0.62)');
      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, W, H);
      this.textures.addCanvas(key, canvas);
    }
    return key;
  }

  makeSparkTexture() {
    if (this.textures.exists('spark')) {
      return;
    }
    const g = this.make.graphics({ add: false });
    g.fillStyle(0xffffff, 1);
    // Thin triangle — tip at right (24,3), base at left
    g.fillTriangle(24, 3, 0, 0, 0, 6);
    g.generateTexture('spark', 24, 6);
    g.destroy();
  }

  handleHit(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    this.clearCharge(payload.user_id);
    const fighter = this.fighters.get(payload.user_id);
    const key     = fighter?.ftype?.key ?? null;
    const attacks = fighter?.ftype?.attacks ?? null;
    const pickIdx = attacks?.length ? Phaser.Math.Between(0, attacks.length - 1) : -1;
    const flipTowardBoss = fighter ? fighter.pos.x > this.layout.boss.anchor.x : false;
    if (fighter?.body) {
      const atkAnimKey = pickIdx >= 0 ? `${key}-attack${pickIdx + 1}` : `${key}-attack`;
      fighter.animState = 'attack';
      fighter.body.off(Phaser.Animations.Events.ANIMATION_COMPLETE);
      fighter.body.setFlipX(flipTowardBoss);
      fighter.body.play(atkAnimKey);

      fighter.body.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        if (!fighter.body?.scene) return;
        const next = this.charges.has(fighter.id) ? 'walk' : 'idle';
        fighter.animState = next;
        fighter.body.setFlipX(next === 'walk' ? flipTowardBoss : false);
        fighter.body.play(`${key}-${next}`);
      });
    }
    const isKillShot = (payload.boss_hp_after ?? 1) <= 0;
    if (payload.damage > 0 && fighter) {
      const prev = this.damageTotals.get(payload.user_id) ?? 0;
      this.damageTotals.set(payload.user_id, prev + payload.damage);
      // Update the canonical rest scale now so the attack animation about to
      // run settles onto it; the visual grow tween itself stays delayed.
      fighter.damageScale = damageScaleMultiplier(prev + payload.damage, this.bossState?.maxHp);
      this.time.delayedCall(isKillShot ? 720 : 120, () => {
        this.rescaleFighterByDamage(payload.user_id);
      });
    }
    const onImpact = () => {
      this.leaderboard?.onHit(payload.user_id, payload.damage, payload.slack_handle);
      applyImpact(this, payload.boss_hp_after);
      if (this.hoveredUserId === payload.user_id) {
        this.showFighterTooltip(payload.user_id);
      }
      if (!isKillShot) this.time.delayedCall(90, () => this.playBossReact());
    };
    if (fighter) {
      const attackType = fighter.ftype?.attackType ?? 'blast';
      const handler = ATTACK_HANDLERS[attackType] ?? ATTACK_HANDLERS.blast;
      const effKey = (pickIdx >= 0 && attacks?.[pickIdx]?.effectFrames) ? `${key}-effect${pickIdx + 1}` : null;
      const onEffect = effKey ? (x, y) => {
        if (!fighter.body?.scene) return;
        const eff = this.add.sprite(x, y, 'fighters', `${effKey}-0`)
          .setScale(fighter.sprite.scaleX * fighter.body.scaleX)
          .setFlipX(flipTowardBoss)
          .setBlendMode(Phaser.BlendModes.ADD)
          .setDepth(3)
          .play(effKey);
        eff.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => eff.destroy());
      } : null;
      handler(this, fighter, {
        isKillShot,
        damage: payload.damage,
        maxHp: this.bossState?.maxHp ?? 1,
        onImpact,
        onEffect,
      });
    } else {
      this.time.delayedCall(TIMINGS.projectileArcMs, onImpact);
    }
  }

  rescaleFighterByDamage(userId) {
    const fighter = this.fighters.get(userId);
    if (!fighter) return;
    fighter.damageScale = damageScaleMultiplier(this.damageTotals.get(userId) ?? 0, this.bossState?.maxHp);
    this.tweenToRestScale(fighter);
  }

  fighterRestScale(fighter) {
    return (fighter.displaySize / fighter.baseSize) * (fighter.damageScale ?? 1);
  }

  /**
   * Tween the fighter toward its canonical rest scale without killing other
   * tweens. If an attack animation currently owns the sprite's scale, skip —
   * the attack's own return tween reads fighterRestScale() and settles there.
   */
  tweenToRestScale(fighter, { duration = 600, ease = 'Back.easeOut' } = {}) {
    fighter.rescaleTween?.remove();
    fighter.rescaleTween = null;
    const attackOwnsScale = this.tweens.getTweensOf(fighter.sprite)
      .some(tw => tw.data?.some(d => d.key === 'scaleX' || d.key === 'scaleY'));
    if (attackOwnsScale) {
      return;
    }
    const target = this.fighterRestScale(fighter);
    fighter.rescaleTween = this.tweens.add({
      targets: fighter.sprite,
      scaleX: target,
      scaleY: target,
      duration,
      ease,
      onComplete: () => { fighter.rescaleTween = null; },
    });
  }

  handleBossSpawned(payload) {
    if (!payload || payload.boss_number == null || payload.max_hp == null) {
      return;
    }
    this.clearAllCharges();
    const L = this.layout;
    const oldSprite = this.bossSprite;
    this.tweens.killTweensOf(oldSprite);
    this.tweens.add({
      targets: oldSprite,
      alpha: 0,
      duration: 200,
      onComplete: () => oldSprite.destroy(),
    });

    const bossType = this.bossTypeFor(payload.boss_number);
    const textureKey = bossType.key;
    const idleKey = this.ensureBossIdleAnim(textureKey);
    const spawnKey = `${textureKey}-spawn`;

    if (this.anims.exists(spawnKey)) {
      // Shadow: rise from ground in place — no drop tween
      this.bossSprite = this.add
        .sprite(L.boss.anchor.x, L.boss.anchor.y, textureKey)
        .setScale(bossType.scale)
        .setDepth(5)
        .play(spawnKey);
      this.bossSprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        if (this.bossSprite?.active) {
          this.bossSprite.play(idleKey);
          this.startBossPatrol();
        }
      });
    } else {
      // Other bosses: drop from above with bounce
      this.bossSprite = this.add
        .sprite(L.boss.anchor.x, -40, textureKey)
        .setScale(bossType.scale)
        .setDepth(5)
        .play(idleKey);
      this.tweens.add({
        targets: this.bossSprite,
        y: L.boss.anchor.y,
        duration: TIMINGS.bossSpawnMs,
        ease: 'Bounce.easeOut',
        onComplete: () => this.startBossPatrol(),
      });
    }

    this.bossState = {
      currentHp: payload.max_hp,
      maxHp: payload.max_hp,
      number: payload.boss_number,
      name: payload.boss_name,
    };
    this.bossNameText.setText(this.bossLabel(this.bossState));
    this.hpBarFill.width = L.hpBar.width;
    this.hpBarFill.setFillStyle(0x22c55e);
    this.hpText.setText(`${formatHp(payload.max_hp)} / ${formatHp(payload.max_hp)}`);
    this.leaderboard?.reset();
    // Reset damage totals and fighter sizes for new boss
    this.damageTotals.clear();
    for (const [, f] of this.fighters.entries()) {
      f.damageScale = 1;
      this.tweenToRestScale(f, { duration: 400, ease: 'Quad.easeOut' });
    }
  }

  handleBossKilled(payload = {}) {
    this.clearAllCharges();
    if (this.bossSprite) {
      this.tweens.add({
        targets: this.bossSprite,
        scale: 0,
        alpha: 0,
        angle: 360,
        duration: TIMINGS.bossKilledMs,
        ease: 'Quad.easeIn',
      });
      this.cameras.main.flash(400, 255, 255, 255);
    }
    if (this.leaderboard) {
      showMvpCard(this, {
        bossLabel: this.bossLabel({
          name: payload.boss_name ?? this.bossState.name,
          number: payload.boss_number ?? this.bossState.number,
        }),
        ranked: this.leaderboard.getRanked(),
        killerHandle: payload.killer_slack_handle ?? null,
      });
    }
  }

  handleCharging(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    if (!this.fighters.has(payload.user_id)) {
      this.handleFighterJoined({
        user_id: payload.user_id,
        slack_handle: payload.slack_handle,
        avatar_url: payload.avatar_url,
        character: payload.character ?? null,
      });
    }
    const fighter = this.fighters.get(payload.user_id);
    if (!fighter) {
      return;
    }
    const existing = this.charges.get(payload.user_id);
    if (existing) {
      existing.activity = payload.activity ?? '';
      if (this.fightersAllowBubbles()) {
        this.updateActivityBubble(existing, fighter, payload.activity);
      }
      return;
    }
    if (fighter.body && fighter.animState !== 'attack') {
      fighter.animState = 'walk';
      fighter.body.setFlipX(fighter.pos.x > this.layout.boss.anchor.x);
      fighter.body.play(fighter.ftype.key + '-walk');
    }
    const localFootY = Math.round(fighter.displaySize / 3);
    const { fireEmitter, fireEmbers } = this.spawnChargeFireEmitters(fighter.ftype, 0, localFootY, fighter.displaySize);
    fighter.sprite.addAt(fireEmbers, 0);
    fighter.sprite.addAt(fireEmitter, 0);
    const ring  = this.createChargingRing(fighter);
    const trail = this.createChargingTrail(fighter);
    fighter.sprite.addAt(ring, 0);
    const avSize = fighter.avatarSize ?? avatarPx(fighter.displaySize);
    const breath = fighter.head ? this.tweens.add({
      targets: fighter.head,
      displayWidth: avSize * 1.06,
      displayHeight: avSize * 1.06,
      duration: TIMINGS.chargeRingPulseMs / 2,
      yoyo: true,
      repeat: -1,
      ease: 'Sine.easeInOut',
    }) : null;
    const entry = { ring, trail, fireEmitter, fireEmbers, breath, bubble: null, activity: payload.activity ?? '' };
    if (this.fightersAllowBubbles()) {
      this.updateActivityBubble(entry, fighter, payload.activity);
    }
    this.charges.set(payload.user_id, entry);
  }

  createChargingRing(fighter) {
    const avatarRelY = fighter.head?.y ?? 0;
    const avR = (fighter.avatarSize ?? avatarPx(fighter.displaySize)) / 2;
    const r   = Math.round(avR + Math.max(4, fighter.displaySize * 0.08));
    const g = this.add.graphics();
    g.lineStyle(2, 0x22d3ee, 1);
    g.strokeCircle(0, 0, r);
    g.setPosition(0, avatarRelY);
    this.tweens.add({
      targets: g,
      alpha: { from: 0.9, to: 0.15 },
      scaleX: { from: 1.0, to: 1.18 },
      scaleY: { from: 1.0, to: 1.18 },
      duration: TIMINGS.chargeRingPulseMs,
      ease: 'Sine.easeInOut',
      yoyo: true,
      repeat: -1,
    });
    return g;
  }

  createChargingTrail(fighter) {
    // Particles drift opposite to boss direction — visual speed trail while walking in place
    const charBot    = Math.round(fighter.displaySize / 3);  // char bottom from container center
    const towardBoss = fighter.pos.x <= this.layout.boss.anchor.x ? 1 : -1;
    const emitX      = fighter.pos.x - towardBoss * Math.round(fighter.displaySize * 0.18);
    const emitY      = fighter.pos.y + charBot - Math.round(fighter.displaySize * 0.12);
    const emitter    = this.add.particles(emitX, emitY, 'spark', {
      tint:      { onEmit: () => Phaser.Math.RND.pick([0x4ade80, 0x86efac, 0xa3e635, 0xffffff]) },
      scale:     { start: 0.5, end: 0 },
      alpha:     { start: 0.65, end: 0 },
      speedX:    { min: -towardBoss * 80, max: -towardBoss * 20 },
      speedY:    { min: -10, max: 22 },
      lifespan:  { min: 130, max: 230 },
      frequency: 45,
      quantity:  2,
      blendMode: Phaser.BlendModes.ADD,
    });
    emitter.setDepth(1);
    return emitter;
  }

  spawnChargeFireEmitters(ftype, localX, localFootY, displaySize) {
    const fireColors = chargeParticleColors(ftype);
    const ps = displaySize * 0.018;
    const fireEmitter = this.add.particles(localX, localFootY, 'spark', {
      tint:      { onEmit: () => Phaser.Math.RND.pick(fireColors) },
      rotate:    { min: 0, max: 360 },
      scale:     { start: ps, end: 0 },
      alpha:     { start: 0.55, end: 0 },
      speedX:    { min: -180, max: 180 },
      speedY:    { min: -90, max: 20 },
      lifespan:  { min: 280, max: 480 },
      frequency: 22,
      quantity:  3,
      gravityY:  80,
      blendMode: Phaser.BlendModes.ADD,
    });
    const fireEmbers = this.add.particles(localX, localFootY, 'spark', {
      tint:      { onEmit: () => Phaser.Math.RND.pick(fireColors) },
      rotate:    { min: 0, max: 360 },
      scale:     { start: ps * 0.5, end: 0 },
      alpha:     { start: 0.4, end: 0 },
      speedX:    { min: -240, max: 240 },
      speedY:    { min: -60, max: 30 },
      lifespan:  { min: 200, max: 400 },
      frequency: 28,
      quantity:  2,
      gravityY:  60,
      blendMode: Phaser.BlendModes.ADD,
    });
    return { fireEmitter, fireEmbers };
  }

  fightersAllowBubbles() {
    return fighterDisplayConfig(this.fighters.size, this.mode).showHandle;
  }

  activityBubbleY(fighter) {
    const avatarRelY  = fighter.head?.y ?? 0;
    const avatarRadius = (fighter.head?.displayHeight ?? 28) / 2;
    return fighter.pos.y + avatarRelY - avatarRadius - 28;
  }

  updateActivityBubble(entry, fighter, activity) {
    if (!activity) {
      if (entry.bubble) {
        entry.bubble.destroy();
        entry.bubble = null;
      }
      return;
    }
    if (entry.bubble) {
      entry.bubble.setActivity(activity);
    } else {
      const bubbleY  = this.activityBubbleY(fighter);
      const fontPx   = Math.max(9, Math.round(fighter.displaySize * 0.22));
      const maxChars = Math.max(12, Math.round(fighter.displaySize * 0.35));
      entry.bubble = this.createActivityBubble(fighter.pos.x, bubbleY, activity, fontPx, maxChars);
    }
    // The hover tooltip takes priority over the activity bubble.
    if (this.hoveredUserId === fighter.id) {
      entry.bubble.setVisible(false);
    }
  }

  createActivityBubble(x, y, activity, fontPx = 14, maxChars = ACTIVITY_MAX_CHARS) {
    const text = this.addSharpText(x, y, truncateActivity(activity, maxChars), {
      fontFamily: 'monospace',
      fontSize: `${fontPx}px`,
      color: '#f1f5f9',
      padding: { left: 8, right: 8, top: 4, bottom: 4 },
    });
    const bg = this.add
      .rectangle(x, y, text.width + 8, text.height + 4, 0x1e293b, 0.92)
      .setOrigin(0.5)
      .setStrokeStyle(1, 0x64748b, 0.9);
    bg.setDepth(100);
    text.setDepth(101);
    return {
      destroy: () => {
        text.destroy();
        bg.destroy();
      },
      setActivity: newActivity => {
        text.setText(truncateActivity(newActivity));
        bg.setSize(text.width + 8, text.height + 4);
      },
      moveTo: (newX, newY) => {
        text.x = newX;
        text.y = newY;
        bg.x = newX;
        bg.y = newY;
      },
      setVisible: visible => {
        text.setVisible(visible);
        bg.setVisible(visible);
      },
    };
  }

  showFighterTooltip(userId) {
    const fighter = this.fighters.get(userId);
    if (!fighter) {
      return;
    }
    const tokens = this.leaderboard?.damageFor(userId) ?? 0;
    const rank = this.leaderboard?.rankOf(userId) ?? null;
    const handle = fighter.handleText || `#${userId}`;
    const rankPrefix = rank ? `#${rank} ` : '';
    const content = `${rankPrefix}${handle} · ${tokens.toLocaleString()} tokens`;
    const fontPx = Math.max(9, Math.round(fighter.displaySize * 0.22));

    if (!this.tooltip) {
      this.tooltip = this.createFighterTooltip(content, fontPx);
    } else {
      this.tooltip.setContent(content, fontPx);
    }

    const margin = 4;
    const halfW = this.tooltip.width() / 2;
    const halfH = this.tooltip.height() / 2;
    const x = Phaser.Math.Clamp(
      fighter.pos.x,
      halfW + margin,
      this.layout.logicalWidth - halfW - margin,
    );
    // Anchor to the same spot as the activity bubble so the tooltip lines up
    // with (and cleanly replaces) the bubble it covers.
    const aboveY = this.activityBubbleY(fighter);
    const avatarCenterY = fighter.pos.y + (fighter.head?.y ?? 0);
    const avatarRadius = (fighter.head?.displayHeight ?? fighter.avatarSize ?? fighter.displaySize) / 2;
    // Flip below the avatar when the tooltip would clip past the top edge.
    const y = aboveY - halfH < margin
      ? avatarCenterY + avatarRadius + halfH + 6
      : aboveY;

    this.tooltip.moveTo(x, y);
    this.tooltip.setVisible(true);
    this.hoveredUserId = userId;

    // Hide the "thinking" activity bubble so it doesn't collide with the tooltip.
    const charge = this.charges.get(userId);
    if (charge?.bubble) {
      charge.bubble.setVisible(false);
    }
  }

  hideFighterTooltip(userId) {
    if (userId != null && this.hoveredUserId !== userId) {
      return;
    }
    const previous = this.hoveredUserId;
    this.hoveredUserId = null;
    if (this.tooltip) {
      this.tooltip.setVisible(false);
    }
    // Restore the activity bubble the tooltip was covering, if still charging.
    if (previous != null) {
      const charge = this.charges.get(previous);
      if (charge?.bubble) {
        charge.bubble.setVisible(true);
      }
    }
  }

  createFighterTooltip(content, fontPx = 14) {
    // Mirror the activity bubble's geometry (font scale, padding, box sizing) so
    // the tooltip lines up exactly with the bubble it temporarily replaces.
    const text = this.addSharpText(0, 0, content, {
      fontFamily: 'monospace',
      fontSize: `${fontPx}px`,
      color: '#fde68a',
      padding: { left: 8, right: 8, top: 4, bottom: 4 },
    });
    const bg = this.add
      .rectangle(0, 0, text.width + 8, text.height + 4, 0x1e293b, 0.96)
      .setOrigin(0.5)
      .setStrokeStyle(1, 0xfbbf24, 0.9);
    bg.setDepth(300);
    text.setDepth(301);
    const tooltip = {
      setContent: (newContent, newFontPx = fontPx) => {
        text.setFontSize(newFontPx);
        text.setText(newContent);
        bg.setSize(text.width + 8, text.height + 4);
      },
      moveTo: (x, y) => {
        text.x = x;
        text.y = y;
        bg.x = x;
        bg.y = y;
      },
      setVisible: visible => {
        text.setVisible(visible);
        bg.setVisible(visible);
      },
      width: () => bg.width,
      height: () => bg.height,
    };
    tooltip.setVisible(false);
    return tooltip;
  }

  handleIdled(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    const userId = payload.user_id;
    this.clearCharge(userId);
    this.removeFighter(userId);
  }

  removeFighter(userId) {
    const entry = this.fighters.get(userId);
    if (!entry) {
      return;
    }
    this.fighters.delete(userId);
    this.tweens.add({
      targets: entry.sprite,
      alpha: 0,
      duration: 300,
      onComplete: () => { if (entry.sprite?.scene) entry.sprite.destroy(); },
    });
    if (entry.handle?.scene) {
      this.tweens.add({
        targets: entry.handle,
        alpha: 0,
        duration: 300,
        onComplete: () => { if (entry.handle?.scene) entry.handle.destroy(); },
      });
    }
    this.relayoutFighters();
  }

  clearCharge(userId) {
    const entry = this.charges.get(userId);
    if (!entry) {
      return;
    }
    if (entry.breath) {
      entry.breath.stop();
      const fighter = this.fighters.get(userId);
      if (fighter?.head?.scene) {
        const av = fighter.avatarSize ?? avatarPx(fighter.displaySize);
        fighter.head.setDisplaySize(av, av);
      }
    }
    if (entry.ring?.scene) {
      this.tweens.killTweensOf(entry.ring);
      const ring = entry.ring;
      this.tweens.add({
        targets: ring,
        alpha: 0,
        duration: 200,
        onComplete: () => { if (ring.scene) ring.destroy(); },
      });
    }
    if (entry.trail?.scene) {
      entry.trail.stop();
      this.time.delayedCall(250, () => { if (entry.trail?.scene) entry.trail.destroy(); });
    }
    if (entry.fireEmitter?.scene) {
      entry.fireEmitter.stop();
      this.time.delayedCall(500, () => { if (entry.fireEmitter?.scene) entry.fireEmitter.destroy(); });
    }
    if (entry.fireEmbers?.scene) {
      entry.fireEmbers.stop();
      this.time.delayedCall(500, () => { if (entry.fireEmbers?.scene) entry.fireEmbers.destroy(); });
    }
    if (entry.bubble) {
      entry.bubble.destroy();
      entry.bubble = null;
    }
    this.charges.delete(userId);
    const fighter = this.fighters.get(userId);
    if (fighter?.body && fighter.animState !== 'attack') {
      fighter.animState = 'idle';
      fighter.body.setFlipX(false);
      fighter.body.play(fighter.ftype.key + '-idle');
    }
  }

  clearAllCharges() {
    for (const userId of [...this.charges.keys()]) {
      this.clearCharge(userId);
    }
  }

  relayoutFighters() {
    const count = this.fighters.size;
    if (count === 0) {
      return;
    }
    const config = fighterDisplayConfig(count, this.mode);
    const positions = computeFighterPositions(
      count,
      this.layout.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );

    let i = 0;
    for (const [userId, entry] of this.fighters.entries()) {
      const target = positions[i++];
      const newSize = config.displaySize;
      const sizeChanged = newSize !== entry.displaySize;

      this.tweens.add({
        targets: entry.sprite,
        x: target.x,
        y: target.y,
        duration: 200,
        ease: 'Quad.easeOut',
      });

      if (sizeChanged) {
        entry.displaySize = newSize;
        entry.sprite.setScale(this.fighterRestScale(entry));
      }

      const scale   = entry.sprite.scaleX;
      const fontPx  = handleFontPx(newSize);
      const maxChrs = Math.max(8, Math.round(newSize * 0.22));
      const handleY = target.y + entry.legH * scale + fontPx;
      if (config.showHandle && !entry.handle) {
        entry.handle = this.addSharpText(target.x, handleY, truncateHandle(entry.handleText, maxChrs), {
          fontFamily: 'monospace',
          fontSize: `${fontPx}px`,
          color: '#fbbf24',
        });
      } else if (!config.showHandle && entry.handle) {
        entry.handle.destroy();
        entry.handle = null;
      } else if (entry.handle) {
        this.tweens.add({
          targets: entry.handle,
          x: target.x,
          y: handleY,
          duration: 200,
          ease: 'Quad.easeOut',
        });
      }

      entry.pos = target;

      const charge = this.charges.get(userId);
      if (charge) {
        // Ring is inside the container at (0,0) — rebuild if size changed
        if (sizeChanged && charge.ring?.scene) {
          this.tweens.killTweensOf(charge.ring);
          charge.ring.destroy();
          charge.ring = this.createChargingRing(entry);
          entry.sprite.addAt(charge.ring, 0);
        }
        // Trail is world-space — rebuild on size change, reposition otherwise
        if (sizeChanged && charge.trail?.scene) {
          charge.trail.stop();
          charge.trail.destroy();
          charge.trail = this.createChargingTrail(entry);
        } else if (charge.trail?.scene) {
          const tb = entry.pos.x <= this.layout.boss.anchor.x ? 1 : -1;
          const cb = Math.round(entry.displaySize / 3);
          charge.trail.setPosition(
            target.x - tb * Math.round(entry.displaySize * 0.18),
            target.y + cb - Math.round(entry.displaySize * 0.12),
          );
        }
        if (charge.bubble) {
          const avatarRelY   = entry.head?.y ?? 0;
          const avatarRadius = (entry.head?.displayHeight ?? 28) / 2;
          charge.bubble.moveTo(target.x, target.y + avatarRelY - avatarRadius - 16);
        }
      }
    }

    if (this.hoveredUserId != null) {
      this.showFighterTooltip(this.hoveredUserId);
    }
  }

  loadAvatarTexture(fighterId, avatarUrl) {
    const key = `fighter-${fighterId}`;
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = () => {
        if (this.isShuttingDown) {
          reject(new Error('scene destroyed before avatar load'));
          return;
        }
        if (this.textures.exists(key)) {
          this.textures.remove(key);
        }
        // Use native image size — no intermediate downscale step
        const size = img.naturalWidth || 512;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.beginPath();
        ctx.arc(size / 2, size / 2, size / 2, 0, Math.PI * 2);
        ctx.clip();
        ctx.drawImage(img, 0, 0, size, size);
        this.textures.addCanvas(key, canvas);
        this.textures.get(key).setFilter(Phaser.Textures.FilterMode.LINEAR);
        resolve(key);
      };
      img.onerror = () => reject(new Error(`avatar load failed: ${avatarUrl}`));
      img.src = avatarUrl;
    });
  }

  makeFallbackAvatarTexture(fighter) {
    const key = `fighter-${fighter.id}-fallback`;
    if (this.textures.exists(key)) {
      return key;
    }
    const size = 128;
    const radius = size / 2;
    const palette = [0x6366f1, 0x10b981, 0xf59e0b, 0xec4899, 0x14b8a6, 0xf97316, 0x8b5cf6, 0x0ea5e9];
    const color = palette[Math.abs(Number(fighter.id) || 0) % palette.length];
    const initial = (fighter.handle ?? '').trim().charAt(0).toUpperCase() || '?';

    const rt = this.add.renderTexture(0, 0, size, size).setVisible(false);
    const circle = this.add.graphics({ x: 0, y: 0 }).setVisible(false);
    circle.fillStyle(color, 1);
    circle.fillCircle(radius, radius, radius);
    rt.draw(circle, 0, 0);
    const label = this.add.text(0, 0, initial, {
      fontFamily: 'monospace',
      fontSize: '72px',
      color: '#ffffff',
    }).setOrigin(0.5).setVisible(false);
    rt.draw(label, radius, radius);
    rt.saveTexture(key);
    circle.destroy();
    label.destroy();
    rt.destroy();
    this.textures.get(key).setFilter(Phaser.Textures.FilterMode.LINEAR);
    return key;
  }

  handleFighterJoined(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    if (this.fighters.has(payload.user_id)) {
      return;
    }
    const fighter = {
      id: payload.user_id,
      handle: payload.slack_handle,
      display_name: payload.display_name ?? null,
      character: payload.character ?? null,
    };

    const count = this.fighters.size + 1;
    const config = fighterDisplayConfig(count, this.mode);
    const positions = computeFighterPositions(
      count,
      this.layout.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );

    const newPos = positions[positions.length - 1];
    this.addFighter(fighter, newPos, config);
    this.relayoutFighters();

    const entry = this.fighters.get(fighter.id);
    if (!entry) {
      return;
    }
    const finalScale = entry.sprite.scaleX;
    entry.sprite.setScale(0);
    this.tweens.add({
      targets: entry.sprite,
      scale: finalScale,
      duration: TIMINGS.fighterJoinMs,
      ease: 'Back.easeOut',
    });
  }

  addFighter(fighter, pos, options = {}) {
    const size = options.displaySize ?? 48;

    // Pick character type by fighter.character key, fall back to id modulo
    const ftypeKey = fighter.character ?? null;
    const ftype = (ftypeKey && FIGHTER_TYPES.find(ft => ft.key === ftypeKey))
      ?? FIGHTER_TYPES[Math.abs(Number(fighter.id) || 0) % FIGHTER_TYPES.length];
    // Scale so the visible character (18px of the 100px frame) fills `size` logical px
    const scale     = size / SPRITE_CHAR_HEIGHT;
    const legH      = Math.round((SPRITE_CHAR_BOT - SPRITE_HALF_FRAME) * scale);
    const avatarY   = -Math.round((SPRITE_HALF_FRAME - SPRITE_CHAR_TOP) * scale) - 38;
    const avSize    = avatarPx(size);
    const fontPx    = handleFontPx(size);
    const maxChars  = Math.max(8, Math.round(size * 0.22));
    const displayName = fighter.display_name || fighter.handle || fighter.slack_handle || '';

    const container = this.add.container(pos.x, pos.y).setDepth(2);

    // Body sprite — starts in idle animation (waiting state)
    const body = this.add.sprite(0, 0, 'fighters', `${ftype.key}-idle-0`).setScale(scale);
    const idleAnim = this.anims.get(ftype.key + '-idle');
    if (idleAnim?.frames?.length) {
      body.play(ftype.key + '-idle');
    }
    container.add(body);
    const avatarUrl = fighter.id ? `/avatars/${fighter.id}?v=${Date.now()}` : null;
    const initialKey = this.textures.exists(`fighter-${fighter.id}`)
      ? `fighter-${fighter.id}`
      : this.makeFallbackAvatarTexture(fighter);
    const head = this.add.image(0, avatarY, initialKey).setDisplaySize(avSize, avSize);
    head.setInteractive({ useHandCursor: true });
    head.on('pointerover', () => this.showFighterTooltip(fighter.id));
    head.on('pointerout', () => this.hideFighterTooltip(fighter.id));
    container.add(head);

    // Handle label (world-space)
    const handle = options.showHandle === false
      ? null
      : this.addSharpText(pos.x, pos.y + legH + fontPx, truncateHandle(displayName, maxChars), {
          fontFamily: 'monospace',
          fontSize: `${fontPx}px`,
          color: '#fbbf24',
        });

    this.fighters.set(fighter.id, {
      id: fighter.id,
      sprite: container,
      body,
      head,
      handle,
      handleText: displayName,
      pos,
      baseSize: size,
      displaySize: size,
      avatarSize: avSize,
      avatarUrl,
      legH,
      ftype,
      damageScale: 1,
      animState: 'idle',
    });

    if (initialKey !== `fighter-${fighter.id}` && avatarUrl) {
      this.loadAvatarTexture(fighter.id, avatarUrl).then(realKey => {
        if (head.scene) head.setTexture(realKey).setDisplaySize(avSize, avSize);
      }).catch(e => console.warn('[battlefield]', e.message));
    }
  }
}
