import { describe, expect, it } from 'vitest';
import { cameoFrame, hopPlan, nextCameoDelay, peekPlan, nextCameoKind, pickRideCloud, dancePlan, rideCloudY } from '@battlefield/environment/clawd-cameo.js';

const lcg = seed => {
  let s = seed;
  return () => (s = (s * 16807) % 2147483647) / 2147483647;
};
const ridge = x => 200 + Math.round(30 * Math.sin(x / 80));
const box = { width: 1600, height: 24, skyline: ridge, avoid: [{ x0: 1100, x1: 1270 }] };

describe('nextCameoDelay', () => {
  it('a regular: the first within 8s of loading, then every 10-25s', () => {
    const lo = () => 0;
    const hi = () => 0.999;
    expect(nextCameoDelay(true, lo)).toBeGreaterThanOrEqual(3000);
    expect(nextCameoDelay(true, hi)).toBeLessThanOrEqual(8000);
    expect(nextCameoDelay(false, lo)).toBeGreaterThanOrEqual(10000);
    expect(nextCameoDelay(false, hi)).toBeLessThanOrEqual(25000);
  });
});

describe('nextCameoKind', () => {
  it('the hopping Clawd and the shy one take turns behind the mountains, the shy one first', () => {
    expect(nextCameoKind(null)).toBe('peek');
    expect(nextCameoKind('peek')).toBe('hop');
    expect(nextCameoKind('hop')).toBe('peek');
  });
});
describe('peekPlan', () => {
  it('starts and ends hidden below the ridge, and peeks only its head above it', () => {
    const plan = peekPlan(box, lcg(5));
    const first = cameoFrame(plan, 0);
    const last = cameoFrame(plan, plan.duration);
    expect(first.y - box.height).toBeGreaterThanOrEqual(ridge(Math.round(first.x)));
    expect(last.y - box.height).toBeGreaterThanOrEqual(ridge(Math.round(last.x)));
    const peek = plan.keys.find(k => k.pose === 'look-left' || k.pose === 'look-right');
    const top = peek.y - box.height;
    expect(top).toBeLessThan(ridge(peek.x));
    expect(peek.y).toBeGreaterThan(ridge(peek.x));
  });

  it('looks both ways, shyly', () => {
    const poses = peekPlan(box, lcg(5)).keys.map(k => k.pose);
    expect(poses).toContain('look-left');
    expect(poses).toContain('look-right');
  });

  it('never peeks from behind the castle', () => {
    for (let s = 1; s < 40; s++) {
      peekPlan(box, lcg(s)).keys.forEach(k => {
        expect(k.x < 1100 - 20 || k.x > 1270 + 20).toBe(true);
      });
    }
  });
});

describe('hopPlan', () => {
  it('hops several times along the ridge, airborne above it at each apex', () => {
    const plan = hopPlan(box, lcg(7));
    const hops = plan.keys.filter(k => k.lift > 0);
    expect(hops.length).toBeGreaterThanOrEqual(3);
    const xs = plan.keys.map(k => k.x);
    expect(Math.abs(xs[xs.length - 1] - xs[0])).toBeGreaterThan(box.height * 3);
    const i = plan.keys.indexOf(hops[0]);
    const mid = cameoFrame(plan, (plan.keys[i - 1].t + hops[0].t) / 2);
    expect(mid.y).toBeLessThanOrEqual(ridge(Math.round(mid.x)) + 2);
    expect(mid.pose).toBe('arms-up');
  });

  it('stays on screen and off the castle', () => {
    for (let s = 1; s < 40; s++) {
      hopPlan(box, lcg(s)).keys.forEach(k => {
        expect(k.x).toBeGreaterThan(0);
        expect(k.x).toBeLessThan(box.width);
        expect(k.x < 1100 - 20 || k.x > 1270 + 20).toBe(true);
      });
    }
  });
});

describe('pickRideCloud', () => {
  it('rides the lowest cloud wide enough to dance on (the one most often seen under the HUD)', () => {
    const clouds = [{ y: 40, w: 300 }, { y: 70, w: 60 }, { y: 62, w: 120 }, { y: 50, w: 200 }];
    expect(pickRideCloud(clouds, 36)).toBe(2);
  });

  it('falls back to the widest when none is wide enough', () => {
    expect(pickRideCloud([{ y: 40, w: 30 }, { y: 70, w: 50 }], 36)).toBe(1);
  });
});

describe('rideCloudY', () => {
  it('lowers Clawd\'s cloud to just under the HUD band (half the horizon), never raises it', () => {
    expect(rideCloudY(40, 186)).toBe(93);
    expect(rideCloudY(120, 186)).toBe(120);
  });
});

describe('dancePlan', () => {
  const opts = { seatY: 100, riderH: 20 };
  const offsets = [0, 110, 220];

  it('loops seamlessly: it ends where and how it starts, always shown', () => {
    const plan = dancePlan(offsets, opts, lcg(3));
    const first = plan.keys[0];
    const last = plan.keys[plan.keys.length - 1];
    expect(last.x).toBe(first.x);
    expect(last.y).toBe(first.y);
    expect(last.pose).toBe(first.pose);
    plan.keys.forEach(k => expect(k.alpha ?? 1).toBe(1));
  });

  it('hops to every cloud of the train, airborne and arms up between them', () => {
    const plan = dancePlan(offsets, opts, lcg(3));
    offsets.forEach(x => expect(plan.keys.some(k => k.x === x)).toBe(true));
    const hops = plan.keys.filter(k => k.lift > 0);
    expect(hops.length).toBeGreaterThanOrEqual(offsets.length - 1);
    const i = plan.keys.indexOf(hops[0]);
    const mid = cameoFrame(plan, (plan.keys[i - 1].t + hops[0].t) / 2);
    expect(mid.y).toBeLessThan(opts.seatY - opts.riderH * 0.5);
    expect(mid.pose).toBe('arms-up');
  });
});

describe('cameoFrame', () => {
  it('holds the pose of the last key reached and eases the position between keys', () => {
    const plan = { duration: 1000, keys: [
      { t: 0, x: 0, y: 100, pose: 'default', alpha: 1 },
      { t: 1000, x: 100, y: 100, pose: 'look-left', alpha: 1 },
    ] };
    const f = cameoFrame(plan, 500);
    expect(f.x).toBeCloseTo(50);
    expect(f.pose).toBe('default');
    expect(cameoFrame(plan, 1000).pose).toBe('look-left');
  });

  it('arcs above the straight line on a lifted segment', () => {
    const plan = { duration: 1000, keys: [
      { t: 0, x: 0, y: 100, pose: 'default' },
      { t: 1000, x: 100, y: 100, pose: 'default', lift: 40 },
    ] };
    expect(cameoFrame(plan, 500).y).toBeCloseTo(60);
  });
});
