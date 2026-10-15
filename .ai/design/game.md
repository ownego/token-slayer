# The game

What token-slayer is as a game: the idea, who plays, how a moment, a session and a season feel, which systems exist and how they connect. Read it before designing anything a player sees or does, then place the feature on this map (`.claude/skills/game-design/`).

**Status.** Drafted from the code and `.ai/domain/*` on 2026-10-15. Statements about what the game does were read from the code. Statements about intent are marked `(?)`: the owner confirms or corrects them, and a correction here is a design decision. When a feature lands that changes this map, update this file in the same PR.

## 1. The idea

Real work is the controller. A developer who uses Claude Code, Codex or a similar tool sends usage events; each finished turn deals damage to one shared boss on a live battlefield, so a team fights a monster together by doing its normal work. The fantasy is *the team's everyday effort is visibly a battle*: you do not play, you work, and the game turns that work into presence, progress and shared moments. `(?)` A second layer is the admin side: the same data shows the org's shared AI accounts and their quota.

Consequences that shape every decision:
- **The player's attention belongs to their work.** The game never asks for input to keep going, and any screen it opens must be skippable and quick to leave.
- **Everything is an event.** Damage, arrival, activity and subagents come from hook events; nothing the player clicks changes the fight's outcome.
- **The wall is shared.** Many people see the same field: what one person shows or does is seen by all.

## 2. Who plays

| Role | Does | Sees |
|---|---|---|
| Developer (player) | works in their tools; optionally opens the fighter sheet to equip a fighter or read stats | their fighter acting on the field, their damage and kills, teammates, the boss |
| Teammate / viewer | works, or leaves the battlefield open on a screen | everyone's fighters, boss HP, rank changes, kill announcements |
| Admin | manages org accounts, attribution, quota, roles | the dashboard (Filament), alerts |
| Wall display `(?)` | the battlefield left open all day | the whole field, long sessions |

## 3. Loops

| Level | Loop | Today |
|---|---|---|
| Moment (seconds) | a tool call or a finished turn -> something visible on the field | a working fighter shows a charge ring and an activity bubble; a finished turn is a hit: damage number, boss reaction, combo tick |
| Session (an hour) | keep working -> keep fighting -> the boss's HP drops, your rank moves, your combo holds (60 s window) | subagents appear as minions beside the fighter; a fighter with no event for the window (30 min by default) is marked idle by the sweep |
| Progress (days) | boss after boss, kills accumulate, ranks settle | boss number rotates through monsters; ThaNode comes after every seventh generic boss and gains an infinity stone at 09:30, 14:00 and 17:50 (Asia/Ho_Chi_Minh), up to six; per-user kill count and per-day best combo |
| Social (always) | being seen -> seeing others -> shared moments | join ceremony (the Necromancer summons a new fighter), the herald strip (kills and spawns, 4.5 s), the kill ceremony (slow motion, the killer's card, countdown), a Slack post on a kill, rank overtakes |

`(?)` There is no spending, unlocking or crafting loop: all 20 roster fighters can be equipped from the first day. Whether progress should unlock anything is open.

## 4. Systems map

| System | Is | Serves loop | Rule that must hold |
|---|---|---|---|
| Event ledger | every hook event appended; damage and totals derive from it | all | append-only; aggregates derive from events |
| Boss arena | the current boss, HP (1,000,000 base), overflow to the next boss, kill chain, rotation, ThaNode and stones | moment, progress | damage lands on the boss only; overflow rolls over |
| Fighter | one per user on the field, assigned by user and boss, or the equipped one; charge ring, bubble, model flair ring | moment, session, social | keyed by user id; sprites are 100 px frames, never upscaled |
| Roster and fighter sheet | the 20 fighters, filters by attack style, a Character tab with Equip and undo, a Profile tab with damage, tokens by model, quota gauges, alerts, the combo buddy, a mini stage | session, social | the sheet is a modal over the live battlefield |
| Custom fighter | a player's own fighter built from an uploaded pose sheet | social | strips served per version; every client shows the same fighter; cosmetic only |
| Minions and companions | subagent swarm, bats, the Necromancer | moment, social | cosmetic only, never feed damage or HP |
| HUD | team totals, your damage, the damage board with rank changes, boss plate, feed, herald, nav | session, social | follows the screen: landscape and portrait layouts, a phone stats sheet |
| Ceremonies | join, kill, boss spawn | social | slow motion and shake are for kills; reduced motion is honoured |
| Accounts and quota | org accounts, attribution, probing, rebalance, alerts | the work-tool layer | per-event attribution, never inferred from membership |
| Admin | Filament dashboard, roles and permissions | admin | policy per resource; permissions through Shield |

## 5. Flows that already exist (each is a state machine; features hook into them)

1. **First run.** Install the hook -> the first event -> the fighter joins (the Necromancer ceremony) -> the sheet shows a `newbie` state with next steps. `(?)` Discovery of the sheet and of fighter choice.
2. **A normal day.** Session starts (hook update check) -> events -> fighter charges while working -> finished turns hit -> idle window passes -> the sweep marks the fighter idle -> the next session starts again.
3. **Equip a fighter.** Open the sheet -> Character tab -> pick a slot (filters, hotbar to try moves) -> Equip -> toast with undo -> the sheet folds and the camera pushes in on your fighter -> everyone sees the change.
4. **A boss falls.** HP crosses zero -> kill ceremony -> herald and Slack -> next boss spawns (overflow carries) -> the board updates.
5. **Reconnect or rotate.** The scene restarts from the boot payload; the state it rebuilds must equal what live events produced (`snapshotState()` round trip).
6. **Quota and alerts.** Accounts reach a threshold or a token is rejected -> alert in the sheet and in Slack -> an admin reconnects.

## 6. Knobs (where the numbers live)

| Knob | Value | Where |
|---|---|---|
| Boss base HP | 1,000,000 | `config/game.php` |
| Fighter idle window | 30 min | `config/game.php` |
| Subagent idle window | 60 s | `config/game.php` |
| ThaNode stones | start 1, ticks 09:30, 14:00, 17:50, max 6 | `config/game.php`; the client mirrors the max |
| Combo window | 60 s | `sheet/clawd-buddy.js` |
| Herald duration | 4.5 s | HUD |
| Fighter size by headcount | 45/36/27 px landscape, 54/45 px portrait | `battlefield/layout.js` |

A new knob has a name, a default, a reason for the default, and a place in this table.

## 7. Feel inventory

Moments that already carry feedback and are the vocabulary to reuse: hit (damage number, boss reaction, combo), charge (ring, bubble), join (summon circle and rise), kill (slow motion, shake, card, countdown), equip (toast with undo, fold, camera push-in), rank overtake (roll and climb), minion spawn (scaled summon). A new moment starts from one of these, scaled down or up; the strongest feedback is kept for the rarest events.

## 8. Pacing and attention

- The field is ambient: nothing demands a click. A flow that needs the player's focus (building something, reading a long list) lives in the sheet, which is opened on purpose and closed with one key.
- Interruption budget: one ceremony at a time; a kill outranks a join; a toast is the right size for anything a player did themselves.
- Peaks are rare and shared (a kill, a new boss, ThaNode's last stone); the ordinary hit stays small so peaks read.
- Long sessions are normal (a wall display for a day): anything that grows with time (textures, listeners, rows) must be released.

## 9. Fairness and tone

- Nobody gains power from a setting: appearance (fighters, names, flair) is cosmetic; damage comes only from real usage.
- The wall is public to the team: content a player makes and others see (a name, an image) needs an owner shown, a way to remove it, and a moderator.
- The audience is developers: copy is plain and short, and the humour lives in the art and the ceremonies.

## 10. Not designed yet `(?)`

Progression that unlocks something; a reason to open the sheet daily; recognition beyond kills and rank (seasons, titles); onboarding beyond the `newbie` state; what a viewer who does not work sees; sound.
