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
 * Purely cosmetic: fights between two fighters' minions never touch damage.
 *
 * Per type:
 * - `charHeight` — the character's ink height in its own frame, in frame px.
 *   minions.js scales every type to the same on-screen height from this, so
 *   a type can ship at its source's native resolution (bigger frames) and
 *   still render the same size as the others. Every strip of one type must
 *   share one frame size for this to hold.
 * - `attacks` — what the minion may play when it attacks (picked at random).
 *   `hitFrame` is the 0-based frame where the blow connects: the victim's
 *   reaction and the clash burst start there. `travel` marks a leap/dash:
 *   the attacker is moved to its target between frames `from` and `to`
 *   (the strip itself stays in place). `fidget: false` keeps an attack out
 *   of the idle fidget, where there is no target to travel to.
 * - `reaction` — what the victim plays when hit. `holdMs` keeps its last
 *   frame on screen, `getUp` then plays the strip backwards (a death strip
 *   reads as falling down, lying there, then getting back up).
 *
 * Every effect is drawn facing RIGHT; minions.js flips the attacker toward
 * its target and the victim toward its attacker.
 *
 * `demon-a`/`blood-monster-a` are the Zerie Tiny RPG pack at native 100px.
 * The three `smw-*` types come from the "SMW Enemies" sheet with self-made
 * attack/hurt strips, shrunk from native resolution to a height between it
 * and the old 20px (goomba 24->22, babybowser 30->25, bowser 38->29) with a
 * palette-snapping shrink, so pixels read chunkier without the old repack's
 * blur. The native set + the converter live in docs/sprite-work/_native-backup/
 * (local only). Bump `?v=` on every edit of these files (7-day Cache-Control).
 */
export const MINION_TYPES = [
  {
    key: 'demon-a',
    charHeight: 20,
    animFiles: {
      idle:    { file: '/assets/battlefield/companions/demon-a/idle.png',    frameWidth: 100, frameHeight: 100, count: 6, rate: 8,  loop: true },
      walk:    { file: '/assets/battlefield/companions/demon-a/walk.png',    frameWidth: 100, frameHeight: 100, count: 8, rate: 10, loop: true },
      attack1: { file: '/assets/battlefield/companions/demon-a/attack1.png', frameWidth: 100, frameHeight: 100, count: 7, rate: 12 },
      attack2: { file: '/assets/battlefield/companions/demon-a/attack2.png', frameWidth: 100, frameHeight: 100, count: 7, rate: 12 },
      death:   { file: '/assets/battlefield/companions/demon-a/death.png',   frameWidth: 100, frameHeight: 100, count: 4, rate: 6 },
    },
    attacks: [{ anim: 'attack1', hitFrame: 4 }, { anim: 'attack2', hitFrame: 3 }],
    reaction: { anim: 'death', holdMs: 2000, getUp: true },
  },
  {
    key: 'blood-monster-a',
    charHeight: 20,
    animFiles: {
      idle:    { file: '/assets/battlefield/companions/blood-monster-a/idle.png',    frameWidth: 100, frameHeight: 100, count: 6, rate: 8,  loop: true },
      walk:    { file: '/assets/battlefield/companions/blood-monster-a/walk.png',    frameWidth: 100, frameHeight: 100, count: 8, rate: 10, loop: true },
      attack1: { file: '/assets/battlefield/companions/blood-monster-a/attack1.png', frameWidth: 100, frameHeight: 100, count: 8, rate: 12 },
      attack2: { file: '/assets/battlefield/companions/blood-monster-a/attack2.png', frameWidth: 100, frameHeight: 100, count: 8, rate: 12 },
      death:   { file: '/assets/battlefield/companions/blood-monster-a/death.png',   frameWidth: 100, frameHeight: 100, count: 4, rate: 6 },
    },
    attacks: [{ anim: 'attack1', hitFrame: 4 }, { anim: 'attack2', hitFrame: 5 }],
    reaction: { anim: 'death', holdMs: 2000, getUp: true },
  },
  {
    key: 'smw-goomba',
    charHeight: 22,
    animFiles: {
      idle:               { file: '/assets/battlefield/companions/smw-goomba/idle.png?v=5',             frameWidth: 110, frameHeight: 110, count: 8,  rate: 6,  loop: true },
      walk:               { file: '/assets/battlefield/companions/smw-goomba/walk.png?v=5',             frameWidth: 110, frameHeight: 110, count: 4,  rate: 8,  loop: true },
      'attack-slam':      { file: '/assets/battlefield/companions/smw-goomba/attack-slam.png?v=5',      frameWidth: 110, frameHeight: 110, count: 9,  rate: 10 },
      'attack-shellspin': { file: '/assets/battlefield/companions/smw-goomba/attack-shellspin.png?v=5', frameWidth: 110, frameHeight: 110, count: 10, rate: 12 },
      'attack-punch':     { file: '/assets/battlefield/companions/smw-goomba/attack-punch.png?v=5',     frameWidth: 110, frameHeight: 110, count: 9,  rate: 12 },
      'attack-headbutt':  { file: '/assets/battlefield/companions/smw-goomba/attack-headbutt.png?v=5',  frameWidth: 110, frameHeight: 110, count: 10, rate: 12 },
      'attack-banana':    { file: '/assets/battlefield/companions/smw-goomba/attack-banana.png?v=5',    frameWidth: 110, frameHeight: 110, count: 10, rate: 12 },
      hurt:               { file: '/assets/battlefield/companions/smw-goomba/hurt.png?v=5',             frameWidth: 110, frameHeight: 110, count: 20, rate: 7 },
    },
    attacks: [
      { anim: 'attack-slam', hitFrame: 5, travel: { from: 2, to: 5 } },
      { anim: 'attack-shellspin', hitFrame: 6 },
      { anim: 'attack-punch', hitFrame: 4 },
      { anim: 'attack-headbutt', hitFrame: 4 },
      { anim: 'attack-banana', hitFrame: 7 },
    ],
    reaction: { anim: 'hurt' },
  },
  {
    key: 'smw-babybowser',
    charHeight: 25,
    animFiles: {
      idle:           { file: '/assets/battlefield/companions/smw-babybowser/idle.png?v=5',         frameWidth: 125, frameHeight: 125, count: 8,  rate: 6,  loop: true },
      walk:           { file: '/assets/battlefield/companions/smw-babybowser/walk.png?v=5',         frameWidth: 125, frameHeight: 125, count: 4,  rate: 8,  loop: true },
      'attack-fire':  { file: '/assets/battlefield/companions/smw-babybowser/attack-fire.png?v=5',  frameWidth: 125, frameHeight: 125, count: 13, rate: 12 },
      'attack-brush': { file: '/assets/battlefield/companions/smw-babybowser/attack-brush.png?v=5', frameWidth: 125, frameHeight: 125, count: 11, rate: 12 },
      hurt:           { file: '/assets/battlefield/companions/smw-babybowser/hurt.png?v=5',         frameWidth: 125, frameHeight: 125, count: 20, rate: 7 },
    },
    attacks: [{ anim: 'attack-fire', hitFrame: 5 }, { anim: 'attack-brush', hitFrame: 8 }],
    reaction: { anim: 'hurt' },
  },
  {
    key: 'smw-bowser',
    charHeight: 29,
    animFiles: {
      idle:              { file: '/assets/battlefield/companions/smw-bowser/idle.png?v=5',            frameWidth: 145, frameHeight: 145, count: 8,  rate: 6,  loop: true },
      walk:              { file: '/assets/battlefield/companions/smw-bowser/walk.png?v=5',            frameWidth: 145, frameHeight: 145, count: 9,  rate: 10, loop: true },
      'attack-fireball': { file: '/assets/battlefield/companions/smw-bowser/attack-fireball.png?v=5', frameWidth: 145, frameHeight: 145, count: 11, rate: 12 },
      'attack-claw':     { file: '/assets/battlefield/companions/smw-bowser/attack-claw.png?v=5',     frameWidth: 145, frameHeight: 145, count: 9,  rate: 12 },
      'attack-dash':     { file: '/assets/battlefield/companions/smw-bowser/attack-dash.png?v=5',     frameWidth: 145, frameHeight: 145, count: 9,  rate: 12 },
      hurt:              { file: '/assets/battlefield/companions/smw-bowser/hurt.png?v=5',            frameWidth: 145, frameHeight: 145, count: 20, rate: 7 },
    },
    attacks: [
      { anim: 'attack-fireball', hitFrame: 8 },
      { anim: 'attack-claw', hitFrame: 4 },
      { anim: 'attack-dash', hitFrame: 5, travel: { from: 3, to: 5 }, fidget: false },
    ],
    reaction: { anim: 'hurt' },
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
