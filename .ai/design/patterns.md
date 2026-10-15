# Patterns

Statements with "must" / "never" are rules for new work; existing code may not follow them everywhere yet. Fix the code when you touch it, do not copy the exception.

## Confirm and destructive actions

The sheet and the HUD have no confirm component today (no modal, confirm or danger class exists in `fighter-sheet.css`); a native `window.confirm` / Livewire `wire:confirm` is the browser's own dialog, in another font, outside the game, blocking, and it cannot name the thing being deleted well. Never use it in the game world.

Pattern for new work, built once as a shared piece and reused:
1. The destructive control is an ordinary `.btn` (or `.link`) labelled with the verb and the object: "Remove fighter".
2. Pressing it swaps that row in place (no second modal) for a confirm row on the same panel: an `.alert.crit` style bar with the object's name ("Remove Ember Knight and all 3 versions?"), a `.btn` "Remove" with `--blood` text, and a `.btn` "Cancel". Focus moves to "Cancel" (the safe choice); Escape cancels; the row never auto-confirms.
3. After confirming: a `.toast` ("Fighter removed") and the view goes to the next sensible state (`Fallback` below).
4. Irreversible and large (bulk, account-level) actions additionally state what cannot be undone.

Who may do it is a policy, not a UI condition: the control is rendered for people allowed to use it and the server authorises it again (Gate/policy + Shield permission for admin actions).

## Fallback after a delete

If the deleted thing was the one in use, the view and every client switch to a defined fallback together (for fighters: the player's roster character), and the UI says so in the toast.

## States every view must define

| State | How it looks |
|---|---|
| Empty | `.empty`: dashed gold box, one line of why, the next step as a button or a short numbered list |
| Loading | a bar or spinner in the same panel with a text label; a disabled control keeps its place; no blank frame; use the game loader (`ts-runner`) for page-level waits |
| Error | `.alert.crit` or an inline `.probe-error`-style note next to what failed, naming the cause in plain words and what to do; keep the player's input |
| Success | `.toast` for a result, `.btn.flash` for the action that caused it, a state badge (`.show-state`) when something is now "Equipped" |
| Disabled | `.btn[disabled]` plus a visible reason next to it ("Choose a file first") |
| Selected | gold border/fill by `aria-selected` / `aria-pressed`, never colour alone: the label changes too (`Equipped`) |

## Forms and uploads

- One panel, labels above fields, 44 px high controls, errors under the field they belong to, the submit button disabled with a reason until the input is valid.
- An upload shows progress, then a build/processing state, then the result; a failure keeps the chosen file name and says what to change.
- A file the player will use is previewed before it counts: the result appears as a normal item the player can select and inspect on the show stage, and nothing is applied or broadcast until the player picks "Equip" (or the equivalent). Creating something is not using it: a player can make a thing for someone else or just to try.
- Text the player types that other players will read (a name) has a length limit, is shown with a visible counter or hint, and is escaped everywhere it is rendered.

## Previews

Preview any thing the player must judge on the shared show stage (`components.md`): real size, all poses, play/pause or step, in-game rates (`motion.md`). The create, edit and equip steps happen on the same screen as the roster, in place of the stage or beside it, so the player keeps their place; they do not navigate to another page for it.

## Links and buttons that leave the view

A link or button that goes to another page opens a new tab (`target="_blank" rel="noopener"`), so the running battlefield, an unsaved form or a build in progress is never lost. This includes the nav in `partials/account-nav.blade.php`; the current page is shown as plain text, not a link. In-page actions (switching a tab, opening a panel) stay in the page.

## Who made it

Anything a player creates and others can see shows its author ("by name") wherever it appears (tile tooltip, stage header, team panels), next to the kind tag (`CUSTOM`).

## Copy

- The audience is developers: say what it is, not how to use it. No "Press X to Y" for obvious controls, no "click Equip to apply".
- One line wherever space is small (bubbles, tiles, tooltips); cut with an ellipsis, never wrap.
- Use the game's words and one word per concept ("Defeated" or "death", not both; "fighter", not also "character" for the same thing on one screen).
- Buttons are verbs naming the object ("Equip this fighter", "Remove fighter"); errors say what is wrong and what to change; no exclamation marks, no blame.
- Numbers with units; times as relative ("2m ago") with the exact value on hover.
