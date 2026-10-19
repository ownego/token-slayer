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
 * The key a minion the player has just built goes by on the shared stage while the Minions tab
 * previews it. Its own key apart from the fighter's draft: the two drafts live side by side and
 * the open Forge tab decides which one the stage shows.
 * @type {string}
 */
export const MINION_DRAFT_KEY = 'minion-draft';

/**
 * The key a minion already in the set goes by while a card's Preview shows it on the stage. Never
 * has a slot of its own: it is a look, closed again with "Close preview".
 * @type {string}
 */
export const MINION_PEEK_KEY = 'minion-peek';

/**
 * The custom fighter's body core inside its 100px frame, as [x0, y0, x1, y1] — the
 * roster's `BOX` shape. Custom strips are built 52px tall with the feet on row 58
 * and the body centred on column 50, so the box is fixed rather than measured.
 * @type {number[]}
 */
export const CUSTOM_BOX = [26, 6, 74, 58];

/**
 * The filter group of a player-made fighter: the roster's style chips never show it,
 * the `Custom` chip shows only it.
 * @type {string}
 */
export const CUSTOM_GROUP = 'CUSTOM';

/**
 * The filter group of a custom minion on the stage: no style chip claims it (only All shows it).
 * @type {string}
 */
export const MINION_GROUP = 'MINION';

/**
 * What a minion is called on the stage: it has no name of its own.
 * @type {string}
 */
const MINION_NAME = 'Minion';

/**
 * Where a custom strip's feet stand in its frame (the server's `FOOT_ROW`).
 * @type {number}
 */
const FOOT_ROW = 58;

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
 * Whether a roster key is one of the stage's custom minion previews.
 *
 * @param {string} key A roster key.
 * @return {boolean}
 */
export function isMinionKey(key) {
  return key === MINION_DRAFT_KEY || key === MINION_PEEK_KEY;
}

/**
 * The body box of a custom minion in its frame, as [x0, y0, x1, y1]: the fighter's box, as tall as
 * the minion was measured (an older fighter copied in is taller than a new build).
 *
 * @param {*} bodyPx The minion's measured body height.
 * @return {number[]}
 */
function minionBox(bodyPx) {
  return Number.isInteger(bodyPx) && bodyPx >= 1 && bodyPx <= FOOT_ROW ? [CUSTOM_BOX[0], FOOT_ROW - bodyPx, CUSTOM_BOX[2], FOOT_ROW] : CUSTOM_BOX;
}

/**
 * A roster-shaped entry for the custom fighter: the shape `ROSTER` has, with each
 * animation's `file` a served URL instead of a bundled strip. It fights as a
 * slasher with its one attack, named like a roster fighter's first.
 *
 * @param {?object} raw The `custom_character` payload (`FighterSheet::$customCharacter`, with its `name`), or for a minion key a minion's state.
 * @param {string} [key] The roster key to give it (the draft preview uses its own).
 * @return {?{key: string, name: string, type: string, group: string, author: ?string, accent: string, anims: Object<string, {frames: number, rate: number, file: string}>}}
 */
export function customRosterEntry(raw, key = CUSTOM_KEY) {
  const spec = normalizeCustomCharacter(raw);
  if (!spec) {
    return null;
  }
  const urls = stripUrls(spec);
  const anim = state => ({ frames: spec.animations[state].frames, rate: spec.animations[state].rate, file: urls[state] });
  const minion = isMinionKey(key);

  return {
    key,
    name: minion ? MINION_NAME : typeof raw.name === 'string' && raw.name.trim() ? raw.name.trim() : DEFAULT_NAME,
    type: 'SLASH',
    group: minion ? MINION_GROUP : CUSTOM_GROUP,
    author: !minion && typeof raw.author === 'string' && raw.author.trim() ? raw.author.trim() : null,
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
  box[key] = isMinionKey(key) ? minionBox(raw.body_px ?? raw.bodyPx) : CUSTOM_BOX;

  return true;
}

/**
 * The group a roster entry is filtered under: its own `group`, else its attack style.
 *
 * @param {{type: string, group?: string}} entry A roster-shaped entry.
 * @return {string}
 */
export function groupOf(entry) {
  return entry.group ?? entry.type;
}

/**
 * Whether a style chip shows a slot of the given group (the All chip, whose type is '', shows everything).
 *
 * @param {string} chipType The chip's `data-type`.
 * @param {string} group The slot's group.
 * @return {boolean}
 */
export function matchesChip(chipType, group) {
  return chipType === '' || chipType === group;
}
