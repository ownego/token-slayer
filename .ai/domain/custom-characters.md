# Domain: Custom characters (player-uploaded fighters)

A player can upload one **pose sheet** they generated with an image AI (from the prompt of the character type they picked — see "The prompt") and get their own fighter. Nothing calls an AI from the server: the player does that step, and the app only validates and converts the result. Page: `/character` (`Livewire\CustomCharacterUpload`).

**Status:** done end to end — upload, conversion, storage, preview, and the fighter on the battlefield (see "On the battlefield").

## The sheet contract

Enforced in code, in this order, and a violation throws `InvalidPoseSheetException` carrying a `PoseSheetProblem` (user-facing message; nothing is stored):

| Check | Class | Rule |
|---|---|---|
| File | `PoseSheetDecoder` | PNG/JPEG/WebP; ≤ `max_bytes`; sides `min_side`..`max_side`; ≤ `max_pixels` (read from the header before decoding) |
| Background | `PoseSheetKeyer` | solid `#FF00FF` or transparent, recognised from the border; magenta is eroded 1 px to drop tinted edge pixels |
| Layout | `PoseSheetLocator` | rows and poses found from empty gaps; row count and per-row pose counts from `custom_character.rows` (idle 2–4, walk 4, attack 3, death 3) |
| Stray marks | `StrayMarkRemover` | detached blobs under `stray_ratio` of a pose's ink (motion lines, sparks) are erased; a larger detached part (a dropped sword) is kept |
| Sanity | `PoseSheetValidator` | no pose touches a sheet edge; idle/walk heights within `size_tolerance` of their median |

Changing the layout or the prompt in a way that invalidates old sheets → bump `custom_character.prompt_version`.

## Conversion

`CustomCharacterBuilder::execute()` runs the chain above, then: `SpritePixelator` (one scale for every pose from the median idle/walk height → `sprite_height`; each output pixel averages only ink pixels; one shared palette of `palette_colors` via `PaletteQuantizer`, a median cut that splits the widest colour range so rare colours like pupils survive) → `RosterToneMatcher` (scales saturation/lightness toward the roster's muted look) → `SpriteOutliner` (1 px `outline_color` on the outer edge) → `SpriteStripBuilder` (100×100 frames, feet on row 58, centred by the lower body; idle holds the last idle pose and bobs, walk/attack/death hold each pose). Fighter frames stay **100 px** — never upscale.

Animation table it produces (frames @ rate): idle 6 @ 8, walk 8 @ 10, attack 6 @ 12, death 3 @ 6.

## Storage

`CustomCharacterStore` writes `custom-characters/{user_id}/{version_hash}/{idle,walk,attack,death}.png` on the `public` disk; the hash is of the PNG content, so a changed character gets a new URL (the staging host caches non-hashed assets for 7 days). `custom_characters` holds one row per user (`version_hash`, `animations`). The uploaded sheet is never kept. `SaveCustomCharacter` builds before writing, so a refused sheet leaves the existing character alone; `RemoveCustomCharacter` deletes row and files.

Uploads are rate limited (`uploads_per_hour`, key `custom-character-upload:{user_id}`) because a build costs about a second of CPU. Building runs synchronously in the request (measured ~1 s for a 1254 px sheet).

## On the battlefield

A custom character is one more choice in the fighter sheet's Character tab. Building one equips it (`custom_characters.active`, true by default and set by `SaveCustomCharacter`); while it is equipped it wins over the equipped roster character and over the per-boss assignment. Equipping a roster character in the sheet turns it off but keeps it, so its slot can equip it again; Remove deletes it. `User::characterForBoss()` is unchanged and still sends a roster key as the fallback an old client (or a failed load) shows, and `User::customFighterState()` is null while the custom fighter is not equipped.

**Wire shape.** `User::customFighterState()` → `CustomCharacter::broadcastState()`: `{version, url_base, animations}` — the version hash, the **same-origin path** the strips are served from (never a full URL: the battlefield reads the strips' pixels for the heel, which a cross-origin image forbids), and the `{frames, rate}` table. It rides as `custom_character` (null for everyone else) on `FighterJoined`, `FighterCharging` (a synthetic client-side rejoin), `FighterCharacterChanged` and each `BossSpawned.fighters[]` row, and as `customCharacter` (same inner shape) on each boot-payload fighter. A client that missed a build or removal (Reverb has no replay) catches up from the next `FighterCharging`, which carries the same state (`charge.js` hands it to `updateCharacters` for a custom fighter, or for a fighter currently showing one). `CustomCharacterUpload` dispatches `FighterCharacterChanged` after a build and after a remove (inside `rescue()`: a dead Reverb must not fail the page), so every client loads or drops it live.

**Client.** `fighter/custom-fighter.js` (pure) reads the payload (`normalizeCustomCharacter` takes `url_base` and the snapshot's `urlBase`) and builds a FIGHTER_TYPES-shaped entry — `key` `custom-<user>-<version>`, `texture` its own, `attackType` slash, no per-attack art. `fighter/custom-fighter-loader.js` fetches the four strips, stacks them into **one canvas texture** of named 100 px frames (`<key>-idle-0` … the names the atlas uses, so the fighter code reads both alike) and registers the animations; it dedupes concurrent loads and the texture belongs to the game, so a rotate-restart and a repeat request are free. `Fighter` shows a roster fighter first and swaps the custom one in when it lands (`_requestCustomFighter`; newest request wins via `entry.wantedCustomKey`, a failed load keeps the roster character). Every `setTexture`/`add.sprite` of a body goes through `textureOf(ftype)`.

**Size.** The strips are built `sprite_height` (28) px tall; the roster fighters are 18. `customFighterType` carries `bodyScale` (18/28) and `bodyOffsetY` (keeps the feet on the roster's line when scaled about the frame centre); `Fighter._fitBody` applies them, and puts a roster body back when a custom one is removed. Keep `CUSTOM_BODY_PX` and `CUSTOM_FOOT_ROW` in `custom-fighter.js` equal to `custom_character.sprite_height` and `SpriteStripBuilder::FOOT_ROW`.

**Snapshot.** `snapshotState()` captures `f.ftype.spec` — or `f.wantedCustom` while the strips are still loading — as `customCharacter`, so a rotate-restart builds the fighter from the textures already loaded, or requests them again.

Limits: an attack is always the slash animation; the minions, the sheet's mini-stage and the Character tab's roster still show the equipped roster character.

## In the fighter sheet

The Character tab (`livewire/fighter-sheet/character.blade.php`, `sheet/character-stage.js`) shows two things for it: a **+ Custom** tile that always leads the roster grid and opens this page in a new tab (`<a class="slot-add">`, deliberately not a `.slot` so selection, filters, keys and tooltips ignore it), and — once the user has built one — a **Custom** slot beside the fighters, tagged YOU while it is the equipped one. `FighterSheet` hands the tab `$customCharacter` (`CustomCharacter::broadcastState()`, equipped or not) and seeds `$equipped` with `'custom'` while it is active; `equip('custom')` turns it on, `equip(<roster key>)` turns it off, and both broadcast `FighterCharacterChanged`.

`sheet/custom-roster.js` makes it look like a roster fighter to the sheet's sprite helpers: `registerCustomRoster` puts a roster-shaped entry (`anims` whose `file` is the served strip URL; `sheet-roster.js`'s `stripUrl` passes a `/…` path through) and a fixed body box into `BY_KEY`/`BOX` under key `custom`, and removes them when the user has none, so nothing lingers from an earlier mount. It fights as a slasher with its one attack, so its hotbar is Idle, Walk, Attack, Death, Summon, and no style chip hides its slot.

## The prompt

The page offers nine character types (warrior, wizard, assassin, archer, healer, necromancer, knight, alchemist, berserker); picking one shows that type's prompt. `config('token_slayer.custom_character')` holds the shared template (`prompt`, with `{LABEL}`, `{label}`, `{COSTUME}`, … placeholders) and the per-class wording (`types`: the six costume items body/waist/feet/cape/weapon/hat, the class `identity`, what it must not look like (`avoid`), its row-3 `attack`); `CharacterPromptBuilder::execute()` fills one prompt per type. Each prompt makes the type the authority for the costume, lists the class defaults, and leaves a **COSTUME ITEMS block empty**: the player types into a line to override that one item, and an empty line means the class default. Adding a type = one more entry in `types` (the order is the picker's). Every prompt also carries one shared **PIXEL ART STYLE** block (in the template, identical for all types: chunky grid-aligned pixels, no anti-aliasing, 2–4 shades per material, dark navy/charcoal outlines, strict side profile for the side-view rows, one unified sprite set); the template's COLORS line must not contradict it (no "2 tones" or outline-width rules there). The layout rows and the magenta background stay in the shared template because the converter depends on them; bump `prompt_version` if they change.

Each type button shows a thumbnail, `public/assets/custom-character/types/<type>.png` (128 x 128, transparent, one per key of `types` — `CustomCharacterUploadTest` fails when a type has none). They were cut from one 3 x 3 image an image AI drew with all nine costumes on a magenta background (the nine-in-one prompt used for it is the type prompts' costume lines plus the style block): the sheet split in thirds, the magenta keyed out with the key colour taken back out of soft edges, stray pieces under the feet dropped, one scale for all nine, feet on a common line. A new type needs a thumbnail made the same way.

The picker is `resources/js/custom-character/prompt-picker.js` (Alpine `characterPrompt`; all prompts are sent with the page, no round trip). Switching type swaps the prompt but carries over the items the player already typed, via `prompt-items.js` (`readItems`/`fillItems`, which only touch the lines under the `COSTUME ITEMS (` heading, never the defaults listed above it). The section is `wire:ignore`, so a build or a refusal never wipes what was typed.
