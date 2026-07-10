import { AttackType } from '../constants.js';

const FIRE_CHARGE   = [0x991100, 0xcc3300, 0xdd6600, 0xee9900, 0xffbb00];
const PURPLE_CHARGE = [0x4400aa, 0x6600cc, 0x8833dd, 0xaa55ee, 0xcc88ff];
const GREEN_CHARGE  = [0x005500, 0x117700, 0x33aa00, 0x55cc11, 0x88ee44];
const BLUE_CHARGE   = [0x003366, 0x0055aa, 0x1188cc, 0x44bbee, 0xaaddff];
const ACID_CHARGE   = [0x336600, 0x558800, 0x88bb00, 0xaadd00, 0xddff44];
const GOLD_CHARGE   = [0x886600, 0xaa8800, 0xccaa00, 0xeecc00, 0xffee44];

export const FIGHTER_TYPES = [
  {
    key: 'soldier', attackType: AttackType.SLASH, chargeColors: FIRE_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 6, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 6,  rate: 12, effectFrames: 6  },
      { frames: 6,  rate: 12, effectFrames: 6  },
      { frames: 9,  rate: 12, effectFrames: 9  },
    ],
  },
  {
    key: 'knight', attackType: AttackType.BLADE, chargeColors: FIRE_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 7, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 7,  rate: 12, effectFrames: 7  },
      { frames: 10, rate: 12, effectFrames: 10 },
      { frames: 11, rate: 12, effectFrames: 11 },
    ],
  },
  {
    key: 'swordsman', attackType: AttackType.SLASH, chargeColors: FIRE_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 7, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 7,  rate: 12, effectFrames: 7  },
      { frames: 15, rate: 12, effectFrames: 15 },
      { frames: 12, rate: 12, effectFrames: 12 },
    ],
  },
  {
    key: 'axeman', attackType: AttackType.SLASH, chargeColors: FIRE_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 9, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 9,  rate: 12, effectFrames: 9  },
      { frames: 9,  rate: 12, effectFrames: 9  },
      { frames: 12, rate: 12, effectFrames: 12 },
    ],
  },
  {
    key: 'orc', attackType: AttackType.SLASH, chargeColors: GREEN_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 6, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 6, rate: 12, effectFrames: 6 },
      { frames: 6, rate: 12, effectFrames: 6 },
    ],
  },
  {
    key: 'armored-orc', attackType: AttackType.BLADE, chargeColors: GREEN_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 7, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 7, rate: 12, effectFrames: 7 },
      { frames: 8, rate: 12, effectFrames: 8 },
      { frames: 9, rate: 12, effectFrames: 9 },
    ],
  },
  {
    key: 'elite-orc', attackType: AttackType.BLAST, chargeColors: GREEN_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 7, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 7,  rate: 12, effectFrames: 7  },
      { frames: 11, rate: 12, effectFrames: 11 },
      { frames: 9,  rate: 12, effectFrames: 9  },
    ],
  },
  {
    key: 'skeleton', attackType: AttackType.SHURIKEN, chargeColors: BLUE_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 6, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 6, rate: 12, effectFrames: 6 },
      { frames: 7, rate: 12, effectFrames: 7 },
    ],
  },
  {
    key: 'armored-skeleton', attackType: AttackType.BLADE, chargeColors: BLUE_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 8, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 8, rate: 12, effectFrames: 8 },
      { frames: 9, rate: 12, effectFrames: 9 },
    ],
  },
  {
    key: 'slime', attackType: AttackType.BLAST, chargeColors: ACID_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 6, rate: 10 },
      attack: { frames: 6, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 6,  rate: 12, effectFrames: 6  },
      { frames: 12, rate: 12, effectFrames: 12 },
    ],
  },
  {
    key: 'archer', attackType: AttackType.ARROW, chargeColors: GOLD_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 9, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 9,  rate: 12, effectFrames: 9  },
      { frames: 12, rate: 12, effectFrames: 12 },
    ],
  },
  {
    key: 'werewolf', attackType: AttackType.SLASH, chargeColors: PURPLE_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 9, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 9,  rate: 12, effectFrames: 9  },
      { frames: 13, rate: 12, effectFrames: 13 },
    ],
  },
  {
    key: 'werebear', attackType: AttackType.BLAST, chargeColors: PURPLE_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 9, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 9,  rate: 12, effectFrames: 9  },
      { frames: 13, rate: 12, effectFrames: 13 },
      { frames: 9,  rate: 12, effectFrames: 9  },
    ],
  },
  {
    key: 'orc-rider', attackType: AttackType.ARROW, chargeColors: GREEN_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 8, rate: 10 },
      attack: { frames: 8, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 8,  rate: 12, effectFrames: 8  },
      { frames: 9,  rate: 12, effectFrames: 9  },
      { frames: 11, rate: 12, effectFrames: 11 },
    ],
  },
  {
    key: 'greatsword-skeleton', attackType: AttackType.BLADE, chargeColors: BLUE_CHARGE,
    animations: {
      idle:   { frames: 6, rate: 8  },
      walk:   { frames: 9, rate: 10 },
      attack: { frames: 9, rate: 12 },
      death:  { frames: 4, rate: 6  },
    },
    attacks: [
      { frames: 9,  rate: 12, effectFrames: 9  },
      { frames: 12, rate: 12, effectFrames: 12 },
      { frames: 8,  rate: 12, effectFrames: 8  },
    ],
  },
];
