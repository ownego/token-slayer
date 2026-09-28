/**
 * Ring separator glyph appended after the label's own characters.
 * @type {string}
 */
export const RING_SEPARATOR = '✦';

/**
 * Texture key for one baked glyph.
 * @param {string} ch
 * @param {string} color
 * @param {number} px
 * @return {string}
 */
export function glyphKey(ch, color, px) {
  return `flair-glyph:${ch}:${color}:${px}`;
}

/**
 * Bakes each unique flair glyph once and hands back its texture key, so a
 * flair costs a few Image objects instead of ~20 canvas Text objects.
 *
 * @param {{ exists: function(string): boolean, create: function(string, string, string, number): void }} deps
 * @return {{ get: function(string, string, number): string, prewarm: function(string, string, number): void }}
 */
export function createGlyphCache({ exists, create }) {
  const get = (ch, color, px) => {
    const key = glyphKey(ch, color, px);
    if (!exists(key)) {
      create(key, ch, color, px);
    }
    return key;
  };
  return {
    get,
    prewarm(label, color, px) {
      for (const ch of new Set([...label, RING_SEPARATOR])) {
        if (ch.trim()) {
          get(ch, color, px);
        }
      }
    },
  };
}
