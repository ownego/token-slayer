/**
 * Gap between the activity bubble's bottom edge and the avatar's top, in px.
 * @type {number}
 */
const BUBBLE_GAP = 6;

/**
 * Where a fighter's activity bubble is centred: just above its avatar, in
 * world space. The avatar's offset and size are container-local, and the
 * container grows with the fighter's damage (up to 1.4x) — so they are scaled
 * here; reading them unscaled put the bubble on top of a grown fighter's avatar.
 *
 * @param {{spriteY: number, headY: number, headH: number, scale: number, bubbleH: number}} f
 * @return {number}
 */
export function bubbleCenterY({ spriteY, headY, headH, scale, bubbleH }) {
  const avatarTop = spriteY + (headY - headH / 2) * scale;

  return avatarTop - BUBBLE_GAP - bubbleH / 2;
}

/**
 * The action bubble's type size and line length for a fighter: bounded, so
 * a fighter grown by damage doesn't stretch its bubble into a long banner
 * (the old sizing scaled both with displaySize — ~38 characters at 24px).
 *
 * @param {number} displaySize The fighter's display size.
 * @return {{fontPx: number, maxChars: number, maxLines: number}}
 */
export function activityFit(displaySize) {
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

  return {
    fontPx: clamp(Math.round(displaySize * 0.2), 10, 14),
    maxChars: clamp(Math.round(displaySize * 0.3), 14, 20),
    maxLines: 2,
  };
}

/**
 * Wraps an action onto at most `maxLines` lines of `maxChars`, breaking at
 * spaces (a word longer than a line is cut), the last line ending in an
 * ellipsis when there's more.
 *
 * @param {string} text
 * @param {number} maxChars
 * @param {number} maxLines
 * @return {string} lines joined by "\n"
 */
export function wrapActivity(text, maxChars, maxLines) {
  const words = String(text ?? '').split(/\s+/).filter(Boolean);
  const lines = [];
  let line = '';
  let i = 0;
  while (i < words.length && lines.length < maxLines) {
    const word = words[i];
    const next = line ? `${line} ${word}` : word;
    if (next.length <= maxChars) {
      line = next;
      i++;
    } else if (!line) {
      lines.push(word.slice(0, maxChars));
      words[i] = word.slice(maxChars);
    } else {
      lines.push(line);
      line = '';
    }
  }
  if (line && lines.length < maxLines) {
    lines.push(line);
  }
  if (i < words.length) {
    const rest = [lines.pop() ?? '', ...words.slice(i)].join(' ').trim();
    lines.push(rest.length > maxChars ? `${rest.slice(0, maxChars - 1)}…` : rest);
  }

  return lines.join('\n');
}
