import Phaser from 'phaser';
import { FIGHTER_TYPES, TIMINGS } from '@battlefield/config.js';
import { computeFighterPositions, damageScaleMultiplier, fighterDisplayConfig } from '@battlefield/layout.js';
import { AnimState, AttackType, TextureKey } from '@battlefield/constants.js';
import { Boss } from '@battlefield/boss.js';
import { setDepthIfChanged } from '@battlefield/shared/depth.js';
import { moveOrigin, planRoute } from '@battlefield/move-geometry.js';
import { resolveFighterPlacement } from '@battlefield/fighter-placement.js';
import { driftedPositions } from '@battlefield/resync.js';
import { findHeel } from '@battlefield/shared/heel.js';
import { glyphKey } from '@battlefield/shared/flair-glyphs.js';
import { loadAvatarTexture, makeFallbackAvatarTexture, makePermanentFallbackAvatarTexture } from './avatar.js';
import { ensureFlairFont, isFlairFontReady } from './flair-font.js';
import { NAME_DEPTH, NAME_FONT_PX, NAME_STYLE, YOU_NAME_COLOR, refreshNameWhenFontLoads, youRingSparks } from './name-ring.js';
import { avatarCenterY, avatarSrc } from './avatar-stack.js';
import {
  buildRingChars,
  clearFlair,
  createFlairState,
  glyphState,
  hasFlairChanged,
  isFlairActive,
  resolveFlairDuration,
  spinMultiplier,
  startFlair,
} from './flair.js';

// Tiny RPG sprite geometry constants — do not change without re-measuring the atlas.
const SPRITE_CHAR_HEIGHT = 18;
const SPRITE_HALF_FRAME  = 50;
const SPRITE_CHAR_BOT    = 56;

const HANDLE_MAX_CHARS = 12;
// The name under the feet: plain outlined text (no pill), above the YOU ring
// — see ./name-ring.js.
const NAME_PLATE_FONT_PX = NAME_FONT_PX;
const NAME_PLATE_STYLE = NAME_STYLE;

// Matches boss/stun.js's own orbiting-star front depth (112) rather than
// picking a fresh number: that effect has the exact same requirement (an
// orbit that wraps a character and must stay visible above every game-world
// VFX layer it could pass over) and is the established precedent for it.
// Attack trails/beams/ghosts run 1.5-4 (attacks/*.js), projectiles run 9-10
// and their trail emitters 9 (projectile.js), boss-reaction FX run 6
// (boss/dreadknight.js) -- depth 5 alone (an earlier attempt at this fix)
// cleared only the first group, so the ring could still be drawn over
// during a melee attack's strike/impact phase, when the fighter ends up
// positioned at the boss anchor alongside the projectile/impact depths.
// 112 clears all of those with margin while staying below the UI-tier
// overlays (activity bubble 100-101, tooltip 300-301 in bubble.js, the
// kill ceremony's card at depth 200-201 in scene.js's showKillCard) that
// should always render on top of any in-world character effect regardless.
const FLAIR_RING_FRONT_DEPTH = 112;

// Ring glyph ghost: one dim afterimage per glyph, a fixed phase behind it
// (a single Image, not the old 3-dot Circle trail — cheaper, and the
// baked-texture glyphs read fine with a single soft echo).
const FLAIR_GHOST_PHASE_GAP = 0.09;
const FLAIR_GHOST_ALPHA = 0.28;

// Everything about the flair is sized as a ratio of the fighter's own
// displaySize, so it stays in proportion as fighters grow with damage.
//
// The ring has to clear the fighter's actual on-screen silhouette, which is
// much WIDER than it is tall once a character's weapons/props are counted
// (measured live: displaySize 45 renders a ~90px-wide sprite plus a 40px
// avatar bubble). The first pass used a radius derived from displaySize
// alone, which put the ring INSIDE that silhouette -- the name ended up
// drawn across the character instead of orbiting around it. These ratios
// match the approved artifact's proportions, where the ring reads as
// clearly wider than the character it wraps.
const FLAIR_RING_RX_RATIO = 1.3;
const FLAIR_RING_RY_RATIO = 0.47;
const FLAIR_FONT_RATIO = 0.28;
const FLAIR_RING_CHAR_STEP = 0.26;

const FLAIR_SPARKLE_GLOW_BLUR = 12;

// The new, smaller burst (replacing the old 95px grey disc + 12 pink
// spokes): a soft glow, a thin expanding ring, a handful of streaks, and a
// single ✦ pop — all sized off the fighter's own displaySize so it stays in
// proportion as fighters grow with damage.
const FLAIR_BURST_GLOW_MS = 260;
const FLAIR_BURST_RING_MS = 380;
const FLAIR_BURST_RING_RADIUS_RATIO = 1.1;
const FLAIR_BURST_STREAK_COUNT = 8;

/**
 * Reads a fighter type's idle frame 0 alpha channel once and caches its
 * heel (via `findHeel`) directly on the shared `ftype` config object —
 * every fighter of that type reuses the same heel forever, no per-instance
 * work. A cache miss draws the frame into an offscreen canvas (the same
 * `atlas.getSourceImage()`/`cutX`/`cutY` approach `fighter/preview.js`
 * already uses to read the atlas without a second image request) and reads
 * its pixels back with `getImageData`.
 *
 * @param {Phaser.Scene} scene
 * @param {{key: string, heel?: {x:number,y:number}}} ftype
 * @return {void}
 */
function cacheHeel(scene, ftype) {
  if (ftype.heel) {
    return;
  }
  const frame = scene.textures.getFrame(TextureKey.FIGHTERS, `${ftype.key}-idle-0`);
  if (!frame) {
    return;
  }
  const { cutX, cutY, cutWidth, cutHeight, source } = frame;
  const canvas = document.createElement('canvas');
  canvas.width = cutWidth;
  canvas.height = cutHeight;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(source.image, cutX, cutY, cutWidth, cutHeight, 0, 0, cutWidth, cutHeight);
  const { data } = ctx.getImageData(0, 0, cutWidth, cutHeight);
  ftype.heel = findHeel(data);
}

/** @param {string} handle @param {number} maxChars @return {string} */
function truncateHandle(handle, maxChars = HANDLE_MAX_CHARS) {
  if (!handle || handle.length <= maxChars) {
    return handle ?? '';
  }
  return handle.slice(0, maxChars - 1) + '…';
}


/** Returns logical avatar pixel size from fighter display size. @param {number} displaySize @return {number} */
export function avatarPx(displaySize) {
  return Math.round(displaySize * 0.85);
}

/** Manages the full fighter lifecycle: join, move, hit, relayout, avatar loading, and scaling. */
export class Fighter {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
    // Warm the flair webfont at scene boot rather than on the first flair
    // hit, so the ring is almost always built with the face already in hand
    // and startFlairRing's re-apply pass stays a fallback, not the norm.
    ensureFlairFont();
  }

  /**
   * Returns the canonical display scale for a fighter based on size and damage.
   *
   * @param {{ displaySize: number, baseSize: number, damageScale?: number }} fighter
   * @return {number}
   */
  static fighterRestScale(fighter) {
    return (fighter.displaySize / fighter.baseSize) * (fighter.damageScale ?? 1);
  }

  /**
   * Seeds the scene with initial fighters and restores damage totals from state.
   *
   * @param {{ fighters: Array, damageTotals?: Array }} state
   * @return {void}
   */
  seedInitial(state) {
    const L = this.scene.layout;
    const config = fighterDisplayConfig(state.fighters.length, this.scene.mode);
    // Grid slots are only for fighters without a saved custom position —
    // sizing this array against the total fighter count and consuming one
    // slot per fighter regardless left the grid ones scattered across gaps
    // sized for slots the custom-positioned fighters were never going to use.
    const gridFighterCount = state.fighters.filter(f => !f.position).length;
    const autoPositions = computeFighterPositions(
      gridFighterCount,
      L.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );
    const bossType = Boss.bossTypeOf(this.scene.bossState);
    const damageByUser = new Map(state.damageTotals ?? []);
    let gridIdx = 0;
    state.fighters.forEach((f) => {
      const damageScale = damageScaleMultiplier(damageByUser.get(f.id) ?? 0, this.scene.bossState?.maxHp);
      const ctx = {
        layout: L,
        bossType,
        fsize: config.displaySize * damageScale,
        zones: this.scene._zones ?? [],
      };
      // Peeked, not yet consumed — a fighter whose saved position turns out
      // invalid (resolveFighterPlacement falls back to grid) still needs a
      // slot despite being excluded from gridFighterCount above; the bounds
      // fallback covers that rare overflow.
      const gridPos = autoPositions[gridIdx]
        ?? autoPositions[autoPositions.length - 1]
        ?? { x: L.logicalWidth / 2, y: config.topY };
      const { pos, isCustom } = resolveFighterPlacement(f.position, gridPos, ctx);
      if (!isCustom) {
        gridIdx++;
      }
      this.addFighter(f, pos, config);
      if (isCustom) {
        this.scene.fighters.get(f.id).hasCustomPosition = true;
      }
    });
    for (const [userId, damage] of state.damageTotals ?? []) {
      this.scene.damageTotals.set(userId, damage);
      this.rescaleFighterByDamage(userId);
    }
  }

  /**
   * Handles the fighter-joined event payload, adding the fighter to the scene.
   * A rejoining fighter carries its persisted position and is restored there
   * rather than placed in the next free grid slot.
   *
   * @param {{ user_id: number|string, slack_handle?: string, display_name?: string, character?: string, position?: {x: number, y: number}|null }} payload
   * @return {void}
   */
  handleFighterJoined(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    if (this.scene.fighters.has(payload.user_id)) {
      return;
    }
    const fighter = {
      id: payload.user_id,
      handle: payload.slack_handle,
      display_name: payload.display_name ?? null,
      character: payload.character ?? null,
      avatarUrl: payload.avatar_url ?? null,
    };

    const count = this.scene.fighters.size + 1;
    const config = fighterDisplayConfig(count, this.scene.mode);
    // Only fighters without a custom position occupy a grid slot — see
    // relayoutFighters() for why sizing this array against the total count
    // (including custom-positioned fighters) leaves the grid ones scattered
    // across gaps sized for slots that were never going to be used.
    const gridFighterCount = [...this.scene.fighters.values()].filter(e => !e.hasCustomPosition).length + 1;
    const positions = computeFighterPositions(
      gridFighterCount,
      this.scene.layout.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );

    const damageScale = damageScaleMultiplier(
      this.scene.damageTotals.get(payload.user_id) ?? 0,
      this.scene.bossState?.maxHp,
    );
    const { pos, isCustom } = resolveFighterPlacement(payload.position, positions[positions.length - 1], {
      layout: this.scene.layout,
      bossType: Boss.bossTypeOf(this.scene.bossState),
      fsize: config.displaySize * damageScale,
      zones: this.scene._zones ?? [],
    });
    this.addFighter(fighter, pos, config);

    const entry = this.scene.fighters.get(fighter.id);
    if (!entry) {
      return;
    }
    // Must be flagged before relayoutFighters(), which grid-snaps every
    // fighter not already marked as custom-positioned.
    entry.hasCustomPosition = isCustom;
    this.relayoutFighters();

    // Every character rises in via a Summon animation instead of a generic
    // scale pop. The four skeleton variants ship their own dedicated Summon
    // art (per the asset pack's own summon-in-tandem design); everyone else
    // gets one fighter/animations.js derives from their own Death strip
    // played in reverse — both already depict emerging from the ground, so
    // no separate pop tween is needed.
    const summonAnim = entry.ftype?.animations?.summon ?? entry.ftype?.animations?.death;
    const summonAnimKey = summonAnim && entry.ftype ? `${entry.ftype.key}-summon` : null;
    const finalScale = entry.sprite.scaleX;
    // Hidden immediately, regardless of which reveal path this character
    // uses below — addFighter() already left it sitting fully visible in
    // its idle pose, which (for the summon-animation path especially) made
    // the character appear to pop in well before the Necromancer's own cast
    // even finished, with the "summon" animation only playing afterward on
    // something already there.
    entry.sprite.setAlpha(0);
    const reveal = () => {
      if (!entry.sprite?.active) return;
      this.scene.necromancer?.spawnSummonCircle(entry.pos.x, entry.pos.y);
      // Give the ground circle a moment to visibly form before the
      // character appears rising out of it, rather than both at once.
      const CIRCLE_LEAD_MS = 150;
      this.scene.time.delayedCall(CIRCLE_LEAD_MS, () => {
        if (!entry.sprite?.active) return;
        entry.sprite.setAlpha(1);
        // Golden glow around the character's own silhouette while it rises —
        // the same gold (#fbbf24) the flair ring already uses elsewhere in
        // this game, faded in as it appears and back out once revealed.
        const glow = entry.body?.preFX?.addGlow(0xfbbf24, 0, 0, false, 0.15, 20);
        if (glow) {
          this.scene.tweens.add({ targets: glow, outerStrength: 3, duration: 220, ease: 'Quad.easeOut' });
        }
        const clearGlow = () => {
          if (!glow) return;
          this.scene.tweens.add({
            targets: glow,
            outerStrength: 0,
            duration: 260,
            ease: 'Quad.easeIn',
            onComplete: () => entry.body?.preFX?.remove(glow),
          });
        };
        if (summonAnimKey && entry.body) {
          entry.body.play(summonAnimKey);
          const durationMs = Math.ceil((summonAnim.frames / summonAnim.rate) * 1000);
          this.scene.time.delayedCall(durationMs, () => {
            clearGlow();
            // A real hit may have landed mid-summon and already be animating
            // its own attack (handleHit always plays over whatever was
            // showing) — in that case animState is ATTACK for that real
            // reason, and its own completion handler owns the transition back.
            if (!entry.body?.scene || entry.animState === AnimState.ATTACK) return;
            entry.animState = AnimState.IDLE;
            entry.body.play(`${entry.ftype.key}-idle`);
          });
        } else {
          // Characters without their own Summon rise animation still rise —
          // pop in from below their final spot rather than growing in place,
          // so every character type reads as emerging from the circle.
          const finalY = entry.sprite.y;
          const riseOffset = entry.displaySize * 0.6;
          entry.sprite.setScale(0);
          entry.sprite.y = finalY + riseOffset;
          this.scene.tweens.add({
            targets: entry.sprite,
            scale: finalScale,
            y: finalY,
            duration: TIMINGS.fighterJoinMs,
            ease: 'Back.easeOut',
            onComplete: clearGlow,
          });
        }
      });
    };
    if (this.scene.necromancer) {
      // A getter, not a captured (x, y) snapshot: a fighter queued behind
      // another can have relayoutFighters() (triggered by a later fighter
      // joining) move its grid slot before the Necromancer gets to it, and
      // entry.pos always reflects wherever it will actually rise.
      this.scene.necromancer.summon(() => entry.pos, reveal);
    } else {
      reveal();
    }
  }

  /**
   * Re-skins each listed fighter's sprite to the given character.
   * characterForBoss() is deterministic per (user, boss), so every existing
   * fighter needs this when the boss changes — not just newly-joined ones,
   * which already get their correct character from handleFighterJoined.
   * With `animate` (a player equipping a new character from the fighter
   * sheet) the swap plays out as the approved mockup shows it: the old
   * fighter dies, a summon circle opens and the new one rises; a boss
   * change re-skins the whole field at once instead.
   *
   * @param {Array<{user_id: number|string, character: string}>} roster
   * @param {{animate?: boolean}} [options]
   * @return {void}
   */
  updateCharacters(roster, { animate = false } = {}) {
    for (const { user_id: userId, character } of roster ?? []) {
      const entry = this.scene.fighters.get(userId);
      if (!entry || !character || entry.ftype?.key === character) {
        continue;
      }
      const ftype = FIGHTER_TYPES.find(ft => ft.key === character);
      if (!ftype) {
        continue;
      }
      const oldKey = entry.ftype?.key;
      if (animate && !this.scene.reducedMotion && oldKey && this.scene.anims.exists(`${oldKey}-death`)) {
        this._swapCharacter(entry, ftype);
      } else {
        this._applyCharacter(entry, ftype);
      }
    }
  }

  /**
   * Plays the old character's death, then opens a summon circle and plays
   * the new character rising (its reversed-death summon) before idling.
   *
   * @param {object} entry A scene.fighters entry.
   * @param {object} ftype The new FIGHTER_TYPES entry.
   * @return {void}
   */
  _swapCharacter(entry, ftype) {
    entry.body.play(`${entry.ftype.key}-death`);
    entry.body.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
      if (this.scene.isShuttingDown) {
        return;
      }
      this.scene.necromancer?.spawnSummonCircle(entry.pos.x, entry.pos.y);
      entry.ftype = ftype;
      entry.body.setTexture(TextureKey.FIGHTERS, `${ftype.key}-death-0`);
      entry.body.play(`${ftype.key}-summon`);
      entry.body.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        if (!this.scene.isShuttingDown) {
          this._applyCharacter(entry, ftype);
        }
      });
    });
  }

  /**
   * Shows a fighter as the given character, idling.
   *
   * @param {object} entry A scene.fighters entry.
   * @param {object} ftype The FIGHTER_TYPES entry.
   * @return {void}
   */
  _applyCharacter(entry, ftype) {
    entry.ftype = ftype;
    // the avatar follows the new body's own head height
    entry.head?.setY(Math.round(avatarCenterY(ftype.key, (entry.baseSize ?? 48) / SPRITE_CHAR_HEIGHT)));
    entry.animState = AnimState.IDLE;
    entry.body.setTexture(TextureKey.FIGHTERS, `${ftype.key}-idle-0`);
    const idleAnim = this.scene.anims.get(`${ftype.key}-idle`);
    if (idleAnim?.frames?.length) {
      entry.body.play(`${ftype.key}-idle`);
    }
  }

  /**
   * Adds a fighter sprite and label to the scene at the given position.
   *
   * @param {{ id: number|string, handle?: string, slack_handle?: string, display_name?: string, character?: string }} fighter
   * @param {{ x: number, y: number }} pos
   * @param {{ displaySize?: number, showHandle?: boolean }} options
   * @return {void}
   */
  addFighter(fighter, pos, options = {}) {
    const size = options.displaySize ?? 48;

    // Pick character type by fighter.character key, fall back to id modulo
    const ftypeKey = fighter.character ?? null;
    const ftype = (ftypeKey && FIGHTER_TYPES.find(ft => ft.key === ftypeKey))
      ?? FIGHTER_TYPES[Math.abs(Number(fighter.id) || 0) % FIGHTER_TYPES.length];
    // Scale so the visible character (18px of the 100px frame) fills `size` logical px
    const scale     = size / SPRITE_CHAR_HEIGHT;
    const legH      = Math.round((SPRITE_CHAR_BOT - SPRITE_HALF_FRAME) * scale);
    const avatarY   = Math.round(avatarCenterY(ftype.key, scale)); // per fighter, from its own head (avatar-stack.js)
    const avSize    = avatarPx(size);
    const displayName = fighter.handle || fighter.slack_handle || fighter.display_name || '';

    const container = this.scene.add.container(pos.x, pos.y).setDepth(2);

    // YOU ring under the viewer's own fighter — added first so it sits behind
    // the body: a soft glow, the gold ellipse with a fainter inner line, and a
    // few sparks orbiting it (brighter on the near side).
    const isYou = Number(fighter.id) === Number(this.scene.currentUserId);
    if (isYou) {
      const ringW = size * 1.15;
      const ringH = ringW * 0.32;
      const glow = this.scene.add.graphics().setBlendMode(Phaser.BlendModes.ADD);
      glow.fillStyle(0xfbbf24, 0.16);
      glow.fillEllipse(0, legH, ringW * 1.35, ringH * 1.6);
      const youRing = this.scene.add.graphics();
      youRing.fillStyle(0xfbbf24, 0.1);
      youRing.fillEllipse(0, legH, ringW, ringH);
      youRing.lineStyle(2, 0xfbbf24, 1);
      youRing.strokeEllipse(0, legH, ringW, ringH);
      youRing.lineStyle(1, 0xfde68a, 0.55);
      youRing.strokeEllipse(0, legH, ringW * 0.72, ringH * 0.72);
      container.add([glow, youRing]);
      this.scene.tweens.add({
        targets: [youRing, glow],
        scaleX: 1.06,
        scaleY: 1.06,
        alpha: 0.65,
        duration: TIMINGS.chargeRingPulseMs,
        yoyo: true,
        repeat: -1,
        ease: 'Sine.easeInOut',
      });
      if (!this.scene.reducedMotion) {
        const sparks = this.scene.add.graphics().setBlendMode(Phaser.BlendModes.ADD);
        container.add(sparks);
        const orbit = this.scene.tweens.addCounter({
          from: 0,
          to: 1,
          duration: 1000,
          repeat: -1,
          onUpdate: () => {
            if (!sparks.active) {
              orbit.stop();

              return;
            }
            sparks.clear();
            for (const p of youRingSparks(this.scene.time.now, 4, ringW, ringH)) {
              sparks.fillStyle(0xfff3c4, p.alpha);
              sparks.fillRect(p.x - 1.5, legH + p.y - 1.5, 3, 3);
            }
          },
        });
      }
    }

    // Body sprite — starts in idle animation (waiting state)
    const body = this.scene.add.sprite(0, 0, TextureKey.FIGHTERS, `${ftype.key}-idle-0`).setScale(scale);
    const idleAnim = this.scene.anims.get(ftype.key + '-idle');
    if (idleAnim?.frames?.length) {
      body.play(ftype.key + '-idle');
    }
    cacheHeel(this.scene, ftype);
    container.add(body);
    const avatarUrl = avatarSrc(fighter);
    const initialKey = this.scene.textures.exists(`fighter-${fighter.id}`)
      ? `fighter-${fighter.id}`
      : makeFallbackAvatarTexture(this.scene, fighter);
    const head = this.scene.add.image(0, avatarY, initialKey).setDisplaySize(avSize, avSize);
    head.setInteractive({ useHandCursor: true });
    head.on('pointerover', () => this.scene.bubble?.showFighterTooltip?.(fighter.id));
    head.on('pointerout', () => this.scene.bubble?.hideFighterTooltip?.(fighter.id));
    container.add(head);

    // Name plate — a dark pill under the feet, matching the DOM HUD's own
    // look (see NAME_PLATE_STYLE); the old yellow handle text above the
    // avatar is gone.
    const handle = options.showHandle === false
      ? null
      : this.scene.addSharpText(pos.x, pos.y + legH + NAME_PLATE_FONT_PX, truncateHandle(displayName), NAME_PLATE_STYLE)
        .setDepth(NAME_DEPTH)
        .setColor(isYou ? YOU_NAME_COLOR : NAME_PLATE_STYLE.color);
    if (handle) {
      refreshNameWhenFontLoads(handle);
    }

    this.scene.fighters.set(fighter.id, {
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
      animState: AnimState.IDLE,
      isStunned: false,
      lastStunAt: null,
      waypointMoving: false,
      hasCustomPosition: false,
      rescaleTween: null,
    });

    if (initialKey !== `fighter-${fighter.id}` && avatarUrl) {
      loadAvatarTexture(this.scene, fighter.id, avatarUrl).then(realKey => {
        if (head.scene) {
          head.setTexture(realKey).setDisplaySize(avSize, avSize);
        }
      }).catch(e => {
        console.warn('[battlefield]', e.message);
        // The load genuinely failed (no avatar_url, a 404, a network
        // error) rather than still being in flight — swap off the
        // letter-glyph "still loading" fallback onto the silhouette one,
        // so this fighter (and any minion badge mirroring its texture,
        // see minions.js's _positionBadge) doesn't read as permanently
        // "not loaded yet".
        if (head.scene) {
          head.setTexture(makePermanentFallbackAvatarTexture(this.scene, fighter)).setDisplaySize(avSize, avSize);
        }
      });
    }
  }

  /**
   * Handles the fighter-moved event, tweening the fighter to the new position.
   *
   * @param {{ user_id: number|string, x: number, y: number }} payload
   * @return {void}
   */
  handleFighterMoved(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    const entry = this.scene.fighters.get(payload.user_id);
    if (!entry) {
      return;
    }
    if (entry.isStunned) {
      return;
    }
    // Skip server echo while local waypoint animation is in progress for own fighter
    if (entry.waypointMoving && payload.user_id === this.scene.currentUserId) {
      return;
    }

    const raw = {
      x: payload.x * this.scene.layout.logicalWidth,
      y: payload.y * this.scene.layout.logicalHeight,
    };
    const ctx = {
      layout: this.scene.layout,
      bossType: Boss.bossTypeOf(this.scene.bossState),
      fsize: entry.displaySize * (entry.damageScale ?? 1),
    };
    const origin = moveOrigin(entry.sprite, entry.pos, ctx);
    const route = planRoute(origin.x, origin.y, raw.x, raw.y, ctx) ?? [origin];

    // Kill any in-progress move tweens before starting new ones
    this.scene.tweens.killTweensOf(entry.sprite);
    if (entry.handle) {
      this.scene.tweens.killTweensOf(entry.handle);
    }

    this._animateMoveRoute(entry, route);
  }

  /**
   * Tweens a fighter's sprite, handle, and charge trail through the given
   * waypoint list — mirrors MoveInput's local route animation so remote
   * viewers see the same detour the mover planned, instead of a straight
   * line that clips the boss/HP-bar column.
   *
   * @param {object} entry
   * @param {Array<{x: number, y: number}>} route
   * @return {void}
   */
  _animateMoveRoute(entry, route) {
    const SPEED_PX_PER_SEC = 300;

    const step = (idx) => {
      if (!entry.sprite?.active || idx >= route.length) {
        return;
      }
      const target = route[idx];
      const dx = target.x - entry.sprite.x;
      const dy = target.y - entry.sprite.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const duration = Math.max(200, Math.round((dist / SPEED_PX_PER_SEC) * 1000));

      // Flip toward movement direction; fall back to boss-facing when barely horizontal
      const flipX = dx < -5 ? true : (dx > 5 ? false : target.x > this.scene.layout.boss.anchor.x);

      // Start walk animation (unless mid-attack)
      if (entry.body && entry.animState !== AnimState.ATTACK && entry.ftype) {
        entry.animState = AnimState.WALK;
        entry.body.setFlipX(flipX);
        entry.body.play(entry.ftype.key + '-walk', true);
      }

      this.scene.tweens.add({
        targets: entry.sprite,
        x: target.x,
        y: target.y,
        duration,
        ease: 'Linear',
        onComplete: () => {
          if (!entry.sprite?.active) {
            return;
          }
          if (idx === route.length - 1) {
            entry.pos = target;
            entry.hasCustomPosition = true;
            if (entry.body && entry.animState !== AnimState.ATTACK) {
              const isCharging = this.scene.charges.has(entry.id);
              const next = isCharging ? AnimState.WALK : AnimState.IDLE;
              entry.animState = next;
              entry.body.setFlipX(next === AnimState.WALK ? target.x > this.scene.layout.boss.anchor.x : false);
              entry.body.play(entry.ftype.key + '-' + next, true);
            }
          }
          step(idx + 1);
        },
      });

      if (entry.handle) {
        this.scene.tweens.killTweensOf(entry.handle);
        const scale = entry.sprite.scaleX;
        this.scene.tweens.add({
          targets: entry.handle,
          x: target.x,
          y: target.y + entry.legH * scale + NAME_PLATE_FONT_PX,
          duration,
          ease: 'Linear',
        });
      }

      const charge = this.scene.charges.get(entry.id);
      if (charge?.trail?.scene) {
        this.scene.tweens.killTweensOf(charge.trail);
        const tb = target.x <= this.scene.layout.boss.anchor.x ? 1 : -1;
        const cb = Math.round(entry.displaySize / 3);
        this.scene.tweens.add({
          targets: charge.trail,
          x: target.x - tb * Math.round(entry.displaySize * 0.18),
          y: target.y + cb - Math.round(entry.displaySize * 0.12),
          duration,
          ease: 'Linear',
        });
      }
    };

    step(0);
  }

  /**
   * Repairs any fighters whose local position has drifted from the
   * server-authoritative snapshot returned by `Battlefield::resync()` — the
   * fix for Reverb's lack of event replay, where a `FighterMoved` broadcast
   * that fires while this client is disconnected is otherwise lost forever
   * for this one viewer. Silently corrects only the fighters that actually
   * drifted, leaving everyone already in sync untouched.
   *
   * @param {Array<{user_id: number|string, x: number, y: number}>} serverPositions
   * @return {void}
   */
  reconcilePositions(serverPositions) {
    if (!Array.isArray(serverPositions) || serverPositions.length === 0) {
      return;
    }
    const L = this.scene.layout;
    const localFighters = [];
    for (const [id, entry] of this.scene.fighters.entries()) {
      localFighters.push({
        id,
        x: entry.pos.x / L.logicalWidth,
        y: entry.pos.y / L.logicalHeight,
        waypointMoving: entry.waypointMoving,
      });
    }
    for (const server of driftedPositions(localFighters, serverPositions)) {
      this.handleFighterMoved({ user_id: server.user_id, x: server.x, y: server.y });
    }
  }

  /**
   * Handles the fighter-idled event, clearing charge and removing the fighter.
   *
   * @param {{ user_id: number|string }} payload
   * @return {void}
   */
  handleIdled(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    const userId = payload.user_id;
    this.scene.charge?.clearCharge?.(userId);
    this.removeFighter(userId);
  }

  /**
   * Removes a fighter from the scene with a fade-out tween.
   *
   * @param {number|string} userId
   * @return {void}
   */
  /**
   * Shows or refreshes a fighter's flair halo for this hit: a ring of glyphs
   * spelling the model's name, orbiting the whole fighter (not just the head,
   * where it would compete with the activity bubble for the same patch of
   * screen), plus a one-shot burst.
   *
   * @param {object} fighter
   * @param {?string} flair
   * @param {?number} flairDurationMs  server-broadcast duration for this model,
   *   or null to fall back to the client default (an older cached client, or a
   *   flair with no configured duration)
   * @param {?string} flairColor  server-broadcast (admin-configured) hex color,
   *   or null to fall back to the client default (an older cached client)
   * @return {void}
   */
  applyFlair(fighter, flair, flairDurationMs, flairColor) {
    const now = this.scene.time.now;
    const durationMs = resolveFlairDuration(flairDurationMs, TIMINGS.flairDurationMs);
    const previous = { flair: fighter.flairState?.flair ?? null, color: fighter.flairColor ?? null };
    fighter.flairState = startFlair(fighter.flairState ?? createFlairState(), flair, now, durationMs);

    if (!isFlairActive(fighter.flairState, now)) {
      return;
    }

    const next = { flair: fighter.flairState.flair, color: flairColor ?? fighter.flairColor ?? TIMINGS.flairDefaultColor };
    fighter.flairColor = next.color;

    // A different model taking over mid-flight (or the same model saved with
    // a new admin color) must replace the ring's content, not just leave the
    // old glyphs spinning under the new state.
    if (!fighter.flairRing || hasFlairChanged(previous, next)) {
      this.startFlairRing(fighter);
    }

    if (flair) {
      // Only a genuinely flair-triggering hit re-bursts and spins the ring
      // up -- an ordinary hit landing while a prior flair is still counting
      // down (startFlair leaves that state untouched) must not replay the
      // full VFX stack on every hit.
      fighter.flairLastBurstAt = now;
      this.burstFlair(fighter);
    }

    fighter.flairTimer?.remove();
    // Resynced to the state's own expiry rather than re-deriving a fresh
    // `durationMs` countdown here: a hit with no flair leaves expiresAt
    // untouched, and rescheduling from `now` on every such hit would
    // silently extend the visible flair well past its real duration.
    const remainingMs = Math.max(0, fighter.flairState.expiresAt - now);
    fighter.flairTimer = this.scene.time.delayedCall(remainingMs, () => this.destroyFlair(fighter));
  }

  /**
   * Creates the orbiting ring of glyphs (the model's name, repeated
   * marquee-style — see flair.js's buildRingChars) from the shared glyph
   * cache (`shared/flair-glyphs.js`'s `createGlyphCache`, baked once per
   * unique char/color/size onto `this.scene.flairGlyphCache`) instead of a
   * fresh Phaser Text per glyph per flair — the ~20-Text-object burst that
   * used to freeze the frame for 125-155ms. World-space Images rather than
   * children of `fighter.sprite`, matching boss/stun.js's orbiting-star
   * precedent: a container renders all its children at one depth, but half
   * the ring must render BEHIND the fighter and half in FRONT of it.
   *
   * @param {object} fighter
   * @return {void}
   */
  startFlairRing(fighter) {
    // Defensive: a ring being rebuilt mid-flight (a different flair took
    // over) must never leave the old ticker/glyphs orphaned.
    this.stopFlairRing(fighter);

    const label = fighter.flairState.flair.toUpperCase();
    const size = fighter.displaySize ?? 45;
    const fontPx = Math.max(9, Math.round(FLAIR_FONT_RATIO * size));
    const cache = this.scene.flairGlyphCache;

    // Drives flair.js's glyphState intro ramp: fixed at the ring's own
    // creation time, never re-set by a same-flair refresh (hasFlairChanged
    // gates whether this method even runs again).
    fighter.flairIntroStart = this.scene.time.now;

    // Tighter than flair.js's own default step: at this ring's radius the
    // default left visible gaps between letters, so the name read as
    // scattered characters rather than one flowing word.
    fighter.flairRing = buildRingChars(label, FLAIR_RING_CHAR_STEP).map(({ ch, phase }, index) => {
      const drawable = ch.trim().length > 0;
      const key = drawable ? cache.get(ch, fighter.flairColor, fontPx) : null;
      return {
        ch,
        phase,
        index,
        image: drawable ? this.scene.add.image(0, 0, key) : null,
        // A single dim afterimage per glyph (not the old 3-dot Circle
        // trail) — cheap, and the baked-texture glyphs already read as a
        // continuous ring without needing more than one echo.
        ghost: drawable ? this.scene.add.image(0, 0, key).setAlpha(FLAIR_GHOST_ALPHA) : null,
      };
    });

    // A handful of independently-twinkling sparkles orbiting slightly wider
    // than the name ring -- present in the approved design but missing from
    // the first pass of this port.
    fighter.flairSparkles = Array.from({ length: 5 }, () => ({
      phase: Math.random() * Math.PI * 2,
      speed: 0.85 + Math.random() * 0.7,
      sizeScale: 0.75 + Math.random() * 0.45,
      text: this.scene.addSharpText(0, 0, '✦', {
        fontFamily: 'monospace', fontSize: `${Math.round(fontPx * 0.8)}px`, color: '#f8fafc',
        padding: { x: FLAIR_SPARKLE_GLOW_BLUR, y: FLAIR_SPARKLE_GLOW_BLUR },
      })
        .setDepth(FLAIR_RING_FRONT_DEPTH)
        .setShadow(0, 0, fighter.flairColor, FLAIR_SPARKLE_GLOW_BLUR, false, true),
    }));

    fighter.flairAngle = 0;
    fighter.flairRingTicker = this.scene.time.addEvent({
      delay: 16,
      loop: true,
      callback: () => this.updateFlairRing(fighter),
    });

    // A texture baked before the page's Pixelify Sans <link> has finished
    // downloading rasterizes in the browser's fallback face and never
    // re-draws itself. Re-baking simply means clearing the stale canvas
    // texture so the cache's own get() recreates it once the font lands;
    // the identity check makes sure a ring that has since been replaced or
    // torn down is left alone.
    if (!isFlairFontReady()) {
      const built = fighter.flairRing;
      ensureFlairFont().then(() => {
        if (fighter.flairRing !== built) {
          return;
        }
        for (const entry of built) {
          if (!entry.image) {
            continue;
          }
          const key = glyphKey(entry.ch, fighter.flairColor, fontPx);
          if (this.scene.textures.exists(key)) {
            this.scene.textures.remove(key);
          }
          const freshKey = cache.get(entry.ch, fighter.flairColor, fontPx);
          entry.image.setTexture(freshKey);
          entry.ghost.setTexture(freshKey);
        }
      });
    }
  }

  /**
   * Per-tick position/depth/scale update for one fighter's orbit ring and
   * its sparkles, via flair.js's `glyphState` — advances the shared orbit
   * angle by real elapsed time (not a fixed step), sped up by
   * {@see spinMultiplier} right after a triggering hit, and places each
   * glyph on an ellipse around the fighter. The ghost is evaluated at the
   * same angle minus a small fixed lag, so it reads as trailing the real
   * glyph through the same front/back/intro/outro states rather than
   * merely being a static dim copy.
   *
   * The front depth ({@see FLAIR_RING_FRONT_DEPTH} — see its own comment
   * for the full reasoning) keeps the ring/name visible above every combat
   * VFX layer a fighter can pass through while attacking; at a lower depth
   * it was getting drawn over during the dash and strike, which read as the
   * flair "not following" the fighter.
   *
   * @param {object} fighter
   * @return {void}
   */
  updateFlairRing(fighter) {
    if (!fighter.sprite?.active || !fighter.flairRing) {
      this.stopFlairRing(fighter);
      return;
    }

    const now = this.scene.time.now;
    const dt = this.scene.game.loop.delta;
    const mult = spinMultiplier(now - (fighter.flairLastBurstAt ?? -Infinity));
    fighter.flairAngle += ((Math.PI * 2) / TIMINGS.flairOrbitPeriodMs) * mult * dt;

    const size = fighter.displaySize ?? 45;
    const rx = FLAIR_RING_RX_RATIO * size;
    const ry = FLAIR_RING_RY_RATIO * size;
    const headOffY = -Math.round(0.15 * size);
    const cx = fighter.sprite.x;
    const cy = fighter.sprite.y + headOffY;
    const opts = {
      introStart: fighter.flairIntroStart,
      // Live remaining time, not a value snapshotted once — glyphState's
      // outro drift only starts inside its own last ~700ms.
      outroLeft: fighter.flairState.expiresAt - now,
      reduced: this.scene.reducedMotion,
    };

    fighter.flairRing.forEach(entry => {
      if (!entry.image) {
        return;
      }
      const state = glyphState(fighter.flairAngle, entry.phase, entry.index, now, opts);
      const px = cx + state.x * rx;
      const py = cy + state.y * ry + state.wave;
      entry.image.setPosition(px, py);
      entry.image.setScale(state.scale);
      entry.image.setAlpha(state.alpha);
      setDepthIfChanged(entry.image, state.front ? FLAIR_RING_FRONT_DEPTH : 1);

      const ghostState = glyphState(fighter.flairAngle - FLAIR_GHOST_PHASE_GAP, entry.phase, entry.index, now, opts);
      entry.ghost.setPosition(cx + ghostState.x * rx, cy + ghostState.y * ry + ghostState.wave);
      entry.ghost.setScale(ghostState.scale);
      entry.ghost.setAlpha(ghostState.alpha * FLAIR_GHOST_ALPHA);
      setDepthIfChanged(entry.ghost, ghostState.front ? FLAIR_RING_FRONT_DEPTH : 1);
    });

    const sparkleRx = rx * 1.22;
    const sparkleRy = ry * 1.35;
    fighter.flairSparkles?.forEach(s => {
      const a = s.phase + now / (1500 / s.speed);
      const twinkle = 0.3 + 0.7 * (0.5 + 0.5 * Math.sin(now / 240 + s.phase * 4));
      s.text.setPosition(cx + Math.cos(a) * sparkleRx, cy + Math.sin(a) * sparkleRy);
      s.text.setScale(s.sizeScale);
      s.text.setAlpha(twinkle);
    });
  }

  /**
   * Tears down one fighter's orbit ring: stops its ticker and destroys every
   * glyph and its ghost. Safe to call when no ring is active.
   *
   * @param {object} fighter
   * @return {void}
   */
  stopFlairRing(fighter) {
    fighter.flairRingTicker?.remove();
    fighter.flairRingTicker = null;
    fighter.flairRing?.forEach(({ image, ghost }) => {
      if (image?.scene) image.destroy();
      if (ghost?.scene) ghost.destroy();
    });
    fighter.flairRing = null;
    fighter.flairSparkles?.forEach(({ text }) => { if (text.scene) text.destroy(); });
    fighter.flairSparkles = null;
  }

  /**
   * One-shot burst that plays alongside every triggering flair hit: a soft
   * ADD-blended glow, a thin ring expanding to ~50px, a handful of short
   * streaks, and a single ✦ pop — replacing the old 95px grey disc + 12
   * pink spokes with something smaller and cleaner. Purely decorative and
   * self-cleaning — nothing here is tracked on `fighter` beyond a
   * `flairBurstAt` timestamp, used only to skip re-bursting when a hit
   * lands while the previous burst (≤300ms) is still animating.
   *
   * @param {object} fighter
   * @return {void}
   */
  burstFlair(fighter) {
    const now = this.scene.time.now;
    if (fighter.flairBurstAt && now - fighter.flairBurstAt < 300) {
      return;
    }
    fighter.flairBurstAt = now;
    if (this.scene.reducedMotion || !fighter.pos) {
      return;
    }

    const color = fighter.flairColor;
    const colorInt = Phaser.Display.Color.HexStringToColor(color).color;
    const size = fighter.displaySize ?? 45;
    const footY = Math.round(size / 2.2);
    const x = fighter.pos.x;
    const y = fighter.pos.y - footY;

    const glow = this.scene.add.image(x, y, TextureKey.SOFTGLOW).setBlendMode(Phaser.BlendModes.ADD).setTint(colorInt).setScale(0.3).setAlpha(0.9).setDepth(1);
    this.scene.tweens.add({
      targets: glow, scale: 1.05, alpha: 0, duration: FLAIR_BURST_GLOW_MS, ease: 'Cubic.easeOut',
      onComplete: () => glow.destroy(),
    });

    const ringRadius = Math.max(10, FLAIR_BURST_RING_RADIUS_RATIO * size);
    const ring = this.scene.add.graphics().setBlendMode(Phaser.BlendModes.ADD).setDepth(1);
    const ringState = { p: 0 };
    this.scene.tweens.add({
      targets: ringState, p: 1, duration: FLAIR_BURST_RING_MS, ease: 'Cubic.easeOut',
      onUpdate: () => {
        ring.clear();
        ring.lineStyle(2 - ringState.p, colorInt, 1 - ringState.p);
        ring.strokeCircle(x, y, 6 + ringState.p * (ringRadius - 6));
      },
      onComplete: () => ring.destroy(),
    });

    const streaks = this.scene.add.particles(x, y, TextureKey.SPARK_STREAK, {
      angle: { min: 0, max: 360 },
      speed: { min: 90, max: 140 },
      scale: { start: 0.9, end: 0.2 },
      alpha: { start: 1, end: 0 },
      lifespan: { min: 200, max: 320 },
      tint: colorInt,
      blendMode: 'ADD',
      emitting: false,
    }).setDepth(1);
    streaks.explode(FLAIR_BURST_STREAK_COUNT);
    this.scene.time.delayedCall(400, () => { if (streaks.scene) streaks.destroy(); });

    const pop = this.scene.addSharpText(x, y, '✦', { fontFamily: 'monospace', fontSize: '18px', color }).setDepth(1).setScale(0.4).setAlpha(0);
    this.scene.tweens.add({
      targets: pop, scale: 1.3, alpha: 1, duration: 120, ease: 'Back.easeOut',
      onComplete: () => {
        this.scene.tweens.add({
          targets: pop, alpha: 0, scale: 0.9, duration: 220, ease: 'Quad.easeIn',
          onComplete: () => pop.destroy(),
        });
      },
    });

    // A light shake, matching the existing convention (impact.js,
    // boss/stun.js both shake the main camera on a hit/stun).
    this.scene.cameras.main.shake(160, 0.003);
  }

  /**
   * Removes a fighter's flair halo — the orbit ring and its ticker, the
   * expiry timer — and resets its flair state. Called both when the flair
   * expires and when the fighter leaves, so no tween, ticker or delayedCall
   * outlives the object it targets.
   *
   * @param {object} fighter
   * @return {void}
   */
  destroyFlair(fighter) {
    if (!fighter) {
      return;
    }
    fighter.flairTimer?.remove?.();
    fighter.flairTimer = null;
    this.stopFlairRing(fighter);
    fighter.flairState = clearFlair();
    fighter.flairColor = null;
    fighter.flairLastBurstAt = null;
  }

  removeFighter(userId) {
    const entry = this.scene.fighters.get(userId);
    if (!entry) {
      return;
    }
    this.scene.fighters.delete(userId);
    this.destroyFlair(entry);
    this.scene.tweens.add({
      targets: entry.sprite,
      alpha: 0,
      duration: 300,
      onComplete: () => { if (entry.sprite?.scene) entry.sprite.destroy(); },
    });
    if (entry.handle?.scene) {
      this.scene.tweens.add({
        targets: entry.handle,
        alpha: 0,
        duration: 300,
        onComplete: () => { if (entry.handle?.scene) entry.handle.destroy(); },
      });
    }
    this.relayoutFighters();
  }

  /**
   * Reflows all fighters into grid positions based on current count.
   *
   * @return {void}
   */
  relayoutFighters() {
    const count = this.scene.fighters.size;
    if (count === 0) {
      return;
    }
    const config = fighterDisplayConfig(count, this.scene.mode);
    // Grid slots are only for fighters without a custom (persisted or
    // click-to-moved) position — sizing a `count`-length grid and then
    // skipping some of it for custom-positioned fighters left the grid ones
    // scattered across gaps sized for fighters that were never going to sit
    // in them.
    const gridFighterCount = [...this.scene.fighters.values()].filter(e => !e.hasCustomPosition).length;
    const positions = computeFighterPositions(
      gridFighterCount,
      this.scene.layout.fighters.rowXRange,
      config.topY,
      config.perRow,
      config.rowSpacing,
    );

    let i = 0;
    for (const [userId, entry] of this.scene.fighters.entries()) {
      const gridTarget = entry.hasCustomPosition ? null : positions[i++];
      const target = entry.hasCustomPosition ? entry.pos : gridTarget;
      const newSize = config.displaySize;
      const sizeChanged = newSize !== entry.displaySize;

      if (!entry.hasCustomPosition) {
        this.scene.tweens.add({
          targets: entry.sprite,
          x: target.x,
          y: target.y,
          duration: 200,
          ease: 'Quad.easeOut',
        });
      }

      if (sizeChanged) {
        entry.displaySize = newSize;
        entry.sprite.setScale(Fighter.fighterRestScale(entry));
      }

      const scale   = entry.sprite.scaleX;
      const handleY = target.y + entry.legH * scale + NAME_PLATE_FONT_PX;
      if (config.showHandle && !entry.handle) {
        entry.handle = this.scene.addSharpText(target.x, handleY, truncateHandle(entry.handleText), NAME_PLATE_STYLE)
          .setDepth(NAME_DEPTH)
          .setColor(Number(entry.id) === Number(this.scene.currentUserId) ? YOU_NAME_COLOR : NAME_PLATE_STYLE.color);
        refreshNameWhenFontLoads(entry.handle);
      } else if (!config.showHandle && entry.handle) {
        entry.handle.destroy();
        entry.handle = null;
      } else if (entry.handle) {
        this.scene.tweens.add({
          targets: entry.handle,
          x: target.x,
          y: handleY,
          duration: 200,
          ease: 'Quad.easeOut',
        });
      }

      entry.pos = target;

      const charge = this.scene.charges.get(userId);
      if (charge) {
        // Ring is inside the container at (0,0) — rebuild if size changed
        if (sizeChanged && charge.ring?.scene) {
          this.scene.tweens.killTweensOf(charge.ring);
          charge.ring.destroy();
          charge.ring = this.scene.charge?.createChargingRing?.(entry);
          entry.sprite.addAt(charge.ring, 0);
        }
        // Trail is world-space — rebuild on size change, reposition otherwise
        if (sizeChanged && charge.trail?.scene) {
          charge.trail.stop();
          charge.trail.destroy();
          charge.trail = this.scene.charge?.createChargingTrail?.(entry);
        } else if (charge.trail?.scene) {
          const tb = entry.pos.x <= this.scene.layout.boss.anchor.x ? 1 : -1;
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

    if (this.scene.hoveredUserId != null) {
      this.scene.bubble?.showFighterTooltip?.(this.scene.hoveredUserId);
    }
  }

  /**
   * Rescales a fighter based on their total damage dealt to the boss.
   *
   * @param {number|string} userId
   * @return {void}
   */
  rescaleFighterByDamage(userId) {
    const fighter = this.scene.fighters.get(userId);
    if (!fighter) {
      return;
    }
    fighter.damageScale = damageScaleMultiplier(this.scene.damageTotals.get(userId) ?? 0, this.scene.bossState?.maxHp);
    this.tweenToRestScale(fighter);
  }

  /**
   * Tween the fighter toward its canonical rest scale without killing other tweens.
   * If an attack animation currently owns the sprite's scale, skip.
   *
   * @param {{ sprite: object, rescaleTween?: object, displaySize: number, baseSize: number, damageScale?: number }} fighter
   * @param {{ duration?: number, ease?: string }} options
   * @return {void}
   */
  tweenToRestScale(fighter, { duration = 600, ease = 'Back.easeOut' } = {}) {
    fighter.rescaleTween?.remove();
    fighter.rescaleTween = null;
    const attackOwnsScale = this.scene.tweens.getTweensOf(fighter.sprite)
      .some(tw => tw.data?.some(d => d.key === 'scaleX' || d.key === 'scaleY'));
    if (attackOwnsScale) {
      return;
    }
    const target = Fighter.fighterRestScale(fighter);
    fighter.rescaleTween = this.scene.tweens.add({
      targets: fighter.sprite,
      scaleX: target,
      scaleY: target,
      duration,
      ease,
      onComplete: () => { fighter.rescaleTween = null; },
    });
  }

  /**
   * Handles the hit event payload: plays attack animation, applies damage scaling, and triggers projectile/impact.
   *
   * @param {{ user_id: number|string, damage: number, boss_hp_after: number, slack_handle?: string }} payload
   * @return {void}
   */
  handleHit(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    this.scene.charge?.clearCharge?.(payload.user_id);
    const fighter = this.scene.fighters.get(payload.user_id);
    if (fighter) {
      this.scene.tweens.killTweensOf(fighter.sprite);
      if (fighter.handle) this.scene.tweens.killTweensOf(fighter.handle);
      // fighter.pos must be current before applyFlair: its burst places a
      // world-space particle emitter at fighter.pos, and a fighter mid-move
      // would otherwise get its spark burst rendered at a stale position.
      // moveOrigin, not the raw sprite: a hit landing mid blade-dash finds
      // the sprite inside the boss column, and recording that as home would
      // make this attack return there and strand every later move on it.
      fighter.pos = moveOrigin(fighter.sprite, fighter.pos, {
        layout: this.scene.layout,
        bossType: Boss.bossTypeOf(this.scene.bossState),
        fsize: fighter.displaySize * (fighter.damageScale ?? 1),
      });
      fighter.waypointMoving = false;
      this.applyFlair(fighter, payload.flair ?? null, payload.flair_duration_ms ?? null, payload.flair_color ?? null);
    }
    const key     = fighter?.ftype?.key ?? null;
    const attacks = fighter?.ftype?.attacks ?? null;
    const pickIdx = attacks?.length ? Phaser.Math.Between(0, attacks.length - 1) : -1;
    const flipTowardBoss = fighter ? fighter.pos.x > this.scene.layout.boss.anchor.x : false;
    if (fighter?.body) {
      const atkAnimKey = pickIdx >= 0 ? `${key}-attack${pickIdx + 1}` : `${key}-attack`;
      fighter.animState = AnimState.ATTACK;
      fighter.body.off(Phaser.Animations.Events.ANIMATION_COMPLETE);
      fighter.body.setFlipX(flipTowardBoss);
      fighter.body.play(atkAnimKey);

      fighter.body.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        if (!fighter.body?.scene) return;
        const next = this.scene.charges.has(fighter.id) ? AnimState.WALK : AnimState.IDLE;
        fighter.animState = next;
        fighter.body.setFlipX(next === AnimState.WALK ? flipTowardBoss : false);
        fighter.body.play(`${key}-${next}`);
      });
    }
    // A dedicated, always-synchronous HP tracker: `this.scene.bossState.currentHp`
    // is only mutated inside impact.apply(), which fires after this hit's
    // attack-animation delay — reading it here would race a second HitDealt
    // that arrives before the first hit's impact has landed. lastKnownBossHp
    // is updated the instant each HitDealt is handled, so hpBefore is always
    // accurate regardless of animation timing.
    const hpBefore = this.scene.lastKnownBossHp ?? this.scene.bossState?.currentHp ?? payload.boss_hp_after;
    const hitTarget = this.scene.batSwarm?.resolveHitTarget(payload.damage, hpBefore, payload.boss_hp_after) ?? null;
    this.scene.lastKnownBossHp = payload.boss_hp_after;

    const isKillShot = (payload.boss_hp_after ?? 1) <= 0;
    // Counts toward the boss totals (and the HUD board they feed) for every
    // hitter, not only ones with a fighter currently on the field — the
    // visual grow/rescale below still needs a real sprite, so it stays
    // gated on `fighter`.
    if (payload.damage > 0) {
      const prev = this.scene.damageTotals.get(payload.user_id) ?? 0;
      this.scene.damageTotals.set(payload.user_id, prev + payload.damage);
      if (fighter) {
        // Update the canonical rest scale now so the attack animation about to
        // run settles onto it; the visual grow tween itself stays delayed.
        fighter.damageScale = damageScaleMultiplier(prev + payload.damage, this.scene.bossState?.maxHp);
        this.scene.time.delayedCall(isKillShot ? 720 : 120, () => {
          this.rescaleFighterByDamage(payload.user_id);
        });
      }
    }
    const onImpact = () => {
      // A hit for the boss that just replaced the one the kill ceremony is
      // still counting down for — bossState (above) already moved on, but
      // its visuals (damage number, plate drop) wait for the ceremony too,
      // or they'd land on the old boss's death scene.
      const showImpact = () => this.scene.impact.apply(payload.boss_hp_after, hitTarget, payload.user_id);
      if (this.scene.spawnGate) {
        this.scene.spawnGate.hold(showImpact);
      } else {
        showImpact();
      }
      if (this.scene.hoveredUserId === payload.user_id) {
        this.scene.bubble?.showFighterTooltip?.(payload.user_id);
      }
      if (!isKillShot) {
        this.scene.time.delayedCall(90, () => {
          if (hitTarget) {
            this.scene.batSwarm?.reactHurt(hitTarget);
          } else {
            this.scene.boss?.playBossReact?.();
          }
        });
      }
    };
    if (fighter) {
      const attackType = fighter.ftype?.attackType ?? AttackType.BLAST;
      const effKey = (pickIdx >= 0 && attacks?.[pickIdx]?.effectFrames) ? `${key}-effect${pickIdx + 1}` : null;
      const onEffect = effKey ? (x, y) => {
        if (!fighter.body?.scene) return;
        const eff = this.scene.add.sprite(x, y, TextureKey.FIGHTERS, `${effKey}-0`)
          .setScale(fighter.sprite.scaleX * fighter.body.scaleX)
          .setFlipX(flipTowardBoss)
          .setBlendMode(Phaser.BlendModes.ADD)
          .setDepth(3)
          .play(effKey);
        eff.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => eff.destroy());
      } : null;
      this.scene.attacks.dispatch(attackType, fighter, {
        isKillShot,
        damage: payload.damage,
        maxHp: this.scene.bossState?.maxHp ?? 1,
        onImpact,
        onEffect,
        target: hitTarget,
      });
    } else {
      this.scene.time.delayedCall(TIMINGS.projectileArcMs, onImpact);
    }
  }
}
