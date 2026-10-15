# Example: the custom fighter, as a filled brief

This is what `design-template.md` looks like filled for "a player makes their own fighter by importing an image". It was written after the fact, from the review of the first version, to show the depth wanted; a real brief is asked of the person building the feature and corrected by them. Lines marked `(?)` were open questions at the time.

**Understanding.** A player wants to be seen on the battlefield as themselves, not as one of 20 assigned characters. They make a pose sheet with an image AI outside the game and bring it in. Success: the player and every teammate see the same fighter live, the player can try it before it counts, and an admin can remove one.
*Size:* Deep (new kind of player-made content, upload, new tables, permission, broadcast).

## 1. Place on the map
- **Fantasy:** the fighter on the shared field is *you*.
- **Loops:** social (being seen, seeing others) and session (your fighter acts while you work). It stays out of the moment loop's math: no damage change.
- **Systems touched:** fighter (a second kind of look), roster and sheet (Character tab, Profile mini stage, filters), broadcast events and the boot payload, the HUD's teammate faces, minions (still the roster look), admin panel, storage.
- **Rules kept:** cosmetic only; keyed by user id; frames 100 px, never upscaled; `snapshotState()` equals the boot payload; boss changes and reconnects keep the fighter.

## 2. Player journeys
| Moment | Does it | Sees it live | Sees it later |
|---|---|---|---|
| Discover | sees a labelled `Create` action in the Character tab; the prompt guide explains how to make a sheet | - | `(?)` how a player who never opens the sheet finds out |
| First use | picks a type, copies the prompt, makes a sheet elsewhere, uploads | upload progress, a build state | the built fighter as a slot |
| Everyday | works as normal | their fighter acts, charges, hits | teammates see it on every load |
| Peak | equips it | a ceremony-sized moment (reuse the join summon) `(?)` | a teammate watching sees the new look appear |
| Goes wrong | uploads a bad sheet | a message that says what to change; the file stays chosen | - |
| Undo or leave | switches back, deletes a version, removes it | the roster fighter returns on every client | - |
| Return | opens the sheet days later | earlier versions, name, author | - |
| Seen from outside | a teammate looks at it | name, `CUSTOM` tag, "by author" | - |

## 3. Flows
**Build -> use** (state list):
1. `no fighter` -> (choose file) -> `file chosen` (Build enabled).
2. `file chosen` -> (Build) -> `building` -> `built` (a slot, selected, nothing equipped yet) or `failed` (message, file kept).
3. `built` -> (inspect on the normal show stage, all moves) -> (Equip) -> `equipped` (broadcast; ceremony) or (Discard) -> `no fighter`.
4. `equipped` -> (equip another) -> `built` (still a slot) | (remove) -> fallback to the roster fighter on every client.
**Building is not using:** a player may build one for a teammate or just to try; only Equip broadcasts.
**Many fighters:** several slots per player; the equipped one is a pointer; each has a name and versions (the last N, a knob).
**Failure branches:** storage write fails half way (the draft stays usable), two tabs build at once (one waits, the other is told), the strips load late on a viewer's screen (roster look first, then a swap that does not reset an attack or a charge).

## 4. Feel and pacing
Build success: a small confirmation in the sheet. Equip: the existing equip moment (fold, camera push-in) plus the join summon for everyone. The preview runs at the game's frame rate, at the stage's scale (4x), with pose picker and pause. Nothing in this flow blocks the battlefield; it lives in the sheet and links to the guide in a new tab. On a wall display, textures of fighters who left are released.

## 5. Rules and knobs
- Cosmetic only. Attack stays the slash animation `(?)`.
- Knobs: max sheet size and pixels (memory: measured on the build path), uploads per hour, versions kept, fighters per player `(?)`, name length.
- Player-made content on a shared wall: the owner is shown ("by name"), the creator or an admin can remove (policy + permission), the fighter falls back to the roster look, the name is escaped and stripped of control characters, files go after the database commit.

## 6. Systems impact
Roster: a `Custom` filter chip, never under every style chip. Sheet: Create or Manage entry, Profile mini stage registers the custom look itself. Broadcast: `custom_character` on join, charge, character-changed and boss-spawned events and in the boot payload (and the snapshot). HUD teammate faces: author and name `(?)`. Admin: list with remove. Storage: disk setting, full URL on another host, CORS.

## 7. Persistence and concurrency
Files under a content-hashed path; draft apart from confirmed; promote all four strips then delete the draft; row written after files; cleanup after commit; one lock per player around build, confirm, equip, delete, remove and discard; versions pruned beyond the cap; a rename racing a confirm keeps the later; existing rows and a column added by an edited migration (deploy window).

## 8. Screens: states, phone, keyboard, access
Panel in the Character tab, in place of the stage, with `Back to roster`. States: empty (prompt guide link), file chosen, building (progress), draft ready (preview), failed (message, file kept), equipped, version list, remove confirm (in the sheet, not a browser dialog), disabled Build with the reason. Phone: full-screen sheet, one column, 44 px targets. Keyboard: Create tile focusable, focus moves into the panel, Escape goes back to the roster. Screen reader: progress and errors announced. Reduced motion: no ceremony animation, the toast stays.

## 9. Edge cases
A 4000 px sheet; the same sheet twice; a viewer who joins while a swap is loading; removing the equipped fighter during someone's attack; a teammate's fighter removed while you look at it; an old database that ran an earlier migration.

## 10. Out of scope
Per-attack art; minions using the custom look; a gallery of other players' fighters; unlocks.

## 11. Success check
On staging with two sessions: build, preview, equip: the second session shows the same fighter within a second and survives a reload and a boss change; remove it: both return to the roster look; a teammate who has not seen it finds `Create` without help; a bad sheet gives a message that says what to change; the whole path works on a phone and from the keyboard.

## Questions
- `blocks building` Should building for someone else be possible, or is a fighter always its builder's? (owner)
- `blocks building` How many fighters and versions per player? (owner)
- `can follow` Should equipping a custom fighter replay the join ceremony for everyone? (owner)
