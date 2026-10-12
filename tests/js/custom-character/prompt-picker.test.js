import { describe, expect, test, vi } from 'vitest';
import { characterPrompt } from '../../../resources/js/custom-character/prompt-picker.js';
import { fillItems, readItems } from '../../../resources/js/custom-character/prompt-items.js';

const template = label => `CHARACTER TYPE: ${label}

For the ${label} class the costume MUST include:
- Body: default ${label} body
- Waist: default ${label} waist
- Feet: default ${label} feet
- Cape: default ${label} cape
- Weapon: default ${label} weapon
- Hat: default ${label} hat

COSTUME ITEMS (optional: write what you want for any item)
- Body:
- Waist:
- Feet:
- Cape:
- Weapon:
- Hat:

OUTPUT: one image.`;

const TYPES = { assassin: 'Assassin', wizard: 'Wizard' };
const PROMPTS = { assassin: template('ASSASSIN'), wizard: template('WIZARD') };

describe('readItems', () => {
  test('reads only the lines the user filled in, from the costume items block', () => {
    const text = fillItems(PROMPTS.assassin, { Weapon: 'a rusty katana', Hat: 'a straw hat' });

    expect(readItems(text)).toEqual({ Weapon: 'a rusty katana', Hat: 'a straw hat' });
  });

  test('ignores the default items the rules list above the block', () => {
    expect(readItems(PROMPTS.assassin)).toEqual({});
  });

  test('ignores a line left blank or holding only spaces', () => {
    expect(readItems(PROMPTS.assassin.replace('- Cape:', '- Cape:    '))).toEqual({});
  });

  test('is empty for text with no costume items block', () => {
    expect(readItems('just some text\n- Weapon: x')).toEqual({});
  });
});

describe('fillItems', () => {
  test('writes each item into its own line of the block and nothing else', () => {
    const filled = fillItems(PROMPTS.wizard, { Cape: 'a green cape' });

    expect(filled).toContain('\n- Cape: a green cape\n');
    expect(filled).toContain('- Body: default WIZARD body');
    expect(filled.replace('- Cape: a green cape', '- Cape:')).toBe(PROMPTS.wizard);
  });

  test('leaves the text alone when there is nothing to fill', () => {
    expect(fillItems(PROMPTS.wizard, {})).toBe(PROMPTS.wizard);
  });

  test('puts back what readItems took out', () => {
    const items = { Body: 'a patched coat', Feet: 'clogs' };

    expect(readItems(fillItems(PROMPTS.assassin, items))).toEqual(items);
  });
});

describe('characterPrompt', () => {
  test('knows where the type thumbnails are served from', () => {
    expect(characterPrompt(TYPES, PROMPTS, '/assets/custom-character/types').thumbs).toBe('/assets/custom-character/types');
  });

  test('starts with no type chosen and no prompt, so nothing is offered before a pick', () => {
    const picker = characterPrompt(TYPES, PROMPTS);

    expect(picker.type).toBeNull();
    expect(picker.text).toBe('');
  });

  test('choosing a type shows that type\'s prompt', () => {
    const picker = characterPrompt(TYPES, PROMPTS);

    picker.choose('assassin');

    expect(picker.type).toBe('assassin');
    expect(picker.text).toBe(PROMPTS.assassin);
  });

  test('switching type keeps the costume items the user typed', () => {
    const picker = characterPrompt(TYPES, PROMPTS);
    picker.choose('assassin');
    picker.text = fillItems(picker.text, { Weapon: 'a twin-blade', Hat: 'a veil' });

    picker.choose('wizard');

    expect(picker.type).toBe('wizard');
    expect(picker.text).toContain('CHARACTER TYPE: WIZARD');
    expect(readItems(picker.text)).toEqual({ Weapon: 'a twin-blade', Hat: 'a veil' });
  });

  test('switching type with nothing typed gives the new type\'s blank prompt', () => {
    const picker = characterPrompt(TYPES, PROMPTS);
    picker.choose('assassin');

    picker.choose('wizard');

    expect(picker.text).toBe(PROMPTS.wizard);
  });

  test('an unknown type is ignored', () => {
    const picker = characterPrompt(TYPES, PROMPTS);
    picker.choose('assassin');

    picker.choose('jester');

    expect(picker.type).toBe('assassin');
  });

  test('copy puts the current text on the clipboard and flashes "copied"', async () => {
    vi.useFakeTimers();
    const writeText = vi.fn(() => Promise.resolve());
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    const picker = characterPrompt(TYPES, PROMPTS);
    picker.choose('wizard');

    await picker.copy();

    expect(writeText).toHaveBeenCalledWith(PROMPTS.wizard);
    expect(picker.copied).toBe(true);
    vi.advanceTimersByTime(1500);
    expect(picker.copied).toBe(false);
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  test('copy does not throw where there is no clipboard', async () => {
    vi.stubGlobal('navigator', {});
    const picker = characterPrompt(TYPES, PROMPTS);
    picker.choose('wizard');

    await expect(picker.copy()).resolves.toBeUndefined();
    vi.unstubAllGlobals();
  });
});
