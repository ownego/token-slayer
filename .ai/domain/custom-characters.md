# Domain: Custom characters (player-uploaded fighters)

A player can upload one **pose sheet** they generated with an image AI (from the prompt in `config/token_slayer.php` → `custom_character.prompt`) and get their own fighter. Nothing calls an AI from the server: the player does that step, and the app only validates and converts the result. Page: `/character` (`Livewire\CustomCharacterUpload`).

**Status:** the upload, conversion, storage and preview are done. The character is **not yet shown on the battlefield** — that needs the strip URLs and animation table added to the `FighterJoined` / `FighterCharacterChanged` payloads, the boot payload and `snapshotState()`, plus dynamic texture loading in Phaser (see `broadcasting.md` and `battlefield.md` before touching it).

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
