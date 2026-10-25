import { describe, test, expect } from 'vitest';
import { FIGHTER_TYPES } from '@battlefield/config.js';
import { AttackType, TextureKey } from '@battlefield/constants.js';
import { HERO_BIG_MOVE_SHARE, heroBody, heroWeights } from '@battlefield/config/heroes.js';

const HERO_KEYS = ['iron-man', 'captain-america', 'batman', 'superman'];
const heroes = FIGHTER_TYPES.filter(f => HERO_KEYS.includes(f.key));

describe('hero fighter types', () => {
  test('big moves share a fifth of a hero\'s hits, split evenly', () => {
    expect(HERO_BIG_MOVE_SHARE).toBe(0.2);
    expect(heroWeights([{ big: false }, { big: true }, { big: true }], 0.2)).toEqual([0.8, 0.1, 0.1]);
    expect(heroWeights([{ big: false }, { big: true }], 0.2)).toEqual([0.8, 0.2]);
  });

  test('draws the hero at a roster fighter\'s height, from its own sheet', () => {
    const body = heroBody(30);
    expect(body).toMatchObject({ texture: TextureKey.HEROES, bodyPx: 18, headTop: 38 });
    expect(body.bodyScale).toBeCloseTo(0.6, 10);
    expect(body.bodyOffsetY).toBeCloseTo(2.4, 10);
  });

  test('a hero whose strips keep its feet lower in the cell still stands on the roster\'s foot row', () => {
    const body = heroBody(60, 79);
    expect(body).toMatchObject({ bodyPx: 18, headTop: 38 });
    expect(body.bodyScale).toBeCloseTo(0.3, 10);
    // the feet row, drawn around the frame's centre row 50 at bodyScale, lands on row 56 (6 below the centre)
    expect((79 - 50) * body.bodyScale + body.bodyOffsetY).toBeCloseTo(6, 10);
  });

  test('each hero rolls its approved moves, normal ones first', () => {
    const moves = Object.fromEntries(heroes.map(h => [h.key, h.attacks.map(a => `${a.move}${a.big ? '*' : ''}`)]));
    expect(moves).toEqual({
      'iron-man': ['repulsor', 'repulsor-beam*', 'micro-missiles*', 'unibeam*'],
      'captain-america': ['shield-throw', 'shield-ricochet*', 'mjolnir*'],
      batman: ['batarang', 'combo', 'explosive*', 'dollar-palm*', 'bat-swarm*'],
      superman: ['heat-vision', 'super-punch*', 'freeze-breath*'],
    });
  });

  test('normal moves share the hits left after the big moves\' fifth', () => {
    expect(heroWeights([{ big: false }, { big: false }, { big: true }, { big: true }], 0.2)).toEqual([0.4, 0.4, 0.1, 0.1]);
  });

  test('every hero is a hero-style type on the hero atlas whose weights sum to one', () => {
    expect(heroes.map(f => f.key)).toEqual(HERO_KEYS);
    for (const hero of heroes) {
      expect(hero.attackType, hero.key).toBe(AttackType.HERO);
      expect(hero.texture, hero.key).toBe(TextureKey.HEROES);
      const total = hero.attacks.reduce((sum, a) => sum + a.weight, 0);
      expect(total, hero.key).toBeCloseTo(1, 10);
      expect(hero.attacks[0].big, `${hero.key}'s first move is its normal one`).toBe(false);
      for (const attack of hero.attacks) {
        expect(attack.frameMs.length, `${hero.key} ${attack.move}`).toBe(attack.frames);
        expect(typeof attack.move).toBe('string');
      }
    }
  });
});

describe('hero body heights', () => {
  test('every hero body stands as tall as a roster fighter: its scale is set from its own measured body', () => {
    const measured = { 'iron-man': 29, 'captain-america': 50, batman: 61, superman: 30 };
    for (const hero of heroes) {
      expect(18 / hero.bodyScale, hero.key).toBeCloseTo(measured[hero.key], 6);
    }
  });
});
