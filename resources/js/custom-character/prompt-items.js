/**
 * The costume items a prompt's COSTUME ITEMS block offers, in the order it lists them.
 * Matches `CharacterPromptBuilder` / the `prompt` template in `config/token_slayer.php`.
 * @type {string[]}
 */
export const ITEM_LABELS = ['Body', 'Waist', 'Feet', 'Cape', 'Weapon', 'Hat'];

/**
 * The heading line that opens the block the user fills in.
 * @type {string}
 */
const BLOCK_HEADING = 'COSTUME ITEMS (';

/**
 * Finds the COSTUME ITEMS block's item lines in a prompt: only the lines right
 * after its heading, never the defaults the rules above list under the same labels.
 *
 * @param {string} text The prompt.
 * @return {Array<{label: string, line: number}>} Each item and the index of its line in `text.split('\n')`.
 */
function blockLines(text) {
  const lines = text.split('\n');
  const heading = lines.findIndex(line => line.startsWith(BLOCK_HEADING));
  if (heading < 0) {
    return [];
  }
  const found = [];
  for (let line = heading + 1; line < lines.length; line++) {
    const label = ITEM_LABELS.find(item => lines[line].startsWith(`- ${item}:`));
    if (!label) {
      break;
    }
    found.push({ label, line });
  }

  return found;
}

/**
 * Whether a prompt has a COSTUME ITEMS block at all (the Custom prompt does not).
 *
 * @param {string} text The prompt.
 * @return {boolean}
 */
export function hasItemsBlock(text) {
  return blockLines(text).length > 0;
}

/**
 * Reads what the user typed into the COSTUME ITEMS block. An item left empty
 * (or only spaces) is not returned, since it means "use the character type's default".
 *
 * @param {string} text The prompt as it stands in the text box.
 * @return {Object<string, string>} The filled items by label.
 */
export function readItems(text) {
  const lines = text.split('\n');
  const items = {};
  for (const { label, line } of blockLines(text)) {
    const value = lines[line].slice(`- ${label}:`.length).trim();
    if (value) {
      items[label] = value;
    }
  }

  return items;
}

/**
 * Writes items into the COSTUME ITEMS block of a prompt, leaving every other
 * line (and every item not given) as it is.
 *
 * @param {string} text The prompt.
 * @param {Object<string, string>} items Values by label.
 * @return {string} The prompt with the items filled in.
 */
export function fillItems(text, items) {
  const lines = text.split('\n');
  for (const { label, line } of blockLines(text)) {
    if (items[label]) {
      lines[line] = `- ${label}: ${items[label]}`;
    }
  }

  return lines.join('\n');
}
