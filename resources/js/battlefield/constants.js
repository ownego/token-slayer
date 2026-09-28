/** Phaser scene key for the battlefield scene. */
export const SCENE_KEY = 'battlefield';

/** Phaser texture/atlas keys registered at scene boot. */
export const TextureKey = {
  FIGHTERS:  'fighters',
  SPARK:     'spark',
  SPARK_STREAK: 'spark-streak',
  FIREBALL:  'fireball',
  EXPLOSION: 'explosion',
  PUFF: 'puff',
  SOFTGLOW: 'softglow',
  CLAWD_DEFAULT: 'clawd-default',
  CLAWD_CROUCH: 'clawd-crouch',
  CLAWD_ARMS: 'clawd-arms',
  CLAWD_LOOK_LEFT: 'clawd-look-left',
  CLAWD_LOOK_RIGHT: 'clawd-look-right',
  MOTE_SOFT: 'mote-soft',
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
  FIGHTER_AGENT_COUNT_CHANGED: 'fighter-agent-count-changed',
  FIGHTER_AGENT_TOOL_USED: 'fighter-agent-tool-used',
  // Local-only (never Echo-sourced): Impact's HP counter tween ticks this
  // on every retargeted-tween frame so the DOM boss plate (hud/boss-plate.js)
  // stays in sync without Impact drawing Phaser text itself.
  BOSS_HP_TICK: 'boss-hp-tick',
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
 * bats, HUD) reads smaller within the same on-screen game area, without
 * touching a single LAYOUTS/config coordinate: 1.0 shows exactly the
 * authored logicalWidth x logicalHeight world with no margin; below 1.0
 * shows proportionally more of it, so existing content occupies a smaller
 * fraction of the canvas (and reveals a matching background-colored
 * margin on every side). Reverted back to 1.0 (no zoom-out) after the
 * margin it introduced read as an unwanted border around the battlefield.
 * The HTML Damage HUD (battlefield.blade.php's fitToCanvas()) reads this
 * same constant off window.__battlefield.worldZoom to keep mirroring the
 * in-canvas TOP DAMAGE panel's position/size exactly.
 *
 * @type {number}
 */
export const WORLD_ZOOM = 1;
