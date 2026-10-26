---
name: game-design
description: Use when designing or changing anything a player sees or does in the game — a feature, fighter, screen, HUD, ceremony or flow — to place it on the game's map and design its whole flow before code, sized to the change.
---

# Game design

Designing a feature means deciding how it plays across the whole game, not only the happy path that was asked for. A feature built from one path ships with its surroundings missing: the preview, the name, the undo, who else sees it, what it feels like, how it fails. This skill walks that design in order, in words, while it is cheap to change. The result is a **design brief** the team can read.

It is an offer to the person building the feature. They own the decision to skip or shrink it; the PR line in step 10 is a record, not a gate.

## Steps

1. **Size it, out loud**, in one line with the reason, so the person can change it.
   - *Quick*: a small change inside a flow that already exists, storing nothing new. Ask the quick questions below, in chat.
   - *Standard*: a new flow or screen. Fill sections 1, 2, 3, 5, 8 and 9 of `design-template.md`, plus any other section the feature touches.
   - *Deep*: a new kind of player-made content, a new system, or a change others build on. Fill every section, then take it through the lenses.
   A feature that adds a table or column, an upload, a permission or a broadcast change is at least *Standard*. When unsure between two sizes, name both and say what each would cost; the person picks.
2. **Read the map.** `.ai/design/game.md` (the idea, the loops, the systems, the knobs), then the matching `.ai/domain/*.md`, `.ai/design/README.md`, and the code of the flow being changed.
3. **Write the understanding back.** Three or four lines: what the player wants to do, which loop it serves, what success looks like, separating what the person said from what you assume. Wait for their correction.
4. **Place it on the map** (section 1). Name the fantasy it supports, the loop or loops it feeds, the systems it touches and the rules of the game it must keep (`game.md` sections 4 and 9). A feature that serves no loop is a question for the person before it is a design.
5. **Walk the journeys and the flows** (sections 2 and 3). Take each person who meets the feature through discover, first use, everyday use, the peak, going wrong, undoing, returning later, and being seen by someone else. Write each flow as a small state table: states, what moves it, what each person sees, how it ends or is cancelled. Questions here are one at a time when the answer is not in the repo.
6. **Design the feel and the pacing** (section 4). Pick the moments that deserve feedback from the existing vocabulary (`game.md` section 7), scaled to their rarity, and say what interrupts the player and what must stay ambient.
7. **Set the rules and knobs** (section 5): what is cosmetic and what is power, each limit with its reason, who may remove what others see.
8. **Close the remaining sections:** systems impact, persistence and concurrency, screens (states, phone, keyboard, access), edge cases, out of scope, and a success check the builder can observe. For every screen, name the closest working flow it follows and walk the Interaction rules of `.ai/guidelines/frontend.md` (instant, one stage, alive, lands on the field, busy and refused) against it; the PR template's checklist is built from them.
9. **Look through the lenses** (offered for Standard, taken by default for Deep): `lenses.md`. Findings are suggestions, each naming the section it would change; the person accepts or declines each.
10. **Put the brief where the team sees it:** the PR or issue description, or one line `No brief: <size and reason>`. Questions that block building are answered first; the others stay listed with a name and a date.
11. **When the feature lands**, update `game.md` where the map changed, the matching `.ai/domain/*.md` for rules and data shapes, and, when a component or rule is new, `.ai/design/`. After review, note in the PR how many comments asked for behaviour the brief did not cover: that count shows whether the brief earns its time.

## Quick questions

1. Which loop does this serve, and what does the player want from it?
2. Who sees the result, and when?
3. What changes in the flow: before, during, after, and how does the player undo it?
4. Which existing moment gives it feedback, and does it interrupt anything?
5. What does it show when empty, loading, failing or done; what happens on a phone and from the keyboard; does it store anything new (if yes, it is at least Standard)?

## Done when

- **Quick:** the five answers were given in chat and one PR line records the size.
- **Standard and Deep:** every section of the chosen size is filled or marked `n/a, because …`; each flow ends in a state and has a way back; the person has read the understanding from step 3 and the finished brief and said it is right (a nod counts); the brief sits in the PR or issue.
- **When the feature lands:** `game.md` and the domain docs are updated, or the PR says nothing durable changed.

## Skipping and shrinking

Skipping is a normal outcome for a one-line fix, a copy change or a bug with a known cause: one line in the PR (`No brief: <size and reason>`). A person who knows the game well may fill the brief themselves and ask only for the lenses.

## Related

- `design-template.md` — the sections, each a question.
- `example-custom-fighter.md` — a filled brief for the custom fighter, as a model of the depth wanted.
- `lenses.md` — reads of a brief from player, product, design, scope and adversarial angles.
- `.ai/design/game.md` — the map; `.ai/design/` — the design system the screens use.
- Installed game-dev skills that sharpen a step: `disciplines:game-ui-ux` (screens and focus), `disciplines:game-feel` (feedback), `disciplines:level-design` (pacing and tension), `disciplines:save-systems` (persistence). `.ai/design/skills.md` lists when each applies.
- Superpowers `brainstorming` and `writing-plans` (when installed) cover open-ended design and step-by-step plans.
