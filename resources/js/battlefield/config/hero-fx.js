// Effect and prop sheets the hero moves play (attacks/hero/), loaded from
// public/assets/battlefield/heroes/fx/. The PVFX entries copy each effect's
// grid manifest (cell size, frame count, per-frame ms, pivot); the props are
// hero art from the same working sets as the hero strips. Sources and
// licences: public/assets/battlefield/CREDITS.md.

/**
 * Hero effect sheets by key: a grid of `frameWidth` x `frameHeight` cells read
 * row by row, played at `frameMs` per frame and placed by `pivot`.
 *
 * @type {Object<string, {file: string, frameWidth: number, frameHeight: number, frames: number, frameMs: number, pivot: number[], loop: boolean}>}
 */
export const HERO_FX = {
  'magical-projectile': { file: 'magical-projectile.png', frameWidth: 96, frameHeight: 96, frames: 12, frameMs: 50, pivot: [48, 50], loop: false },
  'focus-charge': { file: 'focus-charge.png', frameWidth: 96, frameHeight: 96, frames: 14, frameMs: 50, pivot: [48, 48], loop: false },
  'electric-impact': { file: 'electric-impact.png', frameWidth: 96, frameHeight: 96, frames: 14, frameMs: 50, pivot: [48, 54], loop: false },
  'lattice-beam': { file: 'lattice-beam.png', frameWidth: 96, frameHeight: 96, frames: 16, frameMs: 50, pivot: [12, 48], loop: true },
  'beam-cutoff-burst': { file: 'beam-cutoff-burst.png', frameWidth: 96, frameHeight: 96, frames: 14, frameMs: 50, pivot: [70, 48], loop: false },
  'frost-nova': { file: 'frost-nova.png', frameWidth: 96, frameHeight: 96, frames: 13, frameMs: 50, pivot: [48, 61], loop: false },
  'landing-dust': { file: 'landing-dust.png', frameWidth: 96, frameHeight: 96, frames: 14, frameMs: 50, pivot: [48, 70], loop: false },
  'void-implosion': { file: 'void-implosion.png', frameWidth: 96, frameHeight: 96, frames: 14, frameMs: 50, pivot: [48, 50], loop: false },
  'solar-shrapnel': { file: 'solar-shrapnel.png', frameWidth: 96, frameHeight: 96, frames: 14, frameMs: 50, pivot: [48, 56], loop: false },
  'cinder-orchid': { file: 'cinder-orchid.png', frameWidth: 96, frameHeight: 96, frames: 42, frameMs: 50, pivot: [38, 78], loop: false },
  'warm-explosion': { file: 'warm-explosion.png', frameWidth: 96, frameHeight: 96, frames: 15, frameMs: 50, pivot: [48, 58], loop: false },
  'earth-rupture': { file: 'earth-rupture.png', frameWidth: 96, frameHeight: 96, frames: 20, frameMs: 50, pivot: [48, 71], loop: false },
  'cap-shield-spin': { file: 'cap-shield-spin.png', frameWidth: 28, frameHeight: 28, frames: 8, frameMs: 45, pivot: [14, 14], loop: true },
  'cap-mjolnir': { file: 'cap-mjolnir.png', frameWidth: 13, frameHeight: 16, frames: 1, frameMs: 1000, pivot: [6, 8], loop: true },
  'bat-batarang': { file: 'bat-batarang.png', frameWidth: 26, frameHeight: 20, frames: 4, frameMs: 60, pivot: [13, 10], loop: true },
  'bat-bat': { file: 'bat-bat.png', frameWidth: 21, frameHeight: 11, frames: 3, frameMs: 70, pivot: [10, 5], loop: true },
  // single images from the Batman sheet, played by fxPop (they grow and fade)
  'bat-spark': { file: 'bat-spark.png', frameWidth: 29, frameHeight: 32, frames: 1, frameMs: 1000, pivot: [14, 16], loop: true },
  'bat-burst': { file: 'bat-burst.png', frameWidth: 51, frameHeight: 43, frames: 1, frameMs: 1000, pivot: [25, 21], loop: true },
  'bat-explosion': { file: 'bat-explosion.png', frameWidth: 57, frameHeight: 51, frames: 1, frameMs: 1000, pivot: [28, 25], loop: true },
  'bat-cloud': { file: 'bat-cloud.png', frameWidth: 37, frameHeight: 37, frames: 1, frameMs: 1000, pivot: [18, 18], loop: true },
  'bat-sphere': { file: 'bat-sphere.png', frameWidth: 75, frameHeight: 73, frames: 1, frameMs: 1000, pivot: [37, 36], loop: true },
  'bat-smoke': { file: 'bat-smoke.png', frameWidth: 34, frameHeight: 39, frames: 1, frameMs: 1000, pivot: [17, 19], loop: true },
  'dollar-palm': { file: 'dollar-palm.png', frameWidth: 16, frameHeight: 16, frames: 1, frameMs: 1000, pivot: [8, 8], loop: true },
  'coin-spin': { file: 'coin-spin.png', frameWidth: 12, frameHeight: 12, frames: 8, frameMs: 62.5, pivot: [6, 6], loop: true },
};
