# Game skills that apply to this repo

The agent skills from the `awesome-gamedev-agent-skills` marketplace (plugins `disciplines`, `web-engines`, `genres`, `workflows`, engine sets) are installed per developer, not in the repo. This file says which of them fit token-slayer, when to load each, and the repo rule each one adds. Load the skill, then follow the repo's rule where the two differ.

| Task | Load | What the repo takes from it |
|---|---|---|
| Any HUD, sheet, menu or control | `disciplines:game-ui-ux` | Anchored/container layout, safe area, keyboard focus on open, event-driven (not polled) updates. Applied in `layout.md` and `patterns.md`. |
| Feedback on hits, equips, builds, kills | `disciplines:game-feel` | Feedback attaches to discrete events; start with 2-3 channels; ease everything; shake the camera/visual, never the simulated body; hit-stop and shake only for impacts, once per impact; effects return to rest; a reduced-motion switch. See `motion.md`. |
| Frame drops, load stalls, heavy sheet | `disciplines:performance-optimization` | Measure first (browser Performance panel on staging, not a guess), find CPU vs GPU, fix the biggest single cost, re-measure, report numbers. Pool spawned objects (minions, bat swarm, projectiles, sparks); atlas to cut draw calls; avoid overdraw from stacked transparent layers and full-screen effects; animate `transform`/`opacity` only. Keep a budget per subsystem. |
| Anything persisted or restored (custom fighter files and rows, snapshot/boot payload, cached fighter state) | `disciplines:save-systems` | Persist data, not objects; a version or content hash on every stored artefact; write to a temp location then move into place; load defensively (validate, fall back to the last good one); migrate old shapes up; verify by save -> quit -> reload -> compare. Matches the repo's `snapshotState()` <-> boot payload round-trip, content-hashed strips and "write the row only after the files exist". |
| New or changed sprites, palettes, prompts for AI art | `disciplines:create-game-assets`, `pixel-art-sprites` | A style bible per asset family, one shared palette and outline, fixed frame size (100 x 100) and integer scales, validate before accepting. Credit in `CREDITS.md`. |
| Keys, touch, gamepad, rebinding | `disciplines:input-systems` | Name actions, not keys; every pointer action has a keyboard path; touch targets 44 px. See `layout.md`. |
| Boss, minion or fighter behaviour | `disciplines:game-ai`, `ai-behavior-trees-utility-ai` | State machines for animation/behaviour states, scripts per boss as the unit of behaviour, decision logic as pure modules tested without Phaser. |
| Camera moves (focus on equip, framing) | `disciplines:camera-systems` | Smoothing and clamping; shake as a camera offset. `shared/camera-focus.js` already follows this. |
| Custom shader or outline effect | `disciplines:shader-programming` | Only when a Phaser pipeline/effect is needed; justify against `performance-optimization`. |

## Not applicable here

`physics-tuning` and `phaser-arcade-physics` (the battlefield has no physics world), `procedural-gen`, `level-design`, `audio-design` (no audio code today), `save-systems` file formats and slots (the repo has a database, not save files), the `genres/*`, Godot, Unity, Unreal, Roblox, Bevy, Love2D, Pygame and publishing workflows.

## Version caveat

`phaser-core` and `phaser-arcade-physics` describe Phaser 4; the repo is on Phaser 3 (`^3.90`). Names and lifecycle details differ: treat them as background only and check `resources/js/battlefield/CLAUDE.md` and the Phaser 3 docs for anything you write.

## Adding to this list

A new skill is added only after it was used on a real change in this repo and the change is named here. Keep each row to the repo rule it produced, not a summary of the skill.
