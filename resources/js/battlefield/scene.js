import Phaser from 'phaser';
import { BG_COLOR, LAYOUTS, BOSS_TYPES, FIGHTER_TYPES } from '@battlefield/config.js';
import { AnimState, BusEvent, TextureKey, SCENE_KEY } from '@battlefield/constants.js';
import { bus } from './bus.js';
import { Leaderboard } from './leaderboard.js';
import { Impact } from './impact.js';
import { Projectile } from './projectile.js';
import { Attacks } from './attacks.js';
import { Boss } from './boss.js';
import { Charge } from './charge.js';
import { Bubble } from './bubble.js';
import { MoveInput } from './move-input.js';
import { Fighter } from './fighter.js';

/** Phaser scene coordinator — wires all battlefield managers and handles the Phaser lifecycle. */
export class BattlefieldScene extends Phaser.Scene {
  constructor() {
    super(SCENE_KEY);
  }

  /**
   * Loads all sprite sheets, atlases, and FX spritesheets needed by the scene.
   *
   * @return {void}
   */
  preload() {
    // Single atlas covers all 138 fighter strips
    if (!this.textures.exists(TextureKey.FIGHTERS)) {
      this.load.atlas(
        TextureKey.FIGHTERS,
        '/assets/battlefield/fighters/fighters-atlas.png',
        '/assets/battlefield/fighters/fighters-atlas.json',
      );
    }
    for (const boss of BOSS_TYPES) {
      if (boss.animFiles) {
        for (const [anim, info] of Object.entries(boss.animFiles)) {
          const texKey = `${boss.key}-${anim}`;
          if (!this.textures.exists(texKey))
            this.load.spritesheet(texKey, info.file, { frameWidth: info.frameWidth, frameHeight: info.frameHeight });
        }
      } else {
        if (!this.textures.exists(boss.key))
          this.load.spritesheet(boss.key, boss.file, { frameWidth: boss.frameWidth, frameHeight: boss.frameHeight });
      }
    }
    if (!this.textures.exists(TextureKey.FIREBALL))
      this.load.spritesheet(TextureKey.FIREBALL, '/assets/battlefield/fx/fireball.png', { frameWidth: 16, frameHeight: 16 });
    if (!this.textures.exists(TextureKey.EXPLOSION))
      this.load.spritesheet(TextureKey.EXPLOSION, '/assets/battlefield/fx/explosion.png', { frameWidth: 32, frameHeight: 32 });
    const loaderBar = document.getElementById('bf-loader-bar');
    const loader    = document.getElementById('bf-loader');
    this.load.on('progress', v => { if (loaderBar) loaderBar.style.width = Math.round(v * 100) + '%'; });
    this.load.on('complete', () => {
      if (loader) loader.style.display = 'none';
      const pixelArtKeys = [
        ...BOSS_TYPES.filter(b => b.pixelArt !== false).flatMap(b =>
          b.animFiles ? Object.keys(b.animFiles).map(anim => `${b.key}-${anim}`) : [b.key]
        ),
        TextureKey.FIREBALL, TextureKey.EXPLOSION,
      ];
      for (const key of pixelArtKeys) {
        this.textures.get(key).setFilter(Phaser.Textures.FilterMode.NEAREST);
      }
      // Fighter atlas: NEAREST filter + register all animations from named frames
      this.textures.get(TextureKey.FIGHTERS)?.setFilter(Phaser.Textures.FilterMode.NEAREST);
      for (const ft of FIGHTER_TYPES) {
        for (const [state, anim] of Object.entries(ft.animations)) {
          const animKey = `${ft.key}-${state}`;
          if (!this.anims.exists(animKey)) {
            this.anims.create({
              key: animKey,
              frames: this.anims.generateFrameNames(TextureKey.FIGHTERS, {
                prefix: `${ft.key}-${state}-`,
                start: 0,
                end: anim.frames - 1,
              }),
              frameRate: anim.rate,
              repeat: (state === AnimState.IDLE || state === AnimState.WALK) ? -1 : 0,
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
              frames: this.anims.generateFrameNames(TextureKey.FIGHTERS, {
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
              frames: this.anims.generateFrameNames(TextureKey.FIGHTERS, {
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

  /**
   * Creates and wires all battlefield managers, seeds initial state, and registers bus handlers.
   *
   * @return {void}
   */
  create() {
    this.isShuttingDown = false;
    this.mode = this.game.registry.get('mode') ?? 'landscape';
    this.layout = LAYOUTS[this.mode];
    const L = this.layout;

    this.add.rectangle(L.logicalWidth / 2, L.logicalHeight / 2, L.logicalWidth, L.logicalHeight, BG_COLOR);
    this.makeSparkTexture();
    this.add.image(L.logicalWidth / 2, L.logicalHeight / 2, this.makeVignetteTexture());

    const state = this.game.registry.get('initialState');
    this.boss = new Boss(this);
    this.boss.create(state);

    this.bubble = new Bubble(this);
    this.charge = new Charge(this);
    this.impact = new Impact(this);
    this.projectile = new Projectile(this);
    this.attacks = new Attacks(this);

    this.fighters = new Map();
    this.damageTotals = new Map();
    this.currentUserId = state.currentUserId ?? null;
    this.fighter = new Fighter(this);
    this.fighter.seedInitial(state);

    this.leaderboard = new Leaderboard(this);
    this.leaderboard.seed(state.leaderboard ?? []);

    this.charges = new Map();
    // Synthesizes the live `fighter-charging` payload shape — keep in sync with FighterCharging::broadcastWith().
    for (const f of state.fighters) {
      if (f.charging) {
        this.charge.handleCharging({
          user_id: f.id,
          activity: f.charging.activity,
        });
      }
    }

    this._busHandlers = {
      [BusEvent.HIT]:              payload => this.fighter.handleHit(payload),
      [BusEvent.BOSS_SPAWNED]:     payload => this.boss.handleBossSpawned(payload),
      [BusEvent.BOSS_KILLED]:      payload => this.boss.handleBossKilled(payload),
      [BusEvent.FIGHTER_CHARGING]: payload => this.charge.handleCharging(payload),
      [BusEvent.FIGHTER_IDLED]:    payload => this.fighter.handleIdled(payload),
      [BusEvent.FIGHTER_JOINED]:   payload => this.fighter.handleFighterJoined(payload),
      [BusEvent.FIGHTER_MOVED]:    payload => this.fighter.handleFighterMoved(payload),
    };

    this.moveInput = new MoveInput(this);
    this.moveInput.setup();
    for (const [evt, fn] of Object.entries(this._busHandlers)) {
      bus.on(evt, fn);
    }
    this.events.once('shutdown', () => {
      this.isShuttingDown = true;
      for (const [evt, fn] of Object.entries(this._busHandlers)) {
        bus.off(evt, fn);
      }
      this.leaderboard?.destroy?.();
      this.tooltip = null;
      this.hoveredUserId = null;
    });

    this.events.emit('ready');
    this.game.events.emit('ready');
  }

  /**
   * Syncs world-space activity bubbles to their fighter containers every frame.
   *
   * @return {void}
   */
  update() {
    for (const [userId, entry] of this.fighters.entries()) {
      if (!entry.sprite?.active) continue;
      const charge = this.charges?.get(userId);
      if (!charge?.bubble) continue;
      const avatarRelY   = entry.head?.y ?? 0;
      const avatarRadius = (entry.head?.displayHeight ?? 28) / 2;
      charge.bubble.moveTo(entry.sprite.x, entry.sprite.y + avatarRelY - avatarRadius - 16);
    }
  }

  /**
   * Adds a Phaser Text object with LINEAR-filtered resolution-doubled rendering.
   *
   * @param {number} x
   * @param {number} y
   * @param {string} content
   * @param {object} style
   * @param {number} [resolution=2]
   * @return {Phaser.GameObjects.Text}
   */
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

  /** Creates and registers the radial vignette gradient texture for the current mode. */
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

  /** Creates and registers the spark particle texture used by charge and attack effects. */
  makeSparkTexture() {
    if (this.textures.exists(TextureKey.SPARK)) {
      return;
    }
    const g = this.make.graphics({ add: false });
    g.fillStyle(0xffffff, 1);
    // Thin triangle — tip at right (24,3), base at left
    g.fillTriangle(24, 3, 0, 0, 0, 6);
    g.generateTexture(TextureKey.SPARK, 24, 6);
    g.destroy();
  }
}
