// Generated from the approved mockup (docs/superpowers/mockups/2026-09-27-
// battlefield-redesign/fighter-sheet.html): its roster table, move labels,
// type lines and measured body boxes, with each inline base64 strip swapped
// for the real source strip under resources/assets/battlefield/fighters/.

// The legacy "<key>-attack.png" strips are byte-for-byte copies of "<key>-attack1.png":
// globbed together, the bundler emits one file for both and the other name 404s.
const STRIP_URLS = import.meta.glob(['../../../assets/battlefield/fighters/*.png', '!../../../assets/battlefield/fighters/*-attack.png'], { eager: true, query: '?url', import: 'default' });

/**
 * Every fighter the sheet can show: attack type, accent colour, and each
 * animation's frame count, frame rate and source strip file.
 *
 * @type {Array<{key: string, type: string, accent: string, anims: Object<string, {frames: number, rate: number, file: string}>}>}
 */
export const ROSTER = [
  {'key': 'soldier', 'type': 'SLASH', 'accent': '#ffbb00', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'soldier-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'soldier-walk.png'}, 'attack1': {'frames': 6, 'rate': 12, 'file': 'soldier-attack1.png'}, 'attack2': {'frames': 6, 'rate': 12, 'file': 'soldier-attack2.png'}, 'attack3': {'frames': 9, 'rate': 12, 'file': 'soldier-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'soldier-death.png'}, 'effect1': {'frames': 6, 'rate': 12, 'file': 'soldier-effect1.png'}, 'effect2': {'frames': 6, 'rate': 12, 'file': 'soldier-effect2.png'}, 'effect3': {'frames': 9, 'rate': 12, 'file': 'soldier-effect3.png'}}},
  {'key': 'knight', 'type': 'BLADE', 'accent': '#ffbb00', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'knight-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'knight-walk.png'}, 'attack1': {'frames': 7, 'rate': 12, 'file': 'knight-attack1.png'}, 'attack2': {'frames': 10, 'rate': 12, 'file': 'knight-attack2.png'}, 'attack3': {'frames': 11, 'rate': 12, 'file': 'knight-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'knight-death.png'}, 'effect1': {'frames': 7, 'rate': 12, 'file': 'knight-effect1.png'}, 'effect2': {'frames': 10, 'rate': 12, 'file': 'knight-effect2.png'}, 'effect3': {'frames': 11, 'rate': 12, 'file': 'knight-effect3.png'}}},
  {'key': 'swordsman', 'type': 'SLASH', 'accent': '#ffbb00', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'swordsman-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'swordsman-walk.png'}, 'attack1': {'frames': 7, 'rate': 12, 'file': 'swordsman-attack1.png'}, 'attack2': {'frames': 15, 'rate': 12, 'file': 'swordsman-attack2.png'}, 'attack3': {'frames': 12, 'rate': 12, 'file': 'swordsman-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'swordsman-death.png'}, 'effect1': {'frames': 7, 'rate': 12, 'file': 'swordsman-effect1.png'}, 'effect2': {'frames': 15, 'rate': 12, 'file': 'swordsman-effect2.png'}, 'effect3': {'frames': 12, 'rate': 12, 'file': 'swordsman-effect3.png'}}},
  {'key': 'axeman', 'type': 'SLASH', 'accent': '#ffbb00', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'axeman-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'axeman-walk.png'}, 'attack1': {'frames': 9, 'rate': 12, 'file': 'axeman-attack1.png'}, 'attack2': {'frames': 9, 'rate': 12, 'file': 'axeman-attack2.png'}, 'attack3': {'frames': 12, 'rate': 12, 'file': 'axeman-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'axeman-death.png'}, 'effect1': {'frames': 9, 'rate': 12, 'file': 'axeman-effect1.png'}, 'effect2': {'frames': 9, 'rate': 12, 'file': 'axeman-effect2.png'}, 'effect3': {'frames': 12, 'rate': 12, 'file': 'axeman-effect3.png'}}},
  {'key': 'orc', 'type': 'SLASH', 'accent': '#88ee44', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'orc-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'orc-walk.png'}, 'attack1': {'frames': 6, 'rate': 12, 'file': 'orc-attack1.png'}, 'attack2': {'frames': 6, 'rate': 12, 'file': 'orc-attack2.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'orc-death.png'}, 'effect1': {'frames': 6, 'rate': 12, 'file': 'orc-effect1.png'}, 'effect2': {'frames': 6, 'rate': 12, 'file': 'orc-effect2.png'}}},
  {'key': 'armored-orc', 'type': 'BLADE', 'accent': '#88ee44', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'armored-orc-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'armored-orc-walk.png'}, 'attack1': {'frames': 7, 'rate': 12, 'file': 'armored-orc-attack1.png'}, 'attack2': {'frames': 8, 'rate': 12, 'file': 'armored-orc-attack2.png'}, 'attack3': {'frames': 9, 'rate': 12, 'file': 'armored-orc-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'armored-orc-death.png'}, 'effect1': {'frames': 7, 'rate': 12, 'file': 'armored-orc-effect1.png'}, 'effect2': {'frames': 8, 'rate': 12, 'file': 'armored-orc-effect2.png'}, 'effect3': {'frames': 9, 'rate': 12, 'file': 'armored-orc-effect3.png'}}},
  {'key': 'elite-orc', 'type': 'BLAST', 'accent': '#88ee44', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'elite-orc-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'elite-orc-walk.png'}, 'attack1': {'frames': 7, 'rate': 12, 'file': 'elite-orc-attack1.png'}, 'attack2': {'frames': 11, 'rate': 12, 'file': 'elite-orc-attack2.png'}, 'attack3': {'frames': 9, 'rate': 12, 'file': 'elite-orc-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'elite-orc-death.png'}, 'effect1': {'frames': 7, 'rate': 12, 'file': 'elite-orc-effect1.png'}, 'effect2': {'frames': 11, 'rate': 12, 'file': 'elite-orc-effect2.png'}, 'effect3': {'frames': 9, 'rate': 12, 'file': 'elite-orc-effect3.png'}}},
  {'key': 'skeleton', 'type': 'SHURIKEN', 'accent': '#aaddff', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'skeleton-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'skeleton-walk.png'}, 'attack1': {'frames': 6, 'rate': 12, 'file': 'skeleton-attack1.png'}, 'attack2': {'frames': 7, 'rate': 12, 'file': 'skeleton-attack2.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'skeleton-death.png'}, 'effect1': {'frames': 6, 'rate': 12, 'file': 'skeleton-effect1.png'}, 'effect2': {'frames': 7, 'rate': 12, 'file': 'skeleton-effect2.png'}}},
  {'key': 'armored-skeleton', 'type': 'BLADE', 'accent': '#aaddff', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'armored-skeleton-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'armored-skeleton-walk.png'}, 'attack1': {'frames': 8, 'rate': 12, 'file': 'armored-skeleton-attack1.png'}, 'attack2': {'frames': 9, 'rate': 12, 'file': 'armored-skeleton-attack2.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'armored-skeleton-death.png'}, 'effect1': {'frames': 8, 'rate': 12, 'file': 'armored-skeleton-effect1.png'}, 'effect2': {'frames': 9, 'rate': 12, 'file': 'armored-skeleton-effect2.png'}}},
  {'key': 'slime', 'type': 'BLAST', 'accent': '#ddff44', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'slime-idle.png'}, 'walk': {'frames': 6, 'rate': 10, 'file': 'slime-walk.png'}, 'attack1': {'frames': 6, 'rate': 12, 'file': 'slime-attack1.png'}, 'attack2': {'frames': 12, 'rate': 12, 'file': 'slime-attack2.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'slime-death.png'}, 'effect1': {'frames': 6, 'rate': 12, 'file': 'slime-effect1.png'}, 'effect2': {'frames': 12, 'rate': 12, 'file': 'slime-effect2.png'}}},
  {'key': 'archer', 'type': 'ARROW', 'accent': '#ffee44', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'archer-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'archer-walk.png'}, 'attack1': {'frames': 9, 'rate': 12, 'file': 'archer-attack1.png'}, 'attack2': {'frames': 12, 'rate': 12, 'file': 'archer-attack2.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'archer-death.png'}, 'effect1': {'frames': 9, 'rate': 12, 'file': 'archer-effect1.png'}, 'effect2': {'frames': 12, 'rate': 12, 'file': 'archer-effect2.png'}}},
  {'key': 'werewolf', 'type': 'SLASH', 'accent': '#cc88ff', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'werewolf-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'werewolf-walk.png'}, 'attack1': {'frames': 9, 'rate': 12, 'file': 'werewolf-attack1.png'}, 'attack2': {'frames': 13, 'rate': 12, 'file': 'werewolf-attack2.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'werewolf-death.png'}, 'effect1': {'frames': 9, 'rate': 12, 'file': 'werewolf-effect1.png'}, 'effect2': {'frames': 13, 'rate': 12, 'file': 'werewolf-effect2.png'}}},
  {'key': 'werebear', 'type': 'BLAST', 'accent': '#cc88ff', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'werebear-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'werebear-walk.png'}, 'attack1': {'frames': 9, 'rate': 12, 'file': 'werebear-attack1.png'}, 'attack2': {'frames': 13, 'rate': 12, 'file': 'werebear-attack2.png'}, 'attack3': {'frames': 9, 'rate': 12, 'file': 'werebear-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'werebear-death.png'}, 'effect1': {'frames': 9, 'rate': 12, 'file': 'werebear-effect1.png'}, 'effect2': {'frames': 13, 'rate': 12, 'file': 'werebear-effect2.png'}, 'effect3': {'frames': 9, 'rate': 12, 'file': 'werebear-effect3.png'}}},
  {'key': 'orc-rider', 'type': 'ARROW', 'accent': '#88ee44', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'orc-rider-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'orc-rider-walk.png'}, 'attack1': {'frames': 8, 'rate': 12, 'file': 'orc-rider-attack1.png'}, 'attack2': {'frames': 9, 'rate': 12, 'file': 'orc-rider-attack2.png'}, 'attack3': {'frames': 11, 'rate': 12, 'file': 'orc-rider-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'orc-rider-death.png'}, 'effect1': {'frames': 8, 'rate': 12, 'file': 'orc-rider-effect1.png'}, 'effect2': {'frames': 9, 'rate': 12, 'file': 'orc-rider-effect2.png'}, 'effect3': {'frames': 11, 'rate': 12, 'file': 'orc-rider-effect3.png'}}},
  {'key': 'greatsword-skeleton', 'type': 'BLADE', 'accent': '#aaddff', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'greatsword-skeleton-idle.png'}, 'walk': {'frames': 9, 'rate': 10, 'file': 'greatsword-skeleton-walk.png'}, 'attack1': {'frames': 9, 'rate': 12, 'file': 'greatsword-skeleton-attack1.png'}, 'attack2': {'frames': 12, 'rate': 12, 'file': 'greatsword-skeleton-attack2.png'}, 'attack3': {'frames': 8, 'rate': 12, 'file': 'greatsword-skeleton-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'greatsword-skeleton-death.png'}, 'effect1': {'frames': 9, 'rate': 12, 'file': 'greatsword-skeleton-effect1.png'}, 'effect2': {'frames': 12, 'rate': 12, 'file': 'greatsword-skeleton-effect2.png'}, 'effect3': {'frames': 8, 'rate': 12, 'file': 'greatsword-skeleton-effect3.png'}}},
  {'key': 'knight-templar', 'type': 'BLADE', 'accent': '#ffbb00', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'knight-templar-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'knight-templar-walk.png'}, 'attack1': {'frames': 7, 'rate': 12, 'file': 'knight-templar-attack1.png'}, 'attack2': {'frames': 8, 'rate': 12, 'file': 'knight-templar-attack2.png'}, 'attack3': {'frames': 11, 'rate': 12, 'file': 'knight-templar-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'knight-templar-death.png'}}},
  {'key': 'lancer', 'type': 'BLADE', 'accent': '#ffbb00', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'lancer-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'lancer-walk.png'}, 'attack1': {'frames': 6, 'rate': 12, 'file': 'lancer-attack1.png'}, 'attack2': {'frames': 9, 'rate': 12, 'file': 'lancer-attack2.png'}, 'attack3': {'frames': 8, 'rate': 12, 'file': 'lancer-attack3.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'lancer-death.png'}}},
  {'key': 'wizard', 'type': 'BLAST', 'accent': '#cc88ff', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'wizard-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'wizard-walk.png'}, 'attack1': {'frames': 6, 'rate': 12, 'file': 'wizard-attack1.png'}, 'attack2': {'frames': 9, 'rate': 12, 'file': 'wizard-attack2.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'wizard-death.png'}, 'effect1': {'frames': 10, 'rate': 12, 'file': 'wizard-effect1.png'}, 'effect2': {'frames': 7, 'rate': 12, 'file': 'wizard-effect2.png'}}},
  {'key': 'priest', 'type': 'BLAST', 'accent': '#cc88ff', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'priest-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'priest-walk.png'}, 'attack1': {'frames': 9, 'rate': 12, 'file': 'priest-attack1.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'priest-death.png'}, 'effect1': {'frames': 5, 'rate': 12, 'file': 'priest-effect1.png'}}},
  {'key': 'skeleton-archer', 'type': 'ARROW', 'accent': '#aaddff', 'anims': {'idle': {'frames': 6, 'rate': 8, 'file': 'skeleton-archer-idle.png'}, 'walk': {'frames': 8, 'rate': 10, 'file': 'skeleton-archer-walk.png'}, 'attack1': {'frames': 9, 'rate': 12, 'file': 'skeleton-archer-attack1.png'}, 'death': {'frames': 4, 'rate': 6, 'file': 'skeleton-archer-death.png'}}},
];

/**
 * Each fighter's body core inside its 100px frame, weapons excluded, as
 * [x0, y0, x1, y1] — measured over all idle frames.
 *
 * @type {Object<string, number[]>}
 */
export const BOX = {
  'soldier': [44.16, 40, 55.16, 57],
  'knight': [43.04, 41, 55.04, 57],
  'swordsman': [44.64, 39, 56.64, 60],
  'axeman': [43.0, 38, 59.0, 57],
  'orc': [41.0, 42, 60.0, 57],
  'armored-orc': [39.97, 39, 61.97, 57],
  'elite-orc': [39.5, 37, 62.5, 57],
  'skeleton': [45.25, 43, 59.25, 57],
  'armored-skeleton': [45.5, 37, 59.5, 58],
  'slime': [41.0, 44, 60.0, 57],
  'archer': [43.09, 41, 56.09, 57],
  'werewolf': [47.74, 43, 58.74, 57],
  'werebear': [44.0, 40, 59.0, 57],
  'orc-rider': [37.98, 31, 65.98, 57],
  'greatsword-skeleton': [46.0, 40, 58.0, 57],
  'knight-templar': [39.0, 41, 61.0, 57],
  'lancer': [41.25, 30, 59.25, 57],
  'wizard': [44.0, 39, 58.0, 58],
  'priest': [44.0, 38, 58.0, 58],
  'skeleton-archer': [45.92, 40, 59.92, 59],
};

/**
 * Attack move names per attack type, in attack1..3 order.
 *
 * @type {Object<string, string[]>}
 */
export const LABELS = {
  'SLASH': ['Slash combo', 'Spinning slash', 'Slash finisher'],
  'BLADE': ['Blade strike', 'Blade flurry', 'Blade finisher'],
  'SHURIKEN': ['Shuriken toss', 'Shuriken storm'],
  'ARROW': ['Quick shot', 'Arrow volley', 'Piercing shot'],
  'BLAST': ['Fireball', 'Blast wave', 'Meteor'],
};

/**
 * One-line description per attack type, shown under the fighter's name.
 *
 * @type {Object<string, string>}
 */
export const TYPE_LINE = {
  'SLASH': 'Slash fighter. Hits up close with sweeping cuts.',
  'BLADE': 'Blade fighter. Dashes in for a strike.',
  'SHURIKEN': 'Shuriken thrower. Hits from range.',
  'ARROW': 'Arrow fighter. Fires from range.',
  'BLAST': 'Blast caster. Hurls fireballs at the boss.',
};

/**
 * The served URL of a fighter animation strip.
 *
 * @param {string} file Strip file name, e.g. 'orc-rider-idle.png'.
 * @return {string}
 */
export function stripUrl(file) {
  return STRIP_URLS[`../../../assets/battlefield/fighters/${file}`];
}
