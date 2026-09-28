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
  const { readFileSync, readdirSync } = await import('node:fs');
  const { createHash } = await import('node:crypto');
  const dir = 'resources/assets/battlefield/fighters';
  const md5 = f => createHash('md5').update(readFileSync(`${dir}/${f}`)).digest('hex');
  const byHash = new Map();
  // the same set sheet-roster.js's import.meta.glob pulls in (legacy "-attack.png" copies excluded)
  readdirSync(dir).filter(f => f.endsWith('.png') && !f.endsWith('-attack.png')).forEach(f => byHash.set(md5(f), [...(byHash.get(md5(f)) ?? []), f]));
  const used = ROSTER.flatMap(c => Object.values(c.anims).map(a => a.file));
  const clashing = used.filter(f => byHash.get(md5(f)).length > 1);

  expect(clashing).toEqual([]);
});
