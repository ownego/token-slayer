import { normalizeCustomCharacter, stripUrls } from '../fighter/custom-fighter.js';

/**
 * The key the custom fighter goes by in the Character tab's roster, in the
 * sheet's `equipped` and in `$wire.equip()`. Matches `FighterSheet::CUSTOM_KEY`.
 * @type {string}
 */
export const CUSTOM_KEY = 'custom';

/**
 * The key a built-but-not-used draft goes by while the editor previews it. It is
 * registered next to the fighter in use, never in its place, and taken out again
 * when the preview goes away.
 * @type {string}
 */
export const DRAFT_KEY = 'custom-draft';

/**
 * The custom fighter's body core inside its 100px frame, as [x0, y0, x1, y1] — the
 * roster's `BOX` shape. Custom strips are built 28px tall with the feet on row 58
 * and the body centred on column 50, so the box is fixed rather than measured.
 * @type {number[]}
 */
export const CUSTOM_BOX = [36, 30, 64, 58];

/**
 * What the fighter is called until its player names it (`custom_character.default_name`).
 * @type {string}
 */
const DEFAULT_NAME = 'Custom';

/**
 * The accent the stage and slot take for the custom fighter (the roster's gold).
 * @type {string}
 */
const CUSTOM_ACCENT = '#fbbf24';

/**
 * A roster-shaped entry for the custom fighter: the shape `ROSTER` has, with each
 * animation's `file` a served URL instead of a bundled strip. It fights as a
 * slasher with its one attack, named like a roster fighter's first.
 *
 * @param {?object} raw The `custom_character` payload (`FighterSheet::$customCharacter`, with its `name`).
 * @param {string} [key] The roster key to give it (the draft preview uses its own).
 * @return {?{key: string, name: string, type: string, accent: string, anims: Object<string, {frames: number, rate: number, file: string}>}}
 */
export function customRosterEntry(raw, key = CUSTOM_KEY) {
  const spec = normalizeCustomCharacter(raw);
  if (!spec) {
    return null;
  }
  const urls = stripUrls(spec);
  const anim = state => ({ frames: spec.animations[state].frames, rate: spec.animations[state].rate, file: urls[state] });

  return {
    key,
    name: typeof raw.name === 'string' && raw.name.trim() ? raw.name.trim() : DEFAULT_NAME,
    type: 'SLASH',
    accent: CUSTOM_ACCENT,
    anims: { idle: anim('idle'), walk: anim('walk'), attack1: anim('attack'), death: anim('death') },
  };
}

/**
 * Puts the custom fighter into the roster lookups the sheet's sprite helpers read
 * (`BY_KEY` and `BOX`), or takes it out when the user has none, so a removed or
 * replaced character never lingers from an earlier mount. The roster fighters are
 * never touched.
 *
 * @param {Object<string, object>} byKey The roster entries by key.
 * @param {Object<string, number[]>} box The body boxes by key.
 * @param {?object} raw The `custom_character` payload, or null.
 * @param {string} [key] The roster key to register it under.
 * @return {boolean} Whether there is a custom fighter to show.
 */
export function registerCustomRoster(byKey, box, raw, key = CUSTOM_KEY) {
  const entry = customRosterEntry(raw, key);
  if (!entry) {
    delete byKey[key];
    delete box[key];

    return false;
  }
  byKey[key] = entry;
  box[key] = CUSTOM_BOX;

  return true;
}
