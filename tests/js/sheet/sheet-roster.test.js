import { expect, test } from 'vitest';
import { FIGHTER_TYPES } from '@battlefield/config/fighters.js';
import { BOX, ROSTER, stripUrl } from '@battlefield/sheet/sheet-roster.js';

test('the sheet roster covers every battlefield fighter, each with a measured body box', () => {
  const keys = ROSTER.map(c => c.key).sort();

  expect(keys).toEqual(FIGHTER_TYPES.map(t => t.key).sort());
  keys.forEach(key => expect(BOX[key]).toHaveLength(4));
});

test('every animation strip the roster names resolves to a served file', () => {
  ROSTER.forEach(c => Object.values(c.anims).forEach(a => expect(stripUrl(a.file), a.file).toBeTruthy()));
});

test('every strip the roster uses is the one copy of its bytes in the folder (the bundler emits one name for identical files, and the other name 404s)', async () => {
  const { existsSync, readFileSync, readdirSync } = await import('node:fs');
  const { createHash } = await import('node:crypto');
  const dirs = ['resources/assets/battlefield/fighters', 'resources/assets/battlefield/heroes'];
  const pathOf = f => dirs.map(d => `${d}/${f}`).find(p => existsSync(p));
  const md5 = f => createHash('md5').update(readFileSync(pathOf(f))).digest('hex');
  const byHash = new Map();
  // the same sets sheet-roster.js's import.meta.glob calls pull in (legacy "-attack.png" copies excluded)
  dirs.forEach(dir => readdirSync(dir).filter(f => f.endsWith('.png') && !f.endsWith('-attack.png')).forEach(f => byHash.set(md5(f), [...(byHash.get(md5(f)) ?? []), f])));
  const used = ROSTER.flatMap(c => Object.values(c.anims).map(a => a.file));
  const clashing = used.filter(f => byHash.get(md5(f)).length > 1);

  expect(clashing).toEqual([]);
});

test('a hero\'s moves in the sheet are the field\'s: same names, same big flags, same length', () => {
  for (const ft of FIGHTER_TYPES.filter(t => t.attackType === 'hero')) {
    const entry = ROSTER.find(c => c.key === ft.key);
    expect(entry.labels, ft.key).toEqual(ft.attacks.map(a => a.label));
    expect(entry.big, ft.key).toEqual(ft.attacks.map(a => a.big));
    ft.attacks.forEach((a, i) => {
      const anim = entry.anims[`attack${i + 1}`];
      const sheetMs = (anim.frames / anim.rate) * 1000;
      expect(sheetMs, `${ft.key} ${a.move}`).toBeCloseTo(a.frameMs.reduce((sum, ms) => sum + ms, 0), 0);
    });
  }
});

test('a hero\'s body box is its body, not its hover, jets or shadow, so the sheet sizes it like the field does', () => {
  for (const ft of FIGHTER_TYPES.filter(t => t.attackType === 'hero')) {
    const [, y0, , y1] = BOX[ft.key];
    expect(y1 - y0, ft.key).toBeCloseTo(18 / ft.bodyScale, 6);
  }
});

test('a standing hero\'s body box ends on its strip\'s foot row, so the sheet puts its feet on the floor', async () => {
  const sharp = (await import('sharp')).default;
  // the fliers' boxes end at their hover, above the shadow
  for (const ft of FIGHTER_TYPES.filter(t => t.attackType === 'hero' && !t.flies)) {
    const { data, info } = await sharp(`resources/assets/battlefield/heroes/${ft.key}-idle.png`)
      .extract({ left: 0, top: 0, width: 100, height: 100 }).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    let lowest = -1;
    for (let i = 3; i < data.length; i += info.channels) {
      if (data[i] > 0) lowest = Math.floor(i / info.channels / info.width);
    }
    expect(BOX[ft.key][3], ft.key).toBe(lowest + 1);
  }
});
