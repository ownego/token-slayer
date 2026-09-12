/**
 * The 5-bat boss-minion swarm. Loaded as direct per-animation spritesheets
 * (like a multi-anim BOSS_TYPES entry), not the shared fighter atlas, since
 * bats are a small fixed set of non-player-assignable entities.
 */
export const BAT_CONFIG = {
  key: 'bat',
  count: 5,
  scale: 1.4,
  animFiles: {
    flying:  { file: '/assets/battlefield/companions/bat/flying.png',  frameWidth: 100, frameHeight: 100, count: 6, rate: 10, loop: true },
    attack1: { file: '/assets/battlefield/companions/bat/attack1.png', frameWidth: 100, frameHeight: 100, count: 6, rate: 12 },
    attack2: { file: '/assets/battlefield/companions/bat/attack2.png', frameWidth: 100, frameHeight: 100, count: 7, rate: 12 },
    hurt:    { file: '/assets/battlefield/companions/bat/hurt.png',    frameWidth: 100, frameHeight: 100, count: 4, rate: 10 },
    death:   { file: '/assets/battlefield/companions/bat/death.png',   frameWidth: 100, frameHeight: 100, count: 4, rate: 8  },
  },
};

/**
 * The permanent Necromancer fixture that summons every newly-joined fighter.
 * Same direct-spritesheet loading style as BAT_CONFIG.
 */
export const NECROMANCER_CONFIG = {
  key: 'necromancer',
  scale: 2.6,
  animFiles: {
    idle:   { file: '/assets/battlefield/companions/necromancer/idle.png',   frameWidth: 100, frameHeight: 100, count: 6,  rate: 8,  loop: true },
    walk:   { file: '/assets/battlefield/companions/necromancer/walk.png',   frameWidth: 100, frameHeight: 100, count: 6,  rate: 10, loop: true },
    summon: { file: '/assets/battlefield/companions/necromancer/summon.png', frameWidth: 100, frameHeight: 100, count: 10, rate: 8 },
    // The ground-circle burst spawned at a newly-joined fighter's own
    // position (not on the Necromancer's own sprite) — see Necromancer#spawnSummonCircle.
    // Slower rate than its frame count alone suggests: it needs to stay
    // clearly visible while the fighter rises through it, not just flash by.
    circle: { file: '/assets/battlefield/companions/necromancer/summon-circle.png', frameWidth: 100, frameHeight: 100, count: 7, rate: 4 },
    // Source strip for the derived vanish/appear teleport transition (played
    // forward to vanish, reversed to reappear) — see Necromancer#_ensureAnims.
    death: { file: '/assets/battlefield/companions/necromancer/death.png', frameWidth: 100, frameHeight: 100, count: 9, rate: 14 },
    // Burst spawned at the Necromancer's own spot just before it reappears
    // there — see Necromancer#_spawnAppearBurst.
    appearBurst: { file: '/assets/battlefield/companions/necromancer/appear-burst.png', frameWidth: 100, frameHeight: 100, count: 6, rate: 12 },
  },
};
