import { normalizeCustomCharacter, stripUrls } from '../fighter/custom-fighter.js';

/**
 * The key the custom fighter goes by in the Character tab's roster, in the
 * sheet's `equipped` and in `$wire.equip()`. Matches `FighterSheet::CUSTOM_KEY`.
 * @type {string}
 */
export const CUSTOM_KEY = 'custom';

/**
 * The custom fighter's body core inside its 100px frame, as [x0, y0, x1, y1] — the
 * roster's `BOX` shape. Custom strips are built 28px tall with the feet on row 58
 * and the body centred on column 50, so the box is fixed rather than measured.
 * @type {number[]}
 */
export const CUSTOM_BOX = [36, 30, 64, 58];

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
 * @param {?object} raw The `custom_character` payload (`FighterSheet::$customCharacter`).
 * @return {?{key: string, type: string, accent: string, anims: Object<string, {frames: number, rate: number, file: string}>}}
 */
export function customRosterEntry(raw) {
  const spec = normalizeCustomCharacter(raw);
  if (!spec) {
    return null;
  }
  const urls = stripUrls(spec);
  const anim = state => ({ frames: spec.animations[state].frames, rate: spec.animations[state].rate, file: urls[state] });

  return {
    key: CUSTOM_KEY,
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
 * @return {boolean} Whether there is a custom fighter to show.
 */
export function registerCustomRoster(byKey, box, raw) {
  const entry = customRosterEntry(raw);
  if (!entry) {
    delete byKey[CUSTOM_KEY];
    delete box[CUSTOM_KEY];

    return false;
  }
  byKey[CUSTOM_KEY] = entry;
  box[CUSTOM_KEY] = CUSTOM_BOX;

  return true;
}
