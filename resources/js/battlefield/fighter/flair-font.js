/**
 * The webfont the model-flair orbit ring is set in — the same "Pixelify
 * Sans" the DOM HUD (battlefield-hud.css) already uses, loaded from Google
 * Fonts via the page's own `<link>` (battlefield.blade.php), so the flair
 * lettering reads as part of the same redesign rather than a leftover of
 * the old Chakra Petch look this replaces.
 *
 * The admin's flair preview (`resources/views/filament/flair-preview.blade.php`)
 * previously matched this file's font choice for a consistent WYSIWYG
 * preview; it was NOT updated alongside this change (out of scope — PHP/
 * Blade, not touched by this task) and may now show the ring in a
 * different face than the live battlefield until it is.
 */
export const FLAIR_FONT_FAMILY = "'Pixelify Sans', monospace";
export const FLAIR_FONT_WEIGHT = '700';

let loading = null;

/**
 * Resolves once the flair webfont is usable for canvas rasterization.
 *
 * A Phaser Text bakes its glyphs into a texture the moment it is created and
 * never re-rasterizes on its own, so a Text built before the face has
 * downloaded is stuck rendering in the fallback for its whole life —
 * `font-display: swap` repaints DOM text but cannot reach into a canvas
 * texture. Callers use this to re-apply the family once, after the fact.
 *
 * @return {Promise<void>}
 */
export function ensureFlairFont() {
  if (!loading) {
    loading = document.fonts?.load
      ? document.fonts.load(`${FLAIR_FONT_WEIGHT} 16px ${FLAIR_FONT_FAMILY}`).then(() => {}, () => {})
      : Promise.resolve();
  }
  return loading;
}

/**
 * Whether the flair webfont is already available synchronously, so a caller
 * can skip the re-apply pass entirely on every flair after the first.
 *
 * @return {boolean}
 */
export function isFlairFontReady() {
  return document.fonts?.check?.(`${FLAIR_FONT_WEIGHT} 16px ${FLAIR_FONT_FAMILY}`) ?? true;
}
