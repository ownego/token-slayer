import Phaser from 'phaser';
import { BG_COLOR, LAYOUTS, TIMINGS } from './config.js';
import { computeFighterPositions, fighterDisplayConfig } from './layout.js';
import { bus } from './bus.js';
import { spawnProjectile } from './projectile.js';
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

export class BattlefieldScene extends Phaser.Scene {
  constructor() {
    super('battlefield');
  }

  preload() {
    this.load.spritesheet('boss-ghost', '/assets/battlefield/bosses/ghost.png', {
      frameWidth: 32,
      frameHeight: 32,
    });
    this.load.spritesheet('boss-skeleton', '/assets/battlefield/bosses/skeleton.png', {
      frameWidth: 32,
      frameHeight: 32,
    });
    this.load.spritesheet('boss-slime', '/assets/battlefield/bosses/slime.png', {
      frameWidth: 32,
      frameHeight: 32,
    });
    this.load.spritesheet('fireball', '/assets/battlefield/fx/fireball.png', {
      frameWidth: 16,
      frameHeight: 16,
    });
    this.load.spritesheet('explosion', '/assets/battlefield/fx/explosion.png', {
      frameWidth: 32,
      frameHeight: 32,
    });
  }

  bossTextureFor(number) {
    const keys = ['boss-ghost', 'boss-skeleton', 'boss-slime'];
    return keys[number % keys.length];
  }

  ensureBossIdleAnim(textureKey) {
    const animKey = `${textureKey}-idle`;
    if (!this.anims.exists(animKey)) {
      this.anims.create({
        key: animKey,
        frames: this.anims.generateFrameNumbers(textureKey, { start: 0, end: 3 }),
        frameRate: 6,
        repeat: -1,
      });
    }
    return animKey;
  }

  startBossPatrol() {
    const range = 60;
    const sprite = this.bossSprite;
    const leftX = this.layout.boss.anchor.x - range / 2;
    const rightX = this.layout.boss.anchor.x + range / 2;
    sprite.x = leftX;
    sprite.setFlipX(true);
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
  }

  create() {
    this.isShuttingDown = false;
    this.mode = this.game.registry.get('mode') ?? 'landscape';
    this.layout = LAYOUTS[this.mode];
    const L = this.layout;

    this.add.rectangle(L.logicalWidth / 2, L.logicalHeight / 2, L.logicalWidth, L.logicalHeight, BG_COLOR);

    this.makeChargeRingTexture();

    const state = this.game.registry.get('initialState');
    this.bossState = { ...state.boss };

    const initialKey = this.bossTextureFor(state.boss.number);
    const initialAnim = this.ensureBossIdleAnim(initialKey);
    this.bossSprite = this.add
      .sprite(L.boss.anchor.x, L.boss.anchor.y, initialKey)
      .setScale(L.boss.scale)
      .play(initialAnim);
    this.startBossPatrol();

    this.bossNameText = this.addSharpText(L.boss.name.x, L.boss.name.y, this.bossLabel(state.boss), {
      fontFamily: 'monospace',
      fontSize: '14px',
      color: '#ffffff',
    });

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

    this.hpText = this.addSharpText(L.hpBar.x, L.hpBar.y + 12, `${formatHp(state.boss.currentHp)} / ${formatHp(state.boss.maxHp)}`, {
      fontFamily: 'monospace',
      fontSize: '11px',
      color: '#ffffff',
      stroke: '#0f172a',
      strokeThickness: 3,
    }, 3);

    this.fighters = new Map();
    const config = fighterDisplayConfig(state.fighters.length, this.mode);
    const positions = computeFighterPositions(
      state.fighters.length,
      L.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );
    state.fighters.forEach((f, i) => this.addFighter(f, positions[i], config));

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

  makeChargeRingTexture() {
    if (this.textures.exists('charge-ring')) {
      return;
    }
    const g = this.make.graphics({ x: 0, y: 0, add: false });
    g.lineStyle(2, 0x22d3ee, 1);
    g.strokeCircle(16, 16, 15);
    g.generateTexture('charge-ring', 32, 32);
    g.destroy();
  }

  handleHit(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    this.clearCharge(payload.user_id);
    const fighter = this.fighters.get(payload.user_id);
    const fromX = fighter ? fighter.pos.x : this.layout.logicalWidth / 2;
    const fromY = fighter ? fighter.pos.y : this.layout.logicalHeight / 2;
    spawnProjectile(this, fromX, fromY, () => {
      this.leaderboard?.onHit(payload.user_id, payload.damage, payload.slack_handle);
      applyImpact(this, payload.boss_hp_after);
      if (this.hoveredUserId === payload.user_id) {
        this.showFighterTooltip(payload.user_id);
      }
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

    const textureKey = this.bossTextureFor(payload.boss_number);
    const animKey = this.ensureBossIdleAnim(textureKey);
    this.bossSprite = this.add
      .sprite(L.boss.anchor.x, -40, textureKey)
      .setScale(L.boss.scale)
      .play(animKey);
    this.tweens.add({
      targets: this.bossSprite,
      y: L.boss.anchor.y,
      duration: TIMINGS.bossSpawnMs,
      ease: 'Bounce.easeOut',
      onComplete: () => this.startBossPatrol(),
    });

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
    const ringSize = fighter.displaySize + 8;
    const ring = this.add
      .image(fighter.pos.x, fighter.pos.y, 'charge-ring')
      .setBlendMode(Phaser.BlendModes.ADD)
      .setAlpha(0.4)
      .setDisplaySize(ringSize, ringSize);
    const pulse = this.tweens.add({
      targets: ring,
      alpha: 0.9,
      duration: TIMINGS.chargeRingPulseMs / 2,
      yoyo: true,
      repeat: -1,
      ease: 'Sine.easeInOut',
    });
    const breath = this.tweens.add({
      targets: fighter.sprite,
      scaleY: fighter.sprite.scaleY * 1.05,
      duration: TIMINGS.chargeRingPulseMs / 2,
      yoyo: true,
      repeat: -1,
      ease: 'Sine.easeInOut',
    });
    const entry = { ring, pulse, breath, bubble: null, activity: payload.activity ?? '' };
    if (this.fightersAllowBubbles()) {
      this.updateActivityBubble(entry, fighter, payload.activity);
    }
    this.charges.set(payload.user_id, entry);
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
    } else {
      const bubbleY = fighter.pos.y - (fighter.displaySize / 2 + 14);
      entry.bubble = this.createActivityBubble(fighter.pos.x, bubbleY, activity);
    }
    // The hover tooltip takes priority over the activity bubble.
    if (this.hoveredUserId === fighter.id) {
      entry.bubble.setVisible(false);
    }
  }

  createActivityBubble(x, y, activity) {
    const text = this.addSharpText(x, y, truncateActivity(activity), {
      fontFamily: 'monospace',
      fontSize: '7px',
      color: '#f1f5f9',
      padding: { left: 4, right: 4, top: 2, bottom: 2 },
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

    if (!this.tooltip) {
      this.tooltip = this.createFighterTooltip(content);
    } else {
      this.tooltip.setContent(content);
    }

    const margin = 4;
    const halfW = this.tooltip.width() / 2;
    const halfH = this.tooltip.height() / 2;
    const x = Phaser.Math.Clamp(
      fighter.pos.x,
      halfW + margin,
      this.layout.logicalWidth - halfW - margin,
    );
    const aboveY = fighter.pos.y - (fighter.displaySize / 2 + 12);
    // Flip below the avatar when the tooltip would clip past the top edge.
    const y = aboveY - halfH < margin
      ? fighter.pos.y + (fighter.displaySize / 2 + 12)
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

  createFighterTooltip(content) {
    const text = this.addSharpText(0, 0, content, {
      fontFamily: 'monospace',
      fontSize: '8px',
      color: '#fde68a',
      padding: { left: 4, right: 4, top: 2, bottom: 2 },
    });
    const bg = this.add
      .rectangle(0, 0, text.width + 10, text.height + 6, 0x0f172a, 0.96)
      .setOrigin(0.5)
      .setStrokeStyle(1, 0xfbbf24, 0.9);
    bg.setDepth(300);
    text.setDepth(301);
    const tooltip = {
      setContent: newContent => {
        text.setText(newContent);
        bg.setSize(text.width + 10, text.height + 6);
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
    this.clearCharge(payload.user_id);
  }

  clearCharge(userId) {
    const entry = this.charges.get(userId);
    if (!entry) {
      return;
    }
    entry.pulse.stop();
    entry.breath.stop();
    if (entry.bubble) {
      entry.bubble.destroy();
      entry.bubble = null;
    }
    this.tweens.add({
      targets: entry.ring,
      alpha: 0,
      duration: 200,
      onComplete: () => entry.ring.destroy(),
    });
    this.charges.delete(userId);
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
        entry.sprite.setDisplaySize(newSize, newSize);
        if (entry.maskShape) {
          entry.maskShape.destroy();
        }
        const radius = newSize / 2;
        const maskShape = this.make.graphics({ x: target.x, y: target.y, add: false });
        maskShape.fillCircle(0, 0, radius);
        entry.sprite.setMask(maskShape.createGeometryMask());
        entry.maskShape = maskShape;
        entry.displaySize = newSize;
      }

      const handleY = target.y + (newSize / 2) + 9;
      if (config.showHandle && !entry.handle) {
        entry.handle = this.addSharpText(target.x, handleY, truncateHandle(entry.handleText), {
          fontFamily: 'monospace',
          fontSize: '8px',
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
        const ringSize = newSize + 8;
        this.tweens.add({
          targets: charge.ring,
          x: target.x,
          y: target.y,
          duration: 200,
          ease: 'Quad.easeOut',
        });
        charge.ring.setDisplaySize(ringSize, ringSize);
        if (charge.bubble) {
          charge.bubble.moveTo(target.x, target.y - (newSize / 2 + 14));
        }
      }
    }

    if (this.hoveredUserId != null) {
      this.showFighterTooltip(this.hoveredUserId);
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
        this.textures.addImage(key, img);
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
    const size = 64;
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
      fontSize: '36px',
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
      avatarUrl: payload.avatar_url,
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
    const initialKey = this.textures.exists(`fighter-${fighter.id}`)
      ? `fighter-${fighter.id}`
      : this.makeFallbackAvatarTexture(fighter);
    const size = options.displaySize ?? 24;
    const radius = size / 2;
    const sprite = this.add.image(pos.x, pos.y, initialKey).setDisplaySize(size, size);
    const maskShape = this.make.graphics({ x: pos.x, y: pos.y, add: false });
    maskShape.fillCircle(0, 0, radius);
    sprite.setMask(maskShape.createGeometryMask());
    sprite.setInteractive({ useHandCursor: true });
    sprite.on('pointerover', () => this.showFighterTooltip(fighter.id));
    sprite.on('pointerout', () => this.hideFighterTooltip(fighter.id));
    const handle = options.showHandle === false
      ? null
      : this.addSharpText(pos.x, pos.y + radius + 9, truncateHandle(fighter.handle), {
        fontFamily: 'monospace',
        fontSize: '8px',
        color: '#fbbf24',
      });
    this.fighters.set(fighter.id, {
      id: fighter.id,
      avatarUrl: fighter.avatarUrl ?? null,
      sprite,
      handle,
      handleText: fighter.handle ?? '',
      pos,
      maskShape,
      displaySize: size,
    });

    if (initialKey !== `fighter-${fighter.id}`) {
      this.loadAvatarTexture(fighter).then(realKey => {
        if (sprite.scene) {
          sprite.setTexture(realKey).setDisplaySize(size, size);
        }
      }).catch(e => console.warn('[battlefield]', e.message));
    }
  }
}
