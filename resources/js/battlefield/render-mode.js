// Lite mode: when the browser draws WebGL on the CPU (no usable GPU, or a
// driver Chrome blocklists), the full battlefield runs at 3-5fps — nearly all
// of it rasterizing the sky's full-screen layers at up to 2.5x. Lite mode
// renders at the layout's own size (render-scale.js) and bakes the still
// scenery into a few images (environment/bake-runs.js). Measured on staging
// with Chrome's software rasterizer: ~3.7fps → ~18fps.

/**
 * Renderer names of CPU rasterizers: SwiftShader (Chrome's own fallback),
 * Mesa's llvmpipe/softpipe, and Windows' Basic Render Driver.
 *
 * @type {RegExp}
 */
const SOFTWARE = /swiftshader|llvmpipe|softpipe|basic render|software/i;

/**
 * Whether a WebGL renderer name is a CPU rasterizer.
 *
 * @param {?string} renderer UNMASKED_RENDERER_WEBGL, or null when unknown.
 * @return {boolean}
 */
export function isSoftwareRenderer(renderer) {
  return SOFTWARE.test(renderer ?? '');
}

/**
 * Whether the battlefield should run in lite mode: forced by `?lite=1` or
 * off by `?lite=0` (for comparing on staging), else when the browser either
 * refuses a context without a major performance caveat — its own signal it
 * would fall back to software — or names a CPU rasterizer.
 *
 * @param {{query: string, caveatFree: boolean, renderer: ?string}} probe
 * @return {boolean}
 */
export function wantsLite({ query, caveatFree, renderer }) {
  const forced = new URLSearchParams(query).get('lite');
  if (forced === '1' || forced === '0') {
    return forced === '1';
  }

  return !caveatFree || isSoftwareRenderer(renderer);
}

/**
 * Probes this browser (a throwaway WebGL context) and decides lite mode.
 * Not unit-tested: it needs a real WebGL implementation.
 *
 * @return {boolean}
 */
export function detectLite() {
  let renderer = null;
  let caveatFree = true;
  try {
    const canvas = document.createElement('canvas');
    caveatFree = !!canvas.getContext('webgl', { failIfMajorPerformanceCaveat: true });
    const gl = document.createElement('canvas').getContext('webgl');
    const info = gl?.getExtension('WEBGL_debug_renderer_info');
    renderer = info ? gl.getParameter(info.UNMASKED_RENDERER_WEBGL) : null;
    gl?.getExtension('WEBGL_lose_context')?.loseContext();
  } catch {
    // no WebGL at all: Phaser falls back to canvas, which lite mode also helps
    caveatFree = false;
  }

  return wantsLite({ query: window.location.search, caveatFree, renderer });
}
