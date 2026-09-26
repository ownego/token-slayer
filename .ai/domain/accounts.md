# Domain: Org Accounts, Attribution & Quota

Related: `token-tracking.md` (where `account_*` fields arrive on each event). The admin UI for everything here is Filament (`app/Filament/`, served at `/dashboard`).

An **Account** is one org-owned AI subscription, identified by its login email and scoped per provider (`accounts.provider`, enum `App\Enums\Provider`: `claude` | `codex`; email is unique per provider). Developers (users) are members of zero or more accounts through the `account_user` pivot. One user regularly switches between accounts (personal + org), so attribution is **per event, never per user**.

## Schema

| Table | Holds |
|---|---|
| `accounts` | Provider-agnostic envelope: `id`, `email`, `name`, `provider`, timestamps (plus legacy Claude columns, see below) |
| `claude_credentials` (`Account::claudeCredential()`, HasOne) | `plan` (`AccountPlan`), `organization_uuid`, `organization_type`, `rate_limit_tier`, `account_uuid`, `oauth_access_token`/`oauth_refresh_token` (encrypted), `oauth_expires_at`, `oauth_refresh_expires_at`, `status`, `last_probed_at`, `last_refreshed_at`, `probe_error` |
| `codex_credentials` (`Account::codexCredential()`) | `chatgpt_account_id` (unique), `chatgpt_user_id`, `plan_type`, `codex_access_token`/`codex_refresh_token`, `codex_expires_at`, `earliest_refresh_at`, `last_refreshed_at`, `last_probed_at`, `status`, `probe_error` |
| `account_user` | Pivot with `status` (`MembershipStatus`) |
| `devices` | A user's machines (`device_id` fingerprint; `'default'` for the legacy CLI) |
| `account_provisioned_grants` | Per (account, device) grant: `status` (`GrantStatus` pending/claimed/revoked), `provisioned_at`/`claimed_at`/`revoked_at`/`deprovisioned_at`, session expiry, and the encrypted pending secret |
| `account_usage_snapshots` | Append-only probe results (5h/7d utilization in percent 0–100, resets, raw JSON) |
| `rebalance_plans` | Adopted rebalance plans |
| `events.account_id` / `account_email` / `account_source` / `account_org_id` | Per-event attribution (written once at ingest) |

**Proxy accessors:** `Account` exposes the Claude credential columns (`$account->organization_uuid`, `->status`, `->oauth_access_token`, …) through Eloquent `Attribute` accessors. Its `saved` hook persists a dirty `claudeCredential`. `Account::plan` is Claude-only; Codex plan comes from `codex_credentials.plan_type` (`CodexPlan`). Raw query-builder `where()`/`orderBy()`/`join()`/`pluck()` on those names must go through the relation instead (`whereHas('claudeCredential', …)`, `whereRelation('claudeCredential', 'organization_uuid', $uuid)`). The old columns still physically exist on `accounts`; a later deploy is meant to drop them, so never read them directly.

**Provider dispatch:** callers never branch on `Account::provider`. They ask `ProviderServiceFactory` for the provider's `UsageProberContract` (`UsageProber` / `CodexUsageProber`), `GrantRevokerContract` (`AccountProvisioningService` / `CodexProvisioningService`), or `AccountDisconnecterContract` (`AccountConnectService` / `CodexConnectService`).

## Membership states (`account_user.status`)

`MembershipStatus` records how far a user's setup has progressed for that account. It never drives attribution (events do); it only feeds the admin UI and the provisioning handoff. The Members relation manager relabels two of them:

- `untracked`: a known contributor (auto-created by `AccountMembershipRecorder` on their first attributed event) who has not confirmed setup. Shown as **"Unverified"**, hidden behind the *Unverified members* filter, and can be verified (promoted) in place.
- `tracked`: setup confirmed. Shown as **"Verified"**.
- `pending`: an admin provisioned a grant and is waiting for the user's machine to confirm. Shown as **"Pending setup"**.

## Attribution (which account served this usage?)

Verified constraints (2026-07-10, do not re-litigate):
- Hook payloads and transcripts carry NO account identity.
- `~/.claude.json → .oauthAccount` (email/uuid/org/tier) exists on all OSes but goes **stale** when credentials are swapped externally (ccm-style switchers).
- Setup-tokens (`sk-ant-oat01…`) are rejected by `/api/oauth/usage` and `/api/oauth/profile` (missing `user:profile` scope).

Client side (in the hook helper), the identity comes from `~/.config/{namespace}/account.json` (`{"email","uuid","source","updated_at"}`, written by the CLI's account switcher or by ccm/claudehub), falling back to `~/.claude.json → .oauthAccount` (`source=auto`). The org id beacon goes in `account_org_id`: the Anthropic organization uuid for Claude, the `chatgpt_account_id` for Codex.

Server side, `AccountResolver::resolve($orgId, $email, $provider)`:

1. `provider === 'codex'` → Codex accounts only; every other provider → Claude accounts only. This is a security boundary: a Codex `chatgpt_account_id` must never be written into a Claude `organization_uuid`.
2. Exact **org-id** match first (`accounts:org-map` / `accounts:codex-org-map`), then a lowercase **email** match (`accounts:email-map` / `accounts:codex-email-map`). All four maps are cached for 1 h and flushed by `Account` `saved`/`deleted` (`CacheKeys::forgetAccountMaps()`).
3. On an email match that also carried an org id, the resolver **learns** it (`learnOrganizationUuid` / `learnChatgptAccountId`). It never overwrites a different existing value; it logs the conflict instead.
4. `null` means personal/unknown. The raw claim stays in `events.account_email` / `account_org_id` for later backfill. The *Unrecognized* page (`UnrecognizedAccountsQuery`) lists org beacons that matched nothing, and `event-attribution:backfill` re-attributes them once the account exists.

## Connecting an account (server-side OAuth grant)

- **Claude:** admin-driven PKCE code-paste flow (`AccountConnectService::start()` → admin pastes the code → `resolve()`). It either updates an existing account's token or produces a `ConnectDraft` that the admin confirms before the row is created. `ClaudeReconnectModal` handles re-auth. There is **no revocation endpoint** at Anthropic: disconnect wipes stored tokens only (see `tests/fixtures/anthropic/README.md`).
- **Codex:** device-code flow (`CodexConnectService`; the admin enters the `user_code` at auth.openai.com while the Filament modal polls), or the CLI `token-slayer admin codex-connect` → `POST /api/admin/codex/connect` (`hook.token:admin` = hook token plus a live role check).

## Provisioning (admin sets up an account for a user's device)

Provisioning is part of the Members tab's **Add member** action. A *provision* toggle (default on for Claude, absent for Codex) runs the code-paste flow on the user's behalf. It stores the grant against one of the user's `devices` and writes the pivot as `pending`. With the toggle off, it just adds a `tracked` membership. Codex device grants come from the CLI (`token-slayer admin codex-provision` → `POST /api/admin/codex/provision`), and the modal shows that command.

**The grant's raw secret lives on `account_provisioned_grants` itself** (`pending_claude_access_token`/`pending_claude_refresh_token`/`pending_claude_expires_at`, or `pending_codex_auth_json`, all `encrypted` casts), **not in a cache with a TTL**. The 2026-09-08 fix moved it there after TTL expiry lost real, unclaimed production grants. The secret lives until it is confirmed (`clearPendingSecret()` in `confirmSetup()`) or revoked, never on a clock.

The user's machine completes the handoff:
1. `token-slayer setup` → `GET /api/provisioned` (`hook.token`). `AccountProvisioningService::claim($user, $fingerprint)` uses `DeviceClaimResolver` to pick the device (a null fingerprint may only speak for `'default'`) and returns each grant's secret. It is shared across both providers, since one device can hold both.
2. Once configured → `POST /api/provisioned/confirm` (`ConfirmProvisionedSetupRequest`) with `set_up` and `removed` org uuids (plus observed refresh-token deadlines). `confirmSetup()` then:
   - resolves each Account by `organization_uuid` and **never creates one** from client input;
   - promotes `pending → tracked` **only if the user holds a live grant** for it (`revoked_at` null). This closes a self-graft where a client could claim membership of any account;
   - for `removed`, revokes and stamps `deprovisioned_at` on this device's newest grant, or writes a tombstone. It does this only when the user already has an `account_user` row, so a token holder can't plant tombstones on foreign accounts;
   - is additive-only and idempotent. A failure on one org is reported and swallowed, never a 500.

## Quota tracking

The server holds an **independent OAuth grant per account**, so it never collides with developers' own tokens. Constants live in `config/token_slayer.php` (`anthropic.*`, `probe.*`, `session_anchor.*`).

- **Refresh:** `AccountTokenRefresher` refreshes when a token is within `probe.refresh_headroom_hours` (4 h) of expiry. A dead refresh token (`invalid_grant`/`unauthorized`) → `status = needs_reauth` + `AccountTokenRejected` → `SendReauthAlert` → Slack (`AccountTokenRejectedNotification`). The *Expiring* page (`ExpiringAccountsQuery`) lists accounts needing action.
- **WAF gotcha:** platform.claude.com returns a bare 429 for default Guzzle/curl/browser User-Agents, so `AnthropicOAuthClient` always sends `anthropic.user_agent` (claude-cli style).
- **Probe:** `UsageProber` (Claude, `/api/oauth/usage`) / `CodexUsageProber` (`/backend-api/wham/usage`) → append-only `account_usage_snapshots`. `utilization` is already a percent; don't multiply by 100. Per-model limits are read from `limits[]` (`ModelQuotaLimits`), not from top-level keys. Codex reports different windows (`CodexUsageWindows`: e.g. a single 30-day cap on free tier).
- **Plan:** a daily profile sync (`AccountProfileSyncer`) stores the raw `organization_type` + `rate_limit_tier` and derives `plan` (`AccountPlan`: free/pro/max_5x/max_20x/max/unknown) through `PlanResolver`. The 5x tier string also appears on Team seats, so the pair matters. `PlanBadgeResolver` renders either provider's plan badge.
- **Session anchoring:** `SessionAnchorer` sends a real 1-token message (`session_anchor.model`, Haiku) at fixed times, so each Claude account's rolling 5 h window starts on schedule. A 0-token beacon does not start a window.
- **Projection:** `QuotaProjection` linearly extrapolates the burn rate to reset time for the gauges (`QuotaGaugesQuery`). `FleetUsageRefresher` re-probes on demand (the widget's Refresh button) and busts `DamageTotals`.

## Artisan commands & schedule (`routes/console.php`)

| Command | Schedule | Does |
|---|---|---|
| `accounts:probe` | every 5 min, `withoutOverlapping` | Probe every `probeable` (Claude) + `codexProbeable` account through `ProviderServiceFactory` |
| `accounts:anchor-sessions` | 03:45 & 08:45 Asia/Ho_Chi_Minh, `withoutOverlapping` | `SessionAnchorer` for every Claude probeable account; no retry, because a late anchor starts the window off-schedule |
| `accounts:sync-profiles` | daily, `withoutOverlapping` | `AccountProfileSyncer` for Claude accounts not `disabled`/`needs_reauth` (a stale-token 401 would erase the re-auth signal) |
| `accounts:prune-usage-snapshots` | **not scheduled** (removed in `2f9d9f3`, "deferred for later review") | Deletes snapshots > 30 days (hard-coded; `snapshots.retention_days` config is currently unread) |
| `accounts:reencrypt-oauth-tokens` | manual | Re-encrypt stored tokens after an `APP_KEY` rotation |
| `account-membership:backfill` | manual | `HistoricalMembershipBackfiller`: untracked rows for pre-recording contributors |
| `event-attribution:backfill {--org=}` | manual | `EventAttributionBackfiller`: in-place `account_id` fill for org-beacon events |
| `grant-session-expiry:backfill` | manual | `GrantSessionExpiryBackfiller` |

Other schedules (not accounts): `fighters:sweep-idle` every minute, `client-artifacts:refresh` every 5 min, `battlefield:recap {daily|weekly|monthly|yearly}` at 09:00 Asia/Ho_Chi_Minh.

**Recipe: add a scheduled account job.** Use a thin command with `#[Signature('<domain-noun>:<verb>')]` + `#[Description]` in `app/Console/Commands/` that iterates and delegates each item to a Service. Catch per item with `report()` so one account can't stop the batch (see `ProbeAccountUsage`). Add a `Schedule::command(...)` entry with `withoutOverlapping()` (it calls external APIs) and a test in `tests/Feature/Console/`. Fake HTTP with `fakeAnthropic()` / `Http::fake`.

## Rebalance & reconciliation (`Services/Accounts/`, *Rebalance* page)

The Rebalance admin page recommends which member should move to keep per-account load and headcount healthy. A run over a chosen `RebalanceWindow` (week/month/all-time; every figure in one run shares the window) is a stateless draft: recalculating can produce a different one.

Pipeline: `FleetSnapshot` (one coherent `FleetReading` of who is where, capacity, demand) → `AccountRebalanceRecommender` → `RebalancePlanner` (improves the current arrangement one worthwhile move at a time) → `RebalanceRecommendation[]`. `FleetCapacityForecast` answers "do we need another account, and who would move onto it". `ObservedFleetLoad` checks projections against the raw ledger.

- **Capacity:** `AccountCapacityEstimator` measures what a weekly quota is worth from **closed** windows (`ClosedQuotaWindows`), not trailing-tokens ÷ current util. `SuppressedDemandEstimator` adds the demand an account would have served had it not capped out.
- **Home account:** `HomeAccountResolver` decides where a person actually works. Trailing 7-day usage decides first; the whole window only breaks ties. This separates a real **migration** from a brief **spill**. `StaleMembershipQuery` finds seats still held on accounts their holder has left.
- **Member target:** `rebalance.members_per_account` (default 5) is a target, not a cap. Simultaneous usage trips a 5 h window whatever the weekly total is. When there are more people than seats, the target rises to the smallest number that fits. Nobody is moved onto an account already at target, so at saturation a headcount-neutral **swap** is the only move.
- **Quota weight:** `QuotaCostSolver` solves each person's token-to-quota burn rate (1.0 = typical) from **shared** windows, because solo windows were too rare to price most people. `UserDemandEstimator` measures demand per person over a week.
- **Confidence:** each recommendation has `confident` + `confidenceReason`. It is unconfident when the account or person has less than `rebalance.min_history_days` of data.
- **Adopting:** a draft persists only when an admin adopts it as a `RebalancePlan` row (`adopted_by`, moves, the snapshot it was computed against). Moves are ticked off one by one in `applied_indexes`, so the work survives page reloads across many real browser round-trips to Anthropic.

## Invariants

- Tokens at rest are always `encrypted` casts. Never log them. After an `APP_KEY` rotation, run `accounts:reencrypt-oauth-tokens`.
- `events.account_id` is written once at ingest and never recomputed from membership (membership answers "who may use this account"; events answer "who did"). The one sanctioned exception is `event-attribution:backfill`, which only fills `null` rows by exact org-id match.
- Deleting an account nulls `events.account_id`; the raw email/org id survive for re-attribution.
- Account stats are keyed by `events.account_id`, so a user active in two accounts contributes to each correctly.
- Client input never creates an Account, and never promotes a membership without a live grant.
