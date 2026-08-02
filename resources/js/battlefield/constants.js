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
