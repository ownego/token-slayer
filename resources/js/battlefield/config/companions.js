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

/**
 * The subagent-minion candidates a fighter's swarm is randomly drawn from
 * (see minions.js) — one per dispatched-but-not-yet-stopped Task subagent.
 * Idle + Walk + Attack01/Attack02; Hurt/Death were never extracted from
 * either source asset pack. Attack01/Attack02 are played purely as cosmetic
 * flourishes — an idle fidget ("khè khè / múa múa") and a clash between two
 * different fighters' minions that wander close together — neither ever
 * gates on or affects real damage. `demon-a`/`blood-monster-a` are the
 * original pack (100x100 native); `smw-goomba`/`smw-babybowser`/`smw-bowser`
 * (2026-09-24) are cropped/rebuilt from the "SMW Enemies" character sheet and
 * repacked onto the same 100x100 canvas at a matching ink height so all five
 * render at one consistent on-screen size despite very different source
 * resolutions per character — staging trial, not yet confirmed as keepers.
 */
export const MINION_TYPES = [
  {
    key: 'demon-a',
    animFiles: {
      idle:    { file: '/assets/battlefield/companions/demon-a/idle.png',    frameWidth: 100, frameHeight: 100, count: 6, rate: 8,  loop: true },
      walk:    { file: '/assets/battlefield/companions/demon-a/walk.png',    frameWidth: 100, frameHeight: 100, count: 8, rate: 10, loop: true },
      attack1: { file: '/assets/battlefield/companions/demon-a/attack1.png', frameWidth: 100, frameHeight: 100, count: 7, rate: 12 },
      attack2: { file: '/assets/battlefield/companions/demon-a/attack2.png', frameWidth: 100, frameHeight: 100, count: 7, rate: 12 },
    },
  },
  {
    key: 'blood-monster-a',
    animFiles: {
      idle:    { file: '/assets/battlefield/companions/blood-monster-a/idle.png',    frameWidth: 100, frameHeight: 100, count: 6, rate: 8,  loop: true },
      walk:    { file: '/assets/battlefield/companions/blood-monster-a/walk.png',    frameWidth: 100, frameHeight: 100, count: 8, rate: 10, loop: true },
      attack1: { file: '/assets/battlefield/companions/blood-monster-a/attack1.png', frameWidth: 100, frameHeight: 100, count: 8, rate: 12 },
      attack2: { file: '/assets/battlefield/companions/blood-monster-a/attack2.png', frameWidth: 100, frameHeight: 100, count: 8, rate: 12 },
    },
  },
  // SMW-sourced minion candidates (2026-09-24 staging trial) — frames cropped
  // from the "SMW Enemies" character sheet, hand-picked/round-trip-built per
  // character, then repacked onto a 100x100 canvas each (ink height scaled to
  // ~20px, matching MINION_CHAR_HEIGHT — see minions.js) so they render at the
  // same on-screen size as demon-a/blood-monster-a despite very different
  // source resolutions per character.
  // `?v=2` cache-busts these three specifically (bump on every future sprite
  // edit while this trio is still being tuned) — unlike the fighter atlas
  // (ATLAS_VERSION) or simple boss PNGs (static `?v=100`), no MINION_TYPES
  // entry had ANY cache-busting before this, and these companion PNGs are
  // served with a 7-day Cache-Control (nginx default for static files) —
  // caught live 2026-09-24 when a facing-direction fix (see idle/walk/
  // attack1/attack2 below) was deployed but stayed invisible in an
  // already-cached browser tab.
  {
    key: 'smw-goomba',
    animFiles: {
      idle:    { file: '/assets/battlefield/companions/smw-goomba/idle.png?v=2',    frameWidth: 100, frameHeight: 100, count: 4, rate: 6,  loop: true },
      walk:    { file: '/assets/battlefield/companions/smw-goomba/walk.png?v=2',    frameWidth: 100, frameHeight: 100, count: 3, rate: 8,  loop: true },
      attack1: { file: '/assets/battlefield/companions/smw-goomba/attack1.png?v=2', frameWidth: 100, frameHeight: 100, count: 5, rate: 10 },
      attack2: { file: '/assets/battlefield/companions/smw-goomba/attack2.png?v=2', frameWidth: 100, frameHeight: 100, count: 2, rate: 8  },
    },
  },
  {
    key: 'smw-babybowser',
    animFiles: {
      idle:    { file: '/assets/battlefield/companions/smw-babybowser/idle.png?v=2',    frameWidth: 100, frameHeight: 100, count: 8, rate: 6,  loop: true },
      walk:    { file: '/assets/battlefield/companions/smw-babybowser/walk.png?v=2',    frameWidth: 100, frameHeight: 100, count: 4, rate: 8,  loop: true },
      attack1: { file: '/assets/battlefield/companions/smw-babybowser/attack1.png?v=2', frameWidth: 100, frameHeight: 100, count: 9, rate: 12 },
      attack2: { file: '/assets/battlefield/companions/smw-babybowser/attack2.png?v=2', frameWidth: 100, frameHeight: 100, count: 6, rate: 10 },
    },
  },
  {
    key: 'smw-bowser',
    animFiles: {
      idle:    { file: '/assets/battlefield/companions/smw-bowser/idle.png?v=2',    frameWidth: 100, frameHeight: 100, count: 3, rate: 6,  loop: true },
      walk:    { file: '/assets/battlefield/companions/smw-bowser/walk.png?v=2',    frameWidth: 100, frameHeight: 100, count: 9, rate: 10, loop: true },
      attack1: { file: '/assets/battlefield/companions/smw-bowser/attack1.png?v=2', frameWidth: 100, frameHeight: 100, count: 7, rate: 12 },
      attack2: { file: '/assets/battlefield/companions/smw-bowser/attack2.png?v=2', frameWidth: 100, frameHeight: 100, count: 7, rate: 12 },
    },
  },
];

/**
 * Cosmetic burst played where two different fighters' minions clash (see
 * minions.js's `_triggerFight`) — one of the two is picked at random per
 * clash. Both strips are cropped directly out of the shared fighter atlas
 * (wizard's own attack-impact effect frames: `wizard-effect1`/`wizard-effect2`,
 * contiguous regions of `fighters/fighters-atlas.png`) into their own
 * standalone files here, the same direct-spritesheet style as every other
 * companion — reusing the frames without pulling in the whole atlas/wizard
 * loading path, and without tying a generic minion clash to one specific
 * fighter type's own attack visual.
 */
export const MINION_CLASH_EFFECTS = [
  {
    key: 'clash-burst1',
    animFiles: {
      burst: { file: '/assets/battlefield/companions/minion-clash/burst1.png', frameWidth: 100, frameHeight: 100, count: 10, rate: 20 },
    },
  },
  {
    key: 'clash-burst2',
    animFiles: {
      burst: { file: '/assets/battlefield/companions/minion-clash/burst2.png', frameWidth: 100, frameHeight: 100, count: 7, rate: 16 },
    },
  },
];
