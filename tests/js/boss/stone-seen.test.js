import { describe, expect, test } from 'vitest';
import { createSeenStore, unseenOrdinals } from '@battlefield/boss/scripts/stone-seen.js';

function fakeStorage() {
  const data = new Map();
  return {
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => data.set(k, String(v)),
    data,
  };
}

const throwingStorage = {
  getItem() { throw new Error('SecurityError'); },
  setItem() { throw new Error('SecurityError'); },
};

describe('unseenOrdinals', () => {
  test('lists every stone earned since the viewer last looked, oldest first', () => {
    expect(unseenOrdinals(1, 4)).toEqual([2, 3, 4]);
  });

  test('is empty when the viewer has already seen every stone', () => {
    expect(unseenOrdinals(3, 3)).toEqual([]);
    expect(unseenOrdinals(5, 3)).toEqual([]);
  });

  test('a first-time viewer sees every stone the boss holds', () => {
    expect(unseenOrdinals(0, 2)).toEqual([1, 2]);
  });
});

describe('createSeenStore', () => {
  test('a boss nobody has looked at yet has seen nothing', () => {
    expect(createSeenStore(fakeStorage()).read(12)).toBe(0);
  });

  test('remembers the seen count per boss number', () => {
    const storage = fakeStorage();
    const store = createSeenStore(storage);
    store.write(12, 3);

    expect(createSeenStore(storage).read(12)).toBe(3);
    expect(createSeenStore(storage).read(13)).toBe(0);
  });

  test('never moves the seen count backwards', () => {
    const store = createSeenStore(fakeStorage());
    store.write(12, 4);
    store.write(12, 2);

    expect(store.read(12)).toBe(4);
  });

  test('a later ThaNode plays every stone again, whatever the last one reached', () => {
    const storage = fakeStorage();
    createSeenStore(storage).write(57, 6);
    const next = createSeenStore(storage);

    expect(unseenOrdinals(next.read(65), 1)).toEqual([1]);
  });

  test('keeps a single record, so past bosses leave nothing behind in storage', () => {
    const storage = fakeStorage();
    const store = createSeenStore(storage);
    store.write(57, 6);
    store.write(65, 2);

    expect(storage.data.size).toBe(1);
    expect(createSeenStore(storage).read(65)).toBe(2);
  });

  test('keeps working in memory when storage throws (private window, blocked site data)', () => {
    const store = createSeenStore(throwingStorage);
    store.write(12, 2);

    expect(store.read(12)).toBe(2);
  });

  test('ignores a garbage stored value', () => {
    const storage = fakeStorage();
    storage.setItem('ts:stones-seen', 'banana');

    expect(createSeenStore(storage).read(12)).toBe(0);
  });
});
