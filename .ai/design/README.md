# Design system (game UI)

How anything a player sees in token-slayer is built so it looks and behaves like one product. Read this before adding or changing a screen, a panel, a control or a piece of copy. The files here describe what the code does today. Statements with "must" or "never" are rules for new work: older code may not follow them yet, and when you touch it you fix it rather than copy it.

| File | Covers |
|---|---|
| `game.md` | The game itself: the idea, who plays, the loops, the systems map, the flows that exist, the knobs, the feel inventory, pacing, fairness, and what is not designed yet |
| `tokens.md` | The three visual worlds, colours, fonts and where each is loaded, shapes, spacing, the known drift between token sets |
| `components.md` | Every shared piece: panel, buttons, tabs, tiles, chips, tags, alerts, tips, toast, scrollbars, stat tiles, with real class names and states |
| `patterns.md` | Confirming and destructive actions, empty/loading/error/success states, forms and uploads, previews, links that leave the view, copy |
| `motion.md` | Durations, easing, what may animate, reduced motion, pixel-art and sprite preview rules |
| `layout.md` | Breakpoints, phone/landscape rules, safe areas, scroll containers, z-index scale, touch targets, accessibility |
| `skills.md` | Which installed game-dev agent skills apply to this repo, when to load each, and the repo rule each one adds |

## Which world is my screen in?

| World | Where | Look | Source of truth |
|---|---|---|---|
| Game (dark) | the battlefield HUD (`.bf-*`) and the fighter sheet (`.fs`, a modal over the live battlefield) | dark navy panels, square corners, gold outline, pixel fonts | `resources/css/battlefield-hud.css`, `resources/css/fighter-sheet.css` |
| App (light) | `/setup`, `/guide`, `/history`, `/character` guide, account nav | white/gray Tailwind pages, orange accent | Tailwind utilities in the blades, `resources/css/app.css` |
| Admin | `/dashboard` | Filament | Filament components only; do not restyle |

Rules: anything a player does while the battlefield is open (choose, create, edit, confirm, delete a fighter) belongs to the **game** world and lives inside the sheet. A page in the **app** world only explains or links; it does not duplicate a game-world editor. Never build a third look.

## Before you build UI (checklist)

0. For a new feature or a change to a flow, start from a short design brief (`.claude/skills/game-design/`) placed on the map in `game.md`: journeys, flows, feel, rules, states, edge cases. The steps below are what the brief's answers are built from.

1. Pick the world above and reuse its classes and tokens; do not add colours, fonts, radii or shadows that are not in `tokens.md`.
2. Look for the piece in `components.md` first; if it does not exist, build it from the same tokens and add it there in the same PR.
3. Cover every state: default, hover, focus-visible, active, selected, disabled, loading, empty, error, success (`patterns.md`).
4. Destructive or irreversible action: use the in-game confirm of `patterns.md`, never `window.confirm` / `wire:confirm`.
5. Motion: durations from `motion.md`, a `prefers-reduced-motion` rule, no animation that runs faster than the one in the game.
6. Responsive: use the four layout classes, recipes and per-class checklist in `layout.md`; keep every touch target at least 44 px.
7. Keyboard and screen reader: reachable and operable by keyboard, a visible focus ring, labelled controls (`layout.md`).
8. Copy: developer audience, no sentence that explains the obvious (`patterns.md`).
9. Verify on staging with a screenshot per state, desktop and phone width; the team verifies on staging, not locally.
10. Add or update the rows in this folder and, for a changed domain area, the matching `.ai/domain/*.md`.

## Keeping this folder true

This folder is documentation of the code. When a token, a component class or a rule changes in `fighter-sheet.css`, `battlefield-hud.css` or `app.css`, change the matching file here in the same PR. If this folder and the CSS disagree, the CSS is what runs: fix the doc.
