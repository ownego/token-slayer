# Domain: Custom characters (player-made fighters)

A player can make their own fighter from a **pose sheet** they generated with an image AI (from the prompt of the character type they picked — see "The prompt"). Nothing calls an AI from the server: the player does that step, and the app validates and converts the result. The sheet is built into a **draft** the player previews; only when they use it does it become their equipped fighter, which everyone then sees on the battlefield.

Two homes, one editor (`Livewire\CustomCharacterEditor`): the `/character` page (`CustomCharacterController`: the prompt guide, then the editor) and the fighter sheet's Character tab (a panel that replaces the stage). See "The editor" and "In the fighter sheet".

## The flow

1. **Build.** `StageCustomCharacter` (under the player's lock) converts the sheet and writes the strips to the **staged** area; a `custom_character_drafts` row (one per user) points at them. The live fighter is untouched, nothing is broadcast.
2. **Preview.** The editor shows the four strips animated, marked "not live yet", with *Use this fighter* and *Discard*.
3. **Confirm.** `ConfirmCustomCharacter` is bound to the hash the player previewed and to the player: a draft replaced in another tab, or someone else's, is never confirmed (`CustomCharacterDraftMissingException`), and confirming the version already in use is a harmless no-op. It moves the files from staged to confirmed, then in one transaction upserts the `custom_characters` row (equipped, `active`), adds a `custom_character_versions` row, trims the history to `max_versions` (oldest first, never the one in use) and deletes the draft; files of dropped versions are deleted only after the transaction. It then broadcasts `FighterCharacterChanged`.
4. **Manage.** Rename (`RenameCustomCharacter`: trimmed, cut to `name_max`, empty → `default_name`), switch to an earlier version (`EquipCustomCharacterVersion`, also equips and broadcasts), delete an earlier version (`DeleteCustomCharacterVersion`; the one in use is refused), remove the fighter (`RemoveCustomCharacter`).
5. **Remove.** Deletes the row (versions cascade), then every file, then broadcasts so every client drops the fighter live. The player falls back to the roster character they had equipped (`users.equipped_character` is never touched here). Who may: the owner, or a role holding `Delete:CustomCharacter` (`CustomCharacterPolicy`); the admin list is `Filament\Resources\CustomCharacters` (`ViewAny:CustomCharacter`), whose only action is Remove. Deleting a user clears their files (`User::booted`).

Equipping a roster character in the sheet turns the custom fighter off (`active` false) without deleting it, and equipping the Custom slot turns it back on (`FighterSheet::equip('custom')`). `User::equippedFighterKey()` is `custom` while it is on, else the roster key; `User::customFighterState()` is null while it is off.

## Data

| Table | Holds |
|---|---|
| `custom_characters` | One row per user: `name`, the version in use (`version_hash`, `animations`), `active` (equipped) |
| `custom_character_versions` | Every confirmed version (`version_hash`, `animations`), the history the player switches back to |
| `custom_character_drafts` | One row per user: the built-but-unconfirmed version |

## The sheet contract

Enforced in code, in this order, and a violation throws `InvalidPoseSheetException` carrying a `PoseSheetProblem` (user-facing message; nothing is stored):

| Check | Class | Rule |
|---|---|---|
| File | `PoseSheetDecoder` | PNG/JPEG/WebP; ≤ `max_bytes` (4 MB); sides `min_side`..`max_side` (512..2048); ≤ `max_pixels` (4,194,304), all read from the header before decoding |
| Background | `PoseSheetKeyer` | solid `#FF00FF` or transparent, recognised from the border; magenta is eroded 1 px to drop tinted edge pixels |
| Layout | `PoseSheetLocator` | rows and poses found from empty gaps; row count and per-row pose counts from `custom_character.rows` (idle 2–4, walk 4, attack 3, death 3) |
| Stray marks | `StrayMarkRemover` | detached blobs under `stray_ratio` of a pose's ink (motion lines, sparks) are erased; a larger detached part (a dropped sword) is kept |
| Sanity | `PoseSheetValidator` | no pose touches a sheet edge; idle/walk heights within `size_tolerance` of their median; that median at least `sprite_height` (`PosesTooSmall`: poses are only ever shrunk) |

**Size limits are a memory budget.** The build runs in the request on PHP arrays that grow with the pixel count. Measured with a 256M `memory_limit` (`BuildCeilingTest`): a noisy 2048 px sheet peaks near 115 MB, a 4000 px one near 230 MB (a fatal on a busier sheet), so the ceiling is 2048 / 4.2 MP. Livewire's temporary upload limit is the same 4 MB (`config/livewire.php`).

Changing the layout or the prompt in a way that invalidates old sheets → bump `custom_character.prompt_version`.

## Conversion

`CustomCharacterBuilder::execute()` runs the chain above, then: `SpritePixelator` (one scale for every pose from the median idle/walk height → `sprite_height`; each output pixel averages only ink pixels; one shared palette of `palette_colors` via `PaletteQuantizer`, a median cut that splits the widest colour range so rare colours like pupils survive) → `RosterToneMatcher` (scales saturation/lightness toward the roster's muted look) → `SpriteOutliner` (1 px `outline_color` on the outer edge) → `SpriteStripBuilder` (100×100 frames, feet on row 58 = `FOOT_ROW`, centred by the lower body; idle holds the last idle pose and bobs, walk/attack/death hold each pose; a pose wider than 98 px or taller than the 58 rows above the foot line is shrunk to fit instead of bleeding into the next frame). Fighter frames stay **100 px** — never upscale.

Animation table it produces (frames @ rate): idle 6 @ 8, walk 8 @ 10, attack 6 @ 12, death 3 @ 6.

## Storage

`CustomCharacterStore` owns every path and every write, on the disk named by `custom_character.disk` (`public` by default; `CustomCharacterStore::disk()` is the one place that reads it). Confirmed strips are `custom-characters/{user}/{hash}/{idle,walk,attack,death}.png`; a draft's are `custom-characters/{user}/staged/{hash}/…`, so a draft can never be mistaken for the fighter in use. The hash is of the PNG content, so a changed fighter gets a new URL (hosts cache non-hashed assets for days). The uploaded sheet is never kept.

- **Writes are checked.** A full or unwritable disk throws `CustomCharacterStorageException` (and removes what was half-written) instead of letting a row point at files that were never saved. Cleanup (prune, forget, discard) never throws: it runs after the database changed.
- **Order.** Stage writes the new files, then the draft row, then removes older staged files. Confirm promotes the files, then commits the rows, then prunes versions that left the history. Nothing is deleted before the row that stops referencing it is gone.
- **One build at a time per player.** `CustomCharacterLock` (`Cache::lock`, key `CacheKeys::customCharacterBuild`, waits `lock_seconds` then `CustomCharacterBusyException`) wraps stage, confirm, equip-version and delete-version; the rate limiter counts attempts but does not serialise them. Other players never wait.
- **Where it is served from.** `baseUrl()` is a same-origin **path** when the disk's URL is on the app's host (the battlefield reads the strips' pixels for the heel, which a cross-origin image forbids) and the **full URL** when the disk is another host (a bucket or CDN such as the `r2` disk). In that case the host must send CORS headers: the loader and the heel probe set `crossOrigin = 'anonymous'` for `http(s)` URLs, and `stripUrl()` accepts them. Taking an image down behind an `immutable` CDN cache also needs a purge, which this app does not do.

Uploads are rate limited (`uploads_per_hour`, key `custom-character-upload:{user_id}`) because a build costs several seconds of CPU.

## Services (`app/Services/CustomCharacter/`)

Pipeline (above): `PoseSheetDecoder`, `PoseSheetKeyer`, `PoseSheetLocator`, `StrayMarkRemover`, `PoseSheetValidator`, `SpritePixelator`, `PaletteQuantizer`, `RosterToneMatcher`, `SpriteOutliner`, `SpriteStripBuilder`, `CustomCharacterBuilder`. Storage and flow: `CustomCharacterStore`, `CustomCharacterLock`, `StageCustomCharacter`, `ConfirmCustomCharacter`, `DiscardCustomCharacterDraft`, `RenameCustomCharacter`, `EquipCustomCharacterVersion`, `DeleteCustomCharacterVersion`, `RemoveCustomCharacter`, `AnnounceCustomCharacter` (broadcasts inside `rescue()`: a dead Reverb must not undo a change). View models: `PreviewStrips`, `CharacterPromptBuilder`.

## The editor

`Livewire\CustomCharacterEditor` (`variant` `page` or `sheet` only picks the look; styles are `resources/css/custom-character-editor.css`, light by default, dark under `.fs`). It shows, top to bottom: a banner, the **draft preview**, the upload form, the **fighter** (strips, name, remove) and the **earlier versions**. Method names avoid `upload`, `removeUpload`, `uploadMultiple`, `cancelUpload`: in the browser those are Livewire's own file-upload functions and win over a component method of the same name (a form once submitted to `upload()` never reached the server). Every change that touches what is equipped dispatches the browser event `custom-character-changed` with `{custom: sheetState|null, equipped: key}`, which the sheet's Character tab and Profile mini-stage listen to. Each failure the player can act on (bad sheet, busy, storage, no such draft/version) shows its message; anything unexpected is rethrown, never hidden.

## On the battlefield

While the custom fighter is equipped it wins over the equipped roster character and over the per-boss assignment. `User::characterForBoss()` is unchanged and still sends a roster key as the fallback an old client (or a failed load) shows.

**Wire shape.** `User::customFighterState()` → `CustomCharacter::broadcastState()`: `{version, url_base, animations}` — the version hash, where the strips are served from (see Storage), and the `{frames, rate}` table. It rides as `custom_character` (null for everyone else) on `FighterJoined`, `FighterCharging` (a synthetic client-side rejoin), `FighterCharacterChanged` and each `BossSpawned.fighters[]` row, as `customCharacter` (same inner shape) on each boot-payload fighter, and on `/api/state`. A client that missed a change (Reverb has no replay) catches up from the next `FighterCharging`, which carries the same state (`charge.js` hands it to `updateCharacters` for a custom fighter, or for a fighter currently showing one).

**Client.** `fighter/custom-fighter.js` (pure) reads the payload (`normalizeCustomCharacter` takes `url_base` and the snapshot's `urlBase`) and builds a FIGHTER_TYPES-shaped entry — `key` `custom-<user>-<version>`, `texture` its own, `attackType` slash, no per-attack art. `fighter/custom-fighter-loader.js` fetches the four strips, stacks them into **one canvas texture** of named 100 px frames (`<key>-idle-0` … the names the atlas uses, so the fighter code reads both alike) and registers the animations; it dedupes concurrent loads and the texture belongs to the game, so a rotate-restart and a repeat request are free. `Fighter` shows a roster fighter first and swaps the custom one in when it lands (`_requestCustomFighter`). Every `setTexture`/`add.sprite` of a body goes through `textureOf(ftype)`.

**Request state** (`fighter/custom-fighter-requests.js`, one `entry.customRequest`): every update that names a custom fighter first states what is wanted now (`want`, even when nothing needs loading), and the newest wins — a late load for any other version is dropped and its texture released if nothing shows it; a load in flight blocks a repeat of the *same* version only (a swap a hit cut short is asked for again by the next update); a failed version waits `CUSTOM_RETRY_MS` (30 s) while a *different*, newer one is not held back. The completion callbacks of an animated swap check they are still the wanted look before acting, so a newer choice made during the death → summon is never reverted by the older swap.

**Animation state.** The swap lands in whatever state the fighter is in: charging → walk (the ring and bubble stay), idle → idle, mid-attack → the type changes under the playing attack and its completion hands over to the new look (`handleHit` reads `fighter.ftype.key` at completion, not the key it started with). The look a fighter leaves is released (`releaseCustomFighter`: its texture and animations), so a long session does not collect one canvas per rebuild; a roster look is never released (shared atlas). A look left while an attack plays is parked in `entry.releaseOnApply` and let go of when that attack ends (the playing animation still draws from it), by `_releasePending`.

**Size.** The strips are built `sprite_height` (28) px tall; the roster fighters are 18. `customFighterType` carries `bodyScale` (18/28) and `bodyOffsetY` (keeps the feet on the roster's line when scaled about the frame centre); `Fighter._fitBody` applies them, and puts a roster body back when a custom one is removed; the heel offset includes the nudge (`shared/heel.js`). `CUSTOM_BODY_PX`, `CUSTOM_FOOT_ROW` and `CUSTOM_FRAME` in `custom-fighter.js` copy `custom_character.sprite_height`, `SpriteStripBuilder::FOOT_ROW` and `FRAME`; `CustomCharacterConfigTest` fails if they drift.

**Snapshot.** `snapshotState()` writes `character` as the **roster** key (`entry.rosterKey`, kept from the payload's fallback — the boot payload only ever holds one) and `customCharacter` as the newest request, else the look on (`snapshotSpec`), so a rotate-restart builds the fighter from the textures already loaded, or requests them again.

Limits: an attack is always the slash animation; the minions and the roster's teammate faces still use the equipped roster character.

## In the fighter sheet

The Character tab (`livewire/fighter-sheet/character.blade.php`, `sheet/character-stage.js`) leads the roster with an action tile (`<button class="slot-add">`, deliberately not a `.slot` so selection, filters, keys and tooltips ignore it): **Create** until there is a custom fighter, **Manage** after. It opens the editor panel *in place of the stage* (the roster stays beside it; ← Back, picking any fighter, or the tile again return to the stage). Once the player has a fighter it is a **slot** beside the roster, named by the player, marked with an always-visible `CUSTOM` badge, tagged YOU while equipped, never hidden by a style chip, with an *Edit custom fighter* button under its stage. The panel is the editor component nested in `wire:ignore`; it announces changes with `custom-character-changed`, and the tab re-registers the roster entry, redraws the slot (rename, replace, remove, equipped) and moves the YOU tag with no reload. `FighterSheet` follows the editor (`#[On('custom-character-changed')]`, renderless) so a sheet that is closed and opened again rebuilds both tabs from the fresh state, and hands the tab `$customCharacter` (`CustomCharacter::sheetState()`: the broadcast state plus `name` and `active`, equipped or not) and seeds `$equipped` with `'custom'` while it is on; `equip('custom')` turns it on, `equip(<roster key>)` turns it off, and both broadcast.

`sheet/custom-roster.js` makes it look like a roster fighter to the sheet's sprite helpers: `registerCustomRoster` puts a roster-shaped entry (`anims` whose `file` is the served strip URL, one attack named `attack1`; `sheet-roster.js`'s `stripUrl` passes a path or `http(s)` URL through) and a fixed body box into `BY_KEY`/`BOX` under key `custom`, and removes them when the user has none, so nothing lingers from an earlier mount. `nameOf(key)` (`sprite-strip.js`) is the player's name for `custom`. The **Profile tab's mini-stage** registers it too (it mounts first), shows it while equipped, and follows the editor's events and live `CHARACTER_CHANGED` broadcasts.

## The prompt

The page offers nine character types (warrior, wizard, assassin, archer, healer, necromancer, knight, alchemist, berserker); picking one shows that type's prompt. `config('token_slayer.custom_character')` holds the shared template (`prompt`, with `{LABEL}`, `{label}`, `{COSTUME}`, … placeholders) and the per-class wording (`types`: the six costume items body/waist/feet/cape/weapon/hat, the class `identity`, what it must not look like (`avoid`), its row-3 `attack`); `CharacterPromptBuilder::execute()` fills one prompt per type. Each prompt makes the type the authority for the costume, lists the class defaults, and leaves a **COSTUME ITEMS block empty**: the player types into a line to override that one item, and an empty line means the class default. Adding a type = one more entry in `types` (the order is the picker's) and a thumbnail. Every prompt also carries one shared **PIXEL ART STYLE** block (identical for all types: chunky grid-aligned pixels, no anti-aliasing, 2–4 shades per material, dark navy/charcoal outlines, strict side profile for the side-view rows, one unified sprite set); the template's COLORS line must not contradict it. The layout rows and the magenta background stay in the shared template because the converter depends on them; bump `prompt_version` if they change.

Each type button shows a thumbnail, `public/assets/custom-character/types/<type>.png` (128 x 128, transparent, one per key of `types` — `CustomCharacterEditorTest` fails when a type has none). They were cut from one 3 x 3 image an image AI drew with all nine costumes on a magenta background: the sheet split in thirds, the magenta keyed out with the key colour taken back out of soft edges, stray pieces under the feet dropped, one scale for all nine, feet on a common line. A new type needs a thumbnail made the same way.

The picker is `resources/js/custom-character/prompt-picker.js` (Alpine `characterPrompt`; all prompts are sent with the page, no round trip). Switching type swaps the prompt but carries over the items the player already typed, via `prompt-items.js` (`readItems`/`fillItems`, which only touch the lines under the `COSTUME ITEMS (` heading, never the defaults listed above it), and scrolls the prompt into view without taking focus from the tiles. The guide section is static (`partials/custom-character-guide.blade.php`), so a build or a refusal never wipes what was typed.

## Admin

`Filament\Resources\CustomCharacters\CustomCharacterResource` (list only): player, name, equipped, versions, updated. Remove needs `Delete:CustomCharacter` (the list needs `ViewAny:CustomCharacter`); both permissions are created by a migration, assign them to a role in Shield.
