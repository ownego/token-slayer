export const BG_COLOR = 0x020617;

export const BOSS_TYPES = [
  { key: 'boss-ghost',        file: '/assets/battlefield/bosses/ghost.png',        frameWidth: 32,  frameHeight: 32,  idleStart: 0, idleEnd: 3, scale: 4   },
  { key: 'boss-skeleton',     file: '/assets/battlefield/bosses/skeleton.png',     frameWidth: 32,  frameHeight: 32,  idleStart: 0, idleEnd: 3, scale: 4   },
  { key: 'boss-slime',        file: '/assets/battlefield/bosses/slime.png',        frameWidth: 32,  frameHeight: 32,  idleStart: 0, idleEnd: 4, scale: 4   },
];

export const FIGHTER_TYPES = [
  {
    key: 'knight', attackType: 'slash',
    idleFile: '/assets/battlefield/fighters/knight-idle.png',
    runFile:  '/assets/battlefield/fighters/knight-run.png',
    frameWidth: 212, frameHeight: 256,
    idleFrames: 10, runFrames: 10,
    headX: -7, headY: -81, headSize: 120,
  },
  {
    key: 'redhat', attackType: 'blast',
    idleFile: '/assets/battlefield/fighters/redhat-idle.png',
    runFile:  '/assets/battlefield/fighters/redhat-run.png',
    frameWidth: 300, frameHeight: 256,
    idleFrames: 10, runFrames: 8,
    headX: 0, headY: -80, headSize: 120,
  },
  {
    key: 'ninjagirl', attackType: 'shuriken',
    idleFile: '/assets/battlefield/fighters/ninjagirl-idle.png',
    runFile:  '/assets/battlefield/fighters/ninjagirl-run.png',
    frameWidth: 148, runFrameWidth: 185, frameHeight: 256,
    idleFrames: 10, runFrames: 10,
    headX: 0, headY: -82, headSize: 140,
  },
  {
    key: 'adventurer', attackType: 'arrow',
    idleFile: '/assets/battlefield/fighters/adventurer-idle.png',
    runFile:  '/assets/battlefield/fighters/adventurer-run.png',
    frameWidth: 302, frameHeight: 256,
    idleFrames: 10, runFrames: 8,
    headX: -23, headY: -78, headSize: 130,
  },
  {
    key: 'shinobi', attackType: 'blade',
    idleFile: '/assets/battlefield/fighters/ninja-idle.png',
    runFile:  '/assets/battlefield/fighters/ninja-run.png',
    frameWidth: 135, runFrameWidth: 202, frameHeight: 256,
    idleFrames: 10, runFrames: 10,
    headX: 0, headY: -80, headSize: 128,
    baseFlipX: true,
  },
];

export const LAYOUTS = {
  landscape: {
    logicalWidth: 960,
    logicalHeight: 540,
    boss: { anchor: { x: 480, y: 180 }, scale: 4, name: { x: 480, y: 100 } },
    hpBar: { x: 480, y: 300, width: 200, height: 12 },
    fighters: { rowXRange: [80, 880], rowY: 460, perRowMax: 14 },
  },
  portrait: {
    logicalWidth: 540,
    logicalHeight: 960,
    boss: { anchor: { x: 270, y: 310 }, scale: 5, name: { x: 270, y: 200 } },
    hpBar: { x: 270, y: 430, width: 280, height: 12 },
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
