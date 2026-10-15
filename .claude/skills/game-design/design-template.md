# Design brief template

Each section is a question. Answer in a line or two; a table or a state list when it helps. A section that does not apply stays, as `n/a, because …`. Standard briefs fill sections 1, 2, 3, 5, 8 and 9 and any other section the feature touches; Deep briefs fill all of them. `example-custom-fighter.md` is a filled one.

## 1. Place on the map
- **Fantasy:** what does the player get that fits the idea of the game (`game.md` section 1)? Say it in one sentence.
- **Loops:** which of moment, session, progress and social does it feed, and how? One that it should not touch is named too.
- **Systems touched:** from `game.md` section 4, each with what it must now handle.
- **Rules kept:** the rules of the game it must respect (damage only from usage, cosmetic only, per-user keys, and so on).

## 2. Player journeys
A table of who meets the feature at which moment, and what they do and see. Moments: discover, first use, everyday use, the peak, going wrong, undoing or leaving, returning later, seeing it from outside. People: the one who does it, the ones who watch (live and on a later load), the admin.

| Moment | Does it | Sees it live | Sees it later |
|---|---|---|---|

## 3. Flows
One state list per flow (create, use, change, remove, and any flow that crosses people). For each: the states, what moves it from one to the next, who triggers it, what each person sees at each state, how it is cancelled, how it fails, what happens to everything that depends on it when it ends (fallback). A flow with no way back or no failure branch is not finished.

## 4. Feel and pacing
- **Moments:** which events deserve feedback, drawn from `game.md` section 7 and scaled by how rare the event is.
- **Attention:** what interrupts the player, what stays ambient, whether it can happen during a ceremony, and how it behaves on a wall display left open all day.
- **Previews** show the thing as the game will show it (size, rate), with the player in control of the pace.

## 5. Rules and knobs
- Cosmetic or power: does it change who wins, how much damage lands or what anyone earns? (Aim: no.)
- Each limit as a knob: name, default, the reason for the default, where it lives (`game.md` section 6).
- Player-made content: owner shown, a way to remove it, who may, what happens to its users, and what a bad actor could put in it.

## 6. Systems impact
For each system in section 1: what changes in it (roster and filters, the sheet's tabs, broadcast events and the boot payload, the HUD and team panels, the admin panel), and which test or doc pins it.

## 7. Persistence and concurrency
What is stored, with which version or hash; what is written first; what happens when a write fails half way; what two tabs or two people doing the same step at once should result in; what is cleaned up, when, and by whom; what deploying does to rows that already exist and to rows created just before the deploy.

## 8. Screens: states, phone, keyboard, access
Per screen: where the entry point is and how a person finds it (and whether it opens in the same tab or a new one); what is shown first, second and third; empty, loading, working, error, partial, success, disabled, each with message, next action and whether input is kept; phone layout in portrait and landscape; the keyboard path (order, focus on open and close, Escape); touch targets and what replaces hover; labels and live announcements; what reduced motion shows instead of each feel item. Which world (game, app, admin) and which existing components (`.ai/design/`).

## 9. Edge cases
At least three chosen for this feature: the empty case, the biggest case, the repeated case, the unfinished case, the case after someone else changed it, the case of a viewer who joins mid-way.

## 10. Out of scope
What this brief leaves for later, with one line on why, so a later reader knows it was a decision.

## 11. Success check
How the builder shows on staging that it works, observable rather than felt: which flows and states to walk, on which screens and sizes, and a keep-or-rework rule such as "a teammate who has not seen it finds the entry point without help" or "two sessions show the same fighter within a second".

## Questions
One line each, with a name and a date. Mark each as **blocks building** (answered before the hand-over) or **can follow**.
