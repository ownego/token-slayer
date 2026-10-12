import { fillItems, readItems } from './prompt-items.js';

/**
 * How long "Copied" stays on the copy button, in ms.
 * @type {number}
 */
const COPIED_MS = 1300;

/**
 * Alpine.data() component for the custom character page's prompt: pick a character
 * type, get that type's prompt, edit the empty COSTUME ITEMS lines if you want to
 * override an item, copy it. Switching type swaps the prompt but carries over the
 * items the user already typed, so choosing between types never throws that work away.
 *
 * @param {Object<string, string>} types Picker labels by character type, in picker order.
 * @param {Object<string, string>} prompts The prompt text by character type.
 * @param {string} thumbs Folder the type thumbnails (`<type>.png`) are served from.
 * @return {object}
 */
export function characterPrompt(types, prompts, thumbs = '') {
  return {
    types,
    prompts,
    thumbs,
    type: null,
    text: '',
    copied: false,

    /**
     * Shows the prompt of a character type, keeping any costume items already typed.
     *
     * @param {string} key The character type.
     * @return {void}
     */
    choose(key) {
      if (!(key in this.prompts)) {
        return;
      }
      const typed = this.text ? readItems(this.text) : {};
      this.type = key;
      this.text = fillItems(this.prompts[key], typed);
    },

    /**
     * Copies the prompt as it stands, edits included, and flashes "Copied".
     *
     * @return {Promise<void>}
     */
    async copy() {
      try {
        await navigator.clipboard?.writeText(this.text);
      } catch {
        // a blocked clipboard leaves the text selectable in the box
      }
      this.copied = true;
      setTimeout(() => {
        this.copied = false;
      }, COPIED_MS);
    },
  };
}
