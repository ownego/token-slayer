import { TextureKey } from '@battlefield/constants.js';

/** Share of a hero's hits that play one of its big moves, split evenly between them (owner's choice; tune on staging). */
export const HERO_BIG_MOVE_SHARE = 0.2;

/** The latest a hero move's main contact may land after the hit: the bat swarm's last implosion, the longest approved move. */
export const HERO_MAX_CONTACT_MS = 2100;

/** Hero effect objects alive at once above which big moves play their main contact only (tune on staging with eight heroes). */
export const HERO_EFFECT_CAP = 120;

/**
 * How each hero's preview-cell points (hands, eyes, the shield's release) map
 * into its 100 px strip cell: the pad offset the strips were built with
 * (Captain America and Batman keep their 80 px working cell's rows, so their
 * y offset is 0), a scale and a row shift (1 and 0: every set is drawn at 1x).
 *
 * @type {Object<string, {x: number, y: number, scale: number, rowShift: number}>}
 */
export const HERO_CELL = {
  'iron-man':        { x: 10, y: -23, scale: 1,  rowShift: 0 },
  'captain-america': { x: 10, y: 0,  scale: 1,  rowShift: 0 },
  batman:            { x: 7, y: 0,  scale: 1,  rowShift: 0 },
  superman:          { x: 10, y: -23, scale: 1,  rowShift: 0 },
};

/**
 * Points on each hero's preview cell (facing right) that its moves start from,
 * measured on the preview sheets: Iron Man's palm per attack frame and his arc
 * reactor, where Captain America's shield leaves and comes back and the hand
 * that catches the hammer, Batman's throwing hand in his batarang and blast
 * throws, Superman's eyes per heat-vision frame, his punching fist and his mouth.
 *
 * @type {object}
 */
export const HERO_POINTS = {
  ironManPalms: [
    { x: 46.3, y: 69.9 }, { x: 52.3, y: 65.2 }, { x: 52.2, y: 62.6 }, { x: 50.2, y: 62.9 },
    { x: 50.2, y: 62.6 }, { x: 51.3, y: 62.6 }, { x: 52.1, y: 65.7 }, { x: 50.1, y: 68.6 },
  ],
  ironManShoulders: [{ x: 38, y: 52 }, { x: 44, y: 52 }],
  ironManChest: { x: 41, y: 64 },
  capRelease: { x: 59, y: 55 },
  capCatch: { x: 57, y: 56 },
  capRim: { x: 53, y: 51 },
  capHammerHand: { x: 38, y: 62 },
  batHand: { x: 60, y: 54 },
  batBlastHand: { x: 69, y: 50 },
  supermanEyes: [54, 55, 55, 55, 55, 56, 55, 55].map(y => ({ x: 47, y })),
  supermanFist: { x: 50, y: 59 },
  supermanMouth: { x: 46, y: 58 },
};

/** A roster fighter's body is 18 px tall in its 100 px frame; heroes are drawn at that height. */
const ROSTER_BODY_PX = 18;

/** The roster's foot row in the 100 px frame (fighter/index.js's SPRITE_CHAR_BOT); a hero's feet are drawn on it. */
const FOOT_ROW = 56;

/** Row the fighter's container centres on in the 100 px frame (SPRITE_HALF_FRAME). */
const HALF_FRAME = 50;

/**
 * The body fields that draw a hero from its own atlas at a roster fighter's
 * height: its padded sheet holds a body `charH` px tall (measured from the head
 * to the soles, without the hover, jets or shadow) with its feet on `footRow`,
 * scaled down to 18 px with the feet moved onto the roster's row.
 *
 * @param {number} charH The hero's body height in its strip, in pixels.
 * @param {number} [footRow] The row of the hero's feet in its 100 px strip cell.
 * @return {{texture: string, bodyScale: number, bodyOffsetY: number, bodyPx: number, headTop: number}}
 */
export function heroBody(charH, footRow = FOOT_ROW) {
  const bodyScale = ROSTER_BODY_PX / charH;
  return {
    texture: TextureKey.HEROES,
    bodyScale,
    // the body is drawn around the frame's centre row: this puts its feet row on the roster's
    bodyOffsetY: (FOOT_ROW - HALF_FRAME) - (footRow - HALF_FRAME) * bodyScale,
    bodyPx: ROSTER_BODY_PX,
    headTop: FOOT_ROW - ROSTER_BODY_PX,
  };
}

/**
 * Pick weights for a hero's moves: the big ones share `share` of the hits
 * evenly and the normal ones share the rest.
 *
 * @param {Array<{big: boolean}>} attacks The hero's moves.
 * @param {number} share The big moves' combined share, 0..1.
 * @return {number[]}
 */
export function heroWeights(attacks, share) {
  const big = attacks.filter(a => a.big).length;
  const normal = attacks.length - big;
  return attacks.map(a => (a.big ? share / big : (1 - share) / normal));
}

/**
 * Fills each move's pick weight from HERO_BIG_MOVE_SHARE.
 *
 * @param {Array<object>} attacks The hero's moves without weights.
 * @return {Array<object>}
 */
function weighted(attacks) {
  const weights = heroWeights(attacks, HERO_BIG_MOVE_SHARE);
  return attacks.map((a, i) => ({ ...a, weight: weights[i] }));
}

/** iron-man's moves: per-frame ms from the preview harness's frame plans, the first one normal. */
export const IRON_MAN_ATTACKS = weighted([
  { move: 'repulsor', label: 'Repulsor', big: false, frames: 8, rate: 12, frameMs: [80, 90, 130, 110, 90, 90, 90, 110] },
  { move: 'repulsor-beam', label: 'Repulsor Beam', big: true, frames: 8, rate: 12, frameMs: [80, 90, 320, 200, 420, 160, 90, 110] },
  { move: 'micro-missiles', label: 'Micro-missiles', big: true, frames: 4, rate: 12, frameMs: [120, 160, 900, 120] },
  { move: 'unibeam', label: 'Unibeam', big: true, frames: 12, rate: 12, frameMs: [160, 160, 160, 160, 160, 160, 160, 160, 160, 160, 160, 140] },
]);

/** captain-america's moves: per-frame ms from the preview harness's frame plans, the first one normal. */
export const CAPTAIN_AMERICA_ATTACKS = weighted([
  { move: 'shield-throw', label: 'Shield Throw', big: false, frames: 8, rate: 12, frameMs: [90, 100, 130, 90, 90, 90, 480, 200] },
  { move: 'shield-ricochet', label: 'Ricochet', big: true, frames: 8, rate: 12, frameMs: [90, 100, 130, 90, 90, 90, 1000, 200] },
  { move: 'mjolnir', label: 'Mjolnir', big: true, frames: 9, rate: 12, frameMs: [320, 140, 130, 130, 160, 150, 150, 260, 260] },
]);

/** batman's moves: per-frame ms from the demo's frame plans, the first two normal. */
export const BATMAN_ATTACKS = weighted([
  { move: 'batarang', label: 'Batarang', big: false, frames: 8, rate: 12, frameMs: [83, 84, 83, 83, 84, 83, 83, 84] },
  { move: 'combo', label: 'Combo', big: false, frames: 6, rate: 12, frameMs: [83, 84, 83, 83, 84, 83] },
  { move: 'explosive', label: 'Explosive Batarang', big: true, frames: 5, rate: 12, frameMs: [83, 84, 83, 83, 84] },
  { move: 'dollar-palm', label: 'Dollar Palm', big: true, frames: 5, rate: 12, frameMs: [120, 300, 1500, 120, 160] },
  { move: 'bat-swarm', label: 'Bat Swarm', big: true, frames: 4, rate: 12, frameMs: [125, 125, 125, 1400] },
]);

/** superman's moves: per-frame ms from the preview harness's frame plans, the first one normal. */
export const SUPERMAN_ATTACKS = weighted([
  { move: 'heat-vision', label: 'Heat Vision', big: false, frames: 8, rate: 12, frameMs: [80, 160, 260, 140, 280, 280, 120, 110] },
  { move: 'super-punch', label: 'Super Punch', big: true, frames: 8, rate: 12, frameMs: [150, 150, 150, 150, 150, 150, 150, 150] },
  { move: 'freeze-breath', label: 'Freeze Breath', big: true, frames: 16, rate: 12, frameMs: [131, 131, 131, 131, 131, 131, 131, 131, 131, 131, 131, 131, 131, 131, 131, 135] },
]);
