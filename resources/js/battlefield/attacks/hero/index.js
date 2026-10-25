import { HERO_CELL, HERO_MAX_CONTACT_MS } from '@battlefield/config/heroes.js';
import { planContacts } from './contacts.js';
import { cellToWorld, fighterFrame } from './points.js';
import { PROJECTILE_MOVES } from './projectile.js';
import { MULTI_MOVES } from './multi.js';
import { BESPOKE_MOVES } from './bespoke.js';
import { SIGNATURE_MOVES } from './signature.js';

/**
 * Every hero move by id. A move declares its `contacts` (ms after the hit; one
 * is main), draws its wind-up and flight in `run(ctx, contacts)` and its impact
 * visuals in `land(ctx, contact)`, and lists the HERO_FX sheets it `uses`.
 *
 * @type {Object<string, {contacts: Array<{id: string, atMs: number, main?: boolean}>, uses: string[], run: Function, land: Function}>}
 */
export const HERO_MOVES = { ...PROJECTILE_MOVES, ...MULTI_MOVES, ...BESPOKE_MOVES, ...SIGNATURE_MOVES };

/**
 * Plays a hero's picked move. The main contact calls `onImpact` (the HP drop
 * and the damage number) from the scene's HeroEffects clock, at its own time,
 * so it lands exactly once whatever happens to the visuals; the other
 * contacts only make the boss react.
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter A scene.fighters entry whose type is a hero.
 * @param {{move?: string|null, isKillShot: boolean, onImpact: Function|null, target?: {x: number, y: number}|null}} opts
 * @return {void}
 */
export function heroAttack(scene, fighter, opts) {
  const move = HERO_MOVES[opts.move] ?? HERO_MOVES[fighter.ftype?.attacks?.[0]?.move];
  const effects = scene.heroEffects;
  if (!move || !effects) {
    scene.time.delayedCall(0, () => opts.onImpact?.());
    return;
  }
  const ctx = moveContext(scene, fighter, opts.target ?? scene.layout.boss.anchor);
  const contacts = planContacts(move.contacts, {
    capped: effects.capped,
    reduced: ctx.reduced,
    killShot: Boolean(opts.isKillShot),
    maxMs: HERO_MAX_CONTACT_MS,
  });
  for (const contact of contacts) {
    effects.schedule(contact.atMs, () => {
      // the hit must land even if drawing its impact throws
      try {
        move.land(ctx, contact);
      } finally {
        if (contact.main) {
          opts.onImpact?.();
        } else {
          scene.boss?.playBossReact?.();
        }
      }
    }, { main: contact.main });
  }
  move.run(ctx, contacts);
}

/**
 * What a move needs, captured when it starts: the target point (never the
 * live boss, which may be gone by the time a late contact lands), the hero's
 * cell-to-world mapping and the scale its props are drawn at.
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter
 * @param {{x: number, y: number}} aim
 * @return {object}
 */
function moveContext(scene, fighter, aim) {
  const frame = fighterFrame(fighter);
  const cell = HERO_CELL[fighter.ftype.key];
  const target = { x: aim.x, y: aim.y };
  const boss = scene.bossSprite;
  return {
    scene,
    effects: scene.heroEffects,
    fx: { scene, effects: scene.heroEffects },
    fighter,
    target,
    ground: boss?.active ? boss.getBottomCenter().y : target.y + 50,
    dir: target.x >= frame.x ? 1 : -1,
    reduced: Boolean(scene.reducedMotion),
    pt: p => cellToWorld(p, cell, frame),
    // world px per pixel of the hero's own art (Batman's props are drawn at his preview's 2x)
    propScale: frame.bodyScale * frame.scaleX * cell.scale,
  };
}
