import { describe, expect, test } from 'vitest';
import { frameOffset, inspectorLabel, inspectorStart, inspectorStep, inspectorToggle } from '@battlefield/sheet/frame-inspector.js';

describe('frame inspector', () => {
  test('pausing and playing again resumes without restarting the strip', () => {
    const paused = inspectorToggle(inspectorStart());
    expect(paused.state.playing).toBe(false);
    expect(paused.restart).toBe(false);
    const resumed = inspectorToggle(paused.state);
    expect(resumed.state.playing).toBe(true);
    expect(resumed.restart).toBe(false);
  });

  test('Play after stepping restarts the strip (the staging bug: it stayed frozen)', () => {
    const stepped = inspectorStep(inspectorToggle(inspectorStart()).state, 1, 6);
    expect(stepped).toEqual({ playing: false, frame: 1, stepped: true });
    const played = inspectorToggle(stepped);
    expect(played.state).toEqual({ playing: true, frame: 0, stepped: false });
    expect(played.restart).toBe(true);
  });

  test('stepping while playing pauses on the next frame, and wraps at both ends', () => {
    expect(inspectorStep(inspectorStart(), 1, 6)).toEqual({ playing: false, frame: 1, stepped: true });
    expect(inspectorStep(inspectorStart(), -1, 6).frame).toBe(5);
    expect(inspectorStep({ playing: false, frame: 5, stepped: true }, 1, 6).frame).toBe(0);
  });

  test('the label names the frame and the state; the offset is whole 100 px frames', () => {
    expect(inspectorLabel({ playing: true, frame: 0, stepped: false })).toBe('Frame 1 · playing');
    expect(inspectorLabel({ playing: false, frame: 2, stepped: true })).toBe('Frame 3 · paused');
    expect(frameOffset(0)).toBe(0);
    expect(frameOffset(3)).toBe(-300);
  });
});
