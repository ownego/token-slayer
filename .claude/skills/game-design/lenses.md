# Lenses

A **lens** is one independent read of a brief with a single question in mind. A brief is read by a person first; lenses add a second pair of eyes, ideally someone other than its author. Findings are suggestions: each names the brief section it would change, and the person accepts or declines it.

| Lens | Question | Looks at |
|---|---|---|
| Player | Would a developer mid-work feel this is the game, and want it? | Sections 1, 2 and 4: the fantasy, the journeys, attention and interruption, the first minute and the tenth day |
| Product | Is this the right thing, and what does it commit the team to maintain? | Sections 1, 5 and 10; whether the lifecycle serves the stated outcome; for an internal tool, cognitive load and the risk people route around it |
| Design | Would a builder be blocked by a missing decision? | Sections 2, 3, 4 and 8: entry points, every state, flows with a way back and a failure branch, responsive and keyboard behaviour, anything left as "TBD" or "user-friendly" |
| Scope | Is it right-sized, and does every new piece have a user today? | Sections 1 (systems touched), 6 and 10; reuse of what exists; the smallest change that delivers the outcome |
| Adversarial | How could this ship as written and still fail? | Sections 3, 5, 7 and 9: concurrency, half-done writes, abuse of player-made content, deploy windows, the case nobody listed |

Standard briefs offer Player and Design. Deep briefs take all five. Quick briefs skip the lenses.

## With the `compound-engineering` plugin

Save the brief to a scratch file outside the repo (the scratchpad directory, not `docs/`). Start the file with one line saying it is a requirements brief for a feature, a what-to-build document, because the plugin classifies a document by its shape and a plan-shaped reading would ask for implementation detail. Run `compound-engineering:ce-doc-review <path>`; add `mode:headless` before the path to get the findings back as text without questions. The personas line up with the lenses: `ce-product-lens-reviewer` (Product and, for the idea, Player), `ce-design-lens-reviewer`, `ce-scope-guardian-reviewer`, `ce-adversarial-document-reviewer`. Act on findings with confidence `75` and above; treat `50` as a question to the person.

## Without it

One pass, one checklist, ideally by someone other than the author (a teammate, or a fresh Claude session given only the brief):

1. **Would a player want it?** Does it fit the fantasy in `game.md`, serve a loop, and respect the player's attention?
2. **Blocked?** Is any decision a builder would need left open or vague (error wording, phone layout, who may undo)?
3. **Smallest?** Is there a smaller change that delivers the outcome, or an existing component that already does a part?
4. **Fails how?** Name one way it ships as written and still fails (two tabs, a half-done write, an old row, someone else changing it meanwhile).
5. **Worth keeping?** Would the team be glad to maintain it a year from now?
6. **Game checks** (`.ai/design`): one world per screen with its components (`components.md`); destructive steps use the in-game confirm and all states are covered (`patterns.md`); previews at game rates and integer scales (`motion.md`); phone, keyboard and reduced motion named (`layout.md`).

## Recording the outcome

After the lenses, add a short `Lens notes` list to the brief: finding, accepted or declined, and one line of reason for each declined one.
