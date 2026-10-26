## Why

<!-- The problem, in the player's or operator's words. -->

## What

<!-- The change, grouped by area. -->

## Brief

<!-- For anything a player sees or does: the design brief (`game-design` skill), or one line `No brief: <size and reason>`. -->

## Screens a player uses

<!-- Keep this section when the PR touches a screen; tick each line on staging. Rules: `.ai/guidelines/frontend.md`, Interaction rules. -->

- [ ] Closest working flow named, and every difference from it explained: <!-- e.g. Fighter tab roster + Equip -->
- [ ] Instant: picks, previews, toggles and tab or move switches send no request (network panel on staging)
- [ ] One stage: the preview element keeps its key while the selection changes
- [ ] Alive: what appears, changes or leaves plays the game's motion; reduced motion changes at once
- [ ] Lands on the field: a commit that changes the field folds the sheet onto it, or says where it will show
- [ ] Busy and refused: in-flight label, second press ignored, a refusal keeps the choice and says why
- [ ] Phone width and keyboard checked

## Verification

<!-- Tests run with counts, staging checks with what was seen. -->

## Deploy

<!-- Migrations, restarts, permissions, or "Code only". -->
