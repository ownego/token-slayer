import { describe, expect, test } from 'vitest';
import { BusEvent, ECHO_EVENT_MAP } from '@battlefield/constants.js';

// index.js is where the Echo listeners are bound, but it assigns `window.bootBattlefield`
// when it loads and so cannot be imported under Vitest; the map it binds lives in
// constants.js, which is what these tests read.
describe('ECHO_EVENT_MAP', () => {
  test('re-emits a FighterMinionsChanged broadcast as MINIONS_CHANGED', () => {
    expect(ECHO_EVENT_MAP.FighterMinionsChanged).toBe(BusEvent.MINIONS_CHANGED);
  });

  test('carries the other two broadcasts that carry a minion pool', () => {
    expect(ECHO_EVENT_MAP.FighterJoined).toBe(BusEvent.FIGHTER_JOINED);
    expect(ECHO_EVENT_MAP.FighterCharging).toBe(BusEvent.FIGHTER_CHARGING);
  });

  test('every broadcast maps to a bus event the scene knows', () => {
    const busEvents = new Set(Object.values(BusEvent));

    for (const [broadcast, busEvent] of Object.entries(ECHO_EVENT_MAP)) {
      expect(busEvents.has(busEvent), `${broadcast} maps to an unknown bus event`).toBe(true);
    }
  });

  test('no two broadcasts share a bus event', () => {
    const busEvents = Object.values(ECHO_EVENT_MAP);

    expect(new Set(busEvents).size).toBe(busEvents.length);
  });
});
