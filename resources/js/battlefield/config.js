export const BG_COLOR = 0x020617;

export const BOSS_TYPES = [
  { key: 'boss-ghost',        file: '/assets/battlefield/bosses/ghost.png',        frameWidth: 32,  frameHeight: 32,  idleStart: 0, idleEnd: 3, scale: 4   },
  { key: 'boss-skeleton',     file: '/assets/battlefield/bosses/skeleton.png',     frameWidth: 32,  frameHeight: 32,  idleStart: 0, idleEnd: 3, scale: 4   },
  { key: 'boss-slime',        file: '/assets/battlefield/bosses/slime.png',        frameWidth: 32,  frameHeight: 32,  idleStart: 0, idleEnd: 4, scale: 4   },
];

export const FIGHTER_TYPES = [
  {
    key: 'soldier', attackType: 'slash', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/soldier-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/soldier-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/soldier-attack.png', frames: 6, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/soldier-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'knight', attackType: 'blade', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/knight-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/knight-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/knight-attack.png', frames: 7, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/knight-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'swordsman', attackType: 'slash', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/swordsman-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/swordsman-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/swordsman-attack.png', frames: 7, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/swordsman-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'axeman', attackType: 'slash', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/axeman-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/axeman-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/axeman-attack.png', frames: 9, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/axeman-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'orc', attackType: 'slash', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/orc-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/orc-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/orc-attack.png', frames: 6, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/orc-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'armored-orc', attackType: 'blade', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/armored-orc-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/armored-orc-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/armored-orc-attack.png', frames: 7, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/armored-orc-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'elite-orc', attackType: 'blast', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/elite-orc-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/elite-orc-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/elite-orc-attack.png', frames: 7, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/elite-orc-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'skeleton', attackType: 'shuriken', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/skeleton-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/skeleton-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/skeleton-attack.png', frames: 6, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/skeleton-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'armored-skeleton', attackType: 'blade', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/armored-skeleton-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/armored-skeleton-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/armored-skeleton-attack.png', frames: 8, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/armored-skeleton-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'slime', attackType: 'blast', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/slime-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/slime-walk.png',   frames: 6, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/slime-attack.png', frames: 6, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/slime-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'archer', attackType: 'arrow', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/archer-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/archer-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/archer-attack.png', frames: 9, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/archer-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'werewolf', attackType: 'slash', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/werewolf-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/werewolf-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/werewolf-attack.png', frames: 9, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/werewolf-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'werebear', attackType: 'blast', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/werebear-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/werebear-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/werebear-attack.png', frames: 9, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/werebear-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'orc-rider', attackType: 'arrow', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/orc-rider-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/orc-rider-walk.png',   frames: 8, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/orc-rider-attack.png', frames: 8, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/orc-rider-death.png',  frames: 4, rate: 6  },
    },
  },
  {
    key: 'greatsword-skeleton', attackType: 'blade', frameWidth: 400, frameHeight: 400,
    animations: {
      idle:   { file: '/assets/battlefield/fighters/greatsword-skeleton-idle.png',   frames: 6, rate: 8  },
      walk:   { file: '/assets/battlefield/fighters/greatsword-skeleton-walk.png',   frames: 9, rate: 10 },
      attack: { file: '/assets/battlefield/fighters/greatsword-skeleton-attack.png', frames: 9, rate: 12 },
      death:  { file: '/assets/battlefield/fighters/greatsword-skeleton-death.png',  frames: 4, rate: 6  },
    },
  },
];

export const LAYOUTS = {
  landscape: {
    logicalWidth: 960,
    logicalHeight: 540,
    boss: { anchor: { x: 480, y: 180 }, scale: 4, name: { x: 480, y: 100 } },
    hpBar: { x: 480, y: 300, width: 200, height: 16 },
    fighters: { rowXRange: [80, 880], rowY: 460, perRowMax: 14 },
  },
  portrait: {
    logicalWidth: 540,
    logicalHeight: 960,
    boss: { anchor: { x: 270, y: 310 }, scale: 5, name: { x: 270, y: 200 } },
    hpBar: { x: 270, y: 430, width: 280, height: 16 },
    fighters: { rowXRange: [50, 490], rowY: 820, perRowMax: 10 },
  },
};

export const TIMINGS = {
  projectileArcMs: 320,
  flinchMs: 120,
  hpBarMs: 250,
  cameraShake: { duration: 180, intensity: 0.003 },
  chargeRingPulseMs: 600,
  fighterJoinMs: 300,
  bossSpawnMs: 500,
  bossKilledMs: 400,
};
