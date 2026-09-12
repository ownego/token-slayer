/** Phaser scene key for the battlefield scene. */
export const SCENE_KEY = 'battlefield';

/** Phaser texture/atlas keys registered at scene boot. */
export const TextureKey = {
  FIGHTERS:  'fighters',
  SPARK:     'spark',
  FIREBALL:  'fireball',
  EXPLOSION: 'explosion',
};

/** Bus event identifiers shared between scene wiring and Echo listener. */
export const BusEvent = {
  HIT:              'hit',
  BOSS_SPAWNED:     'boss-spawned',
  BOSS_KILLED:      'boss-killed',
  FIGHTER_JOINED:   'fighter-joined',
  FIGHTER_CHARGING: 'fighter-charging',
  FIGHTER_IDLED:    'fighter-idled',
  FIGHTER_MOVED:    'fighter-moved',
  POSITIONS_RESYNCED: 'positions-resynced',
  FIGHTER_CHARGE_CLEARED: 'fighter-charge-cleared',
  CHARACTER_CHANGED: 'character-changed',
};

/** Animation state identifiers shared across scene and managers. */
export const AnimState = {
  IDLE: 'idle',
  WALK: 'walk',
  ATTACK: 'attack',
};

/** Attack type identifiers shared across fighter config, attacks, and projectile. */
export const AttackType = {
  SLASH:    'slash',
  BLAST:    'blast',
  SHURIKEN: 'shuriken',
  BLADE:    'blade',
  ARROW:    'arrow',
};

/** Boss patrol phase identifiers. */
export const BossPhase = {
  IDLE: 'idle',
  MOVE: 'move',
};

/** Abyssal Dreadknight attack animation keys. */
export const DreadknightAttack = {
  SLAM:      'slam',
  SLASH_LOW: 'slash-low',
  THRUST:    'thrust',
  SPIN:      'spin',
  DASH:      'dash',
};

/**
 * Uniform scale applied to a fighter sprite's native 100x100 atlas frame in
 * the character-select preview modal. Shared by character-preview/scene.js
 * (the live Phaser tiles) and fighter/preview.js (the static 2D-canvas
 * thumbnails) so both render a character at the exact same size — the v52
 * design mockup's own move-thumb/preview-sprite CSS rules render each
 * fighter frame at a fixed multiple of its native size and let the crop
 * circle/box clip the excess, never stretching to fill it.
 *
 * @type {number}
 */
export const PREVIEW_SPRITE_SCALE = 2.4;

/**
 * Extra camera zoom-out applied on top of renderScaleFor()'s crispness
 * factor (index.js) — everyone/everything (boss, fighters, Necromancer,
 * bats, HUD) reads slightly smaller within the same on-screen game area,
 * without touching a single LAYOUTS/config coordinate: 1.0 shows exactly
 * the authored logicalWidth x logicalHeight world; below 1.0 shows
 * proportionally more of it, so existing content occupies a smaller
 * fraction of the canvas (and reveals a matching background-colored
 * margin — kept close to 1.0 so that margin stays barely noticeable).
 * The HTML Damage HUD (battlefield.blade.php's fitToCanvas()) reads this
 * same constant off window.__battlefield.worldZoom to keep mirroring the
 * in-canvas TOP DAMAGE panel's position/size exactly.
 *
 * @type {number}
 */
export const WORLD_ZOOM = 0.95;
