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
