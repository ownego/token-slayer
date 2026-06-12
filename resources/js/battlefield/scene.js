import Phaser from 'phaser';
import { BG_COLOR, LAYOUTS, TIMINGS, BOSS_TYPES, FIGHTER_TYPES } from './config.js';
import { chargeFootY, computeFighterPositions, damageScaleMultiplier, fighterDisplayConfig } from './layout.js';
import { bus } from './bus.js';
import { ATTACK_HANDLERS } from './attacks.js';
import { applyImpact } from './impact.js';
import { createLeaderboard, showMvpCard } from './leaderboard.js';
import { formatHp } from './format.js';

const ACTIVITY_MAX_CHARS = 18;
function truncateActivity(activity) {
  if (!activity || activity.length <= ACTIVITY_MAX_CHARS) {
    return activity ?? '';
  }
  return activity.slice(0, ACTIVITY_MAX_CHARS - 1) + '…';
}

const HANDLE_MAX_CHARS = 6;
function truncateHandle(handle) {
  if (!handle || handle.length <= HANDLE_MAX_CHARS) {
    return handle ?? '';
  }
  return handle.slice(0, HANDLE_MAX_CHARS - 1) + '…';
}

function chargeParticleColors(ftype) {
  if (ftype?.key === 'shinobi') {
    return [0x4c1d95, 0x7c3aed, 0xa855f7, 0xc4b5fd, 0x8b5cf6];
  }
  return [0x991100, 0xcc3300, 0xdd6600, 0xee9900, 0xffbb00];
}

export class BattlefieldScene extends Phaser.Scene {
  constructor() {
    super('battlefield');
  }

  preload() {
    for (const ft of FIGHTER_TYPES) {
      if (!this.textures.exists(ft.key + '-idle'))
        this.load.spritesheet(ft.key + '-idle', ft.idleFile, { frameWidth: ft.frameWidth, frameHeight: ft.frameHeight });
      if (!this.textures.exists(ft.key + '-run'))
        this.load.spritesheet(ft.key + '-run',  ft.runFile,  { frameWidth: ft.runFrameWidth ?? ft.frameWidth, frameHeight: ft.frameHeight });
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
      // Fighter sheets are high-res (256px frames) drawn well below native
      // size — default LINEAR filtering keeps them smooth, NEAREST would alias.
      for (const ft of FIGHTER_TYPES) {
        if (!this.anims.exists(ft.key + '-idle')) {
          this.anims.create({
            key: ft.key + '-idle',
            frames: this.anims.generateFrameNumbers(ft.key + '-idle', { start: 0, end: ft.idleFrames - 1 }),
            frameRate: 8,
            repeat: -1,
          });
        }
        if (!this.anims.exists(ft.key + '-run')) {
          this.anims.create({
            key: ft.key + '-run',
            frames: this.anims.generateFrameNumbers(ft.key + '-run', { start: 0, end: ft.runFrames - 1 }),
            frameRate: 10,
            repeat: -1,
          });
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
        0xef4444
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
      if (!isKillShot) this.time.delayedCall(90, () => this.playBossReact());
    };
    if (fighter) {
      const attackType = fighter.ftype?.attackType ?? 'blast';
      const handler = ATTACK_HANDLERS[attackType] ?? ATTACK_HANDLERS.blast;
      handler(this, fighter, {
        isKillShot,
        damage: payload.damage,
        maxHp: this.bossState?.maxHp ?? 1,
        onImpact,
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
    // Switch to run animation while charging
    if (fighter.body) {
      fighter.body.setTexture(fighter.ftype.key + '-run');
      fighter.body.play(fighter.ftype.key + '-run');
      fighter.body.setFlipX(
        (fighter.pos.x > this.layout.boss.anchor.x) !== (fighter.ftype?.baseFlipX ?? false),
      );
    }
    const localFootY = chargeFootY(0, fighter.displaySize) + Math.round(fighter.displaySize * 0.22);
    const { emitter, embers } = this.spawnChargeEmitters(fighter.ftype, 0, localFootY, fighter.displaySize);
    fighter.sprite.addAt(embers, 0);
    fighter.sprite.addAt(emitter, 0);
    const entry = { emitter, embers, bubble: null, activity: payload.activity ?? '' };
    if (this.fightersAllowBubbles()) {
      this.updateActivityBubble(entry, fighter, payload.activity);
    }
    this.charges.set(payload.user_id, entry);
  }

  spawnChargeEmitters(ftype, posX, footY, displaySize) {
    const fireColors = chargeParticleColors(ftype);
    const ps = displaySize * 0.018;
    // Symmetric spread from both sides of feet — depth 0 so sprite renders on top
    const emitter = this.add.particles(posX, footY, 'spark', {
      tint: { onEmit: () => Phaser.Math.RND.pick(fireColors) },
      rotate: { min: 0, max: 360 },
      scale: { start: ps, end: 0 },
      alpha: { start: 0.55, end: 0 },
      speedX: { min: -180, max: 180 },
      speedY: { min: -90, max: 20 },
      lifespan: { min: 280, max: 480 },
      frequency: 22,
      quantity: 3,
      gravityY: 80,
      blendMode: Phaser.BlendModes.ADD,
    });
    const embers = this.add.particles(posX, footY, 'spark', {
      tint: { onEmit: () => Phaser.Math.RND.pick(fireColors) },
      rotate: { min: 0, max: 360 },
      scale: { start: ps * 0.5, end: 0 },
      alpha: { start: 0.4, end: 0 },
      speedX: { min: -240, max: 240 },
      speedY: { min: -60, max: 30 },
      lifespan: { min: 200, max: 400 },
      frequency: 28,
      quantity: 2,
      gravityY: 60,
      blendMode: Phaser.BlendModes.ADD,
    });
    return { emitter, embers };
  }

  fightersAllowBubbles() {
    return fighterDisplayConfig(this.fighters.size, this.mode).showHandle;
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
      return;
    }
    const bubbleY = fighter.pos.y - (fighter.displaySize / 2 + 28);
    entry.bubble = this.createActivityBubble(fighter.pos.x, bubbleY, activity);
  }

  createActivityBubble(x, y, activity) {
    const text = this.addSharpText(x, y, truncateActivity(activity), {
      fontFamily: 'monospace',
      fontSize: '14px',
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
    };
  }

  handleIdled(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    this.clearCharge(payload.user_id);
  }

  clearCharge(userId) {
    const entry = this.charges.get(userId);
    if (!entry) {
      return;
    }
    entry.emitter.stop();
    entry.embers?.stop();
    this.time.delayedCall(900, () => {
      if (entry.emitter?.scene) entry.emitter.destroy();
      if (entry.embers?.scene) entry.embers.destroy();
    });
    if (entry.bubble) {
      entry.bubble.destroy();
      entry.bubble = null;
    }
    this.charges.delete(userId);
    // Switch back to idle animation
    const fighter = this.fighters.get(userId);
    if (fighter?.body) {
      fighter.body.setTexture(fighter.ftype.key + '-idle');
      fighter.body.play(fighter.ftype.key + '-idle');
      fighter.body.setFlipX(fighter.ftype.baseFlipX ?? false);
      fighter.sprite.scaleY = fighter.sprite.scaleX;
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

      const scale = entry.sprite.scaleX;
      const handleY = target.y + entry.legH * scale + 14;
      if (config.showHandle && !entry.handle) {
        entry.handle = this.addSharpText(target.x, handleY, truncateHandle(entry.handleText), {
          fontFamily: 'monospace',
          fontSize: '16px',
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
        // Emitters are inside the container — use local coords (container center = 0,0)
        const newLocalFootY = chargeFootY(0, newSize) + Math.round(newSize * 0.22);
        charge.emitter.setPosition(0, newLocalFootY);
        charge.embers?.setPosition(0, newLocalFootY);
        if (charge.bubble) {
          charge.bubble.moveTo(target.x, target.y - (newSize / 2 + 28));
        }
      }
    }
  }

  loadAvatarTexture(fighter) {
    const key = `fighter-${fighter.id}`;
    if (this.textures.exists(key)) {
      return Promise.resolve(key);
    }
    if (!fighter.avatarUrl) {
      return Promise.reject(new Error('no avatar URL'));
    }
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = () => {
        if (this.isShuttingDown) {
          reject(new Error('scene destroyed before avatar load'));
          return;
        }
        if (this.textures.exists(key)) {
          resolve(key);
          return;
        }
        // Bake a circular crop into the texture using canvas 2D
        const size = 128;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        ctx.beginPath();
        ctx.arc(size / 2, size / 2, size / 2, 0, Math.PI * 2);
        ctx.clip();
        ctx.drawImage(img, 0, 0, size, size);
        this.textures.addCanvas(key, canvas);
        this.textures.get(key).setFilter(Phaser.Textures.FilterMode.LINEAR);
        resolve(key);
      };
      img.onerror = () => reject(new Error(`avatar load failed: ${fighter.avatarUrl}`));
      img.src = fighter.avatarUrl;
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
      avatarUrl: payload.user_id ? `/avatars/${payload.user_id}` : null,
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
    const scale = size / ftype.frameHeight;
    const legH  = Math.round(ftype.frameHeight * 0.35 * scale);

    const container = this.add.container(pos.x, pos.y).setDepth(2);

    // Body sprite — starts in idle animation (waiting state)
    const body = this.add.sprite(0, 0, ftype.key + '-idle').setScale(scale);
    body.play(ftype.key + '-idle');
    if (ftype.baseFlipX) body.setFlipX(true);
    container.add(body);

    // Avatar overlay on face area (circular pre-baked texture)
    const avatarSize = Math.round(ftype.headSize * scale);
    const headOffsetX = Math.round(ftype.headX * scale);
    const headOffsetY = Math.round(ftype.headY * scale);
    const initialKey = this.textures.exists(`fighter-${fighter.id}`)
      ? `fighter-${fighter.id}`
      : this.makeFallbackAvatarTexture(fighter);
    const head = this.add.image(headOffsetX, headOffsetY, initialKey).setDisplaySize(avatarSize, avatarSize);
    container.add(head);

    // Handle label (world-space)
    const handle = options.showHandle === false
      ? null
      : this.addSharpText(pos.x, pos.y + legH + 14, truncateHandle(fighter.handle), {
          fontFamily: 'monospace',
          fontSize: '16px',
          color: '#fbbf24',
        });

    this.fighters.set(fighter.id, {
      id: fighter.id,
      avatarUrl: fighter.avatarUrl ?? null,
      sprite: container,
      body,
      head,
      handle,
      handleText: fighter.handle ?? '',
      pos,
      baseSize: size,
      displaySize: size,
      legH,
      ftype,
      damageScale: 1,
    });

    if (initialKey !== `fighter-${fighter.id}`) {
      this.loadAvatarTexture(fighter).then(realKey => {
        if (head.scene) head.setTexture(realKey).setDisplaySize(avatarSize, avatarSize);
      }).catch(e => console.warn('[battlefield]', e.message));
    }
  }
}
