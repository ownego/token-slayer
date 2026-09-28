import { describe, expect, it } from 'vitest';
import { isSoftwareRenderer, wantsLite } from '@battlefield/render-mode.js';

describe('isSoftwareRenderer', () => {
  it.each([
    ['ANGLE (Google, Vulkan 1.3.0 (SwiftShader Device (Subzero) (0x0000C0DE)), SwiftShader driver)'],
    ['llvmpipe (LLVM 15.0.7, 256 bits)'],
    ['Mesa softpipe'],
    ['ANGLE (Microsoft, Microsoft Basic Render Driver Direct3D11 vs_5_0 ps_5_0)'],
  ])('knows a CPU rasterizer: %s', name => {
    expect(isSoftwareRenderer(name)).toBe(true);
  });

  it.each([
    ['ANGLE (AMD, AMD Radeon Graphics (radeonsi renoir ACO), OpenGL 4.6)'],
    ['ANGLE (Intel, Intel(R) UHD Graphics 620 Direct3D11 vs_5_0 ps_5_0)'],
    ['Apple M1'],
  ])('leaves a real GPU alone: %s', name => {
    expect(isSoftwareRenderer(name)).toBe(false);
  });
});

describe('wantsLite', () => {
  it('goes lite when the browser draws WebGL on the CPU', () => {
    expect(wantsLite({ query: '', caveatFree: true, renderer: 'SwiftShader driver' })).toBe(true);
  });

  it('goes lite when the browser refuses a context without a major performance caveat', () => {
    // Chrome's own signal that it would fall back to software rendering
    expect(wantsLite({ query: '', caveatFree: false, renderer: null })).toBe(true);
  });

  it('stays full on a real GPU', () => {
    expect(wantsLite({ query: '', caveatFree: true, renderer: 'Intel(R) UHD Graphics 620' })).toBe(false);
  });

  it('?lite=1 / ?lite=0 force it either way (staging comparisons)', () => {
    expect(wantsLite({ query: '?lite=1', caveatFree: true, renderer: 'Apple M1' })).toBe(true);
    expect(wantsLite({ query: '?x=2&lite=0', caveatFree: false, renderer: 'SwiftShader' })).toBe(false);
  });
});
