import Phaser from 'phaser';
import { AttackType } from '@battlefield/constants.js';
import { slash } from './slash.js';
import { blast } from './blast.js';
import { shuriken } from './shuriken.js';
import { blade } from './blade.js';
import { arrow } from './arrow.js';

/** Handles all per-fighter attack animations and dispatches projectiles. */
export class Attacks {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
  }

  /**
   * Routes an attack to the correct per-type handler.
   *
   * @param {string} type
   * @param {object} fighter
   * @param {object} opts
   * @return {void}
   */
  dispatch(type, fighter, opts) {
    switch (type) {
      case AttackType.SLASH:    return slash(this.scene, fighter, opts);
      case AttackType.SHURIKEN: return shuriken(this.scene, fighter, opts);
      case AttackType.BLADE:    return blade(this.scene, fighter, opts);
      case AttackType.ARROW:    return arrow(this.scene, fighter, opts);
      case AttackType.BLAST:
      default:                  return blast(this.scene, fighter, opts);
    }
  }
}
