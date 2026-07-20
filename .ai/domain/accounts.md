# Domain: Org Accounts, Attribution & Quota

> Status: design approved 2026-07-10; implementation phased (attribution → quota → analytics). Update this file as phases land — sections below describe the target state.

An **Account** = one Claude (Anthropic) Max subscription owned by the org, identified by its login email. Developers (users) are members of zero or more accounts (`account_user` pivot). One user regularly switches between accounts (personal + org), so account attribution is **per-event, never per-user**.

## Membership states (`account_user.status`)

The pivot's `status` (string-backed enum `MembershipStatus`) records how far a user's attribution setup has progressed for that account. It never drives attribution itself — events do — only the admin UI and the provisioning handoff:

- `untracked` — a known contributor who has not confirmed token-slayer setup for this account. Shown as **"Unverified"** (warning) in the Members table; can be verified (promoted) in place.
- `tracked` — setup confirmed, attribution live. Shown as **"Verified"** (success).
- `pending` — an admin provisioned an OAuth grant for the user (see *Provisioning* below) and is waiting for the user's machine to confirm setup. Shown as **"Pending setup"** (warning).

## Attribution (which account served this usage?)

Verified constraints (2026-07-10, do not re-litigate):
- Hook payloads and transcripts carry NO account identity.
- `~/.claude.json → .oauthAccount` (email/uuid/org/tier) exists on all OSes but goes **stale** when credentials are swapped externally (ccm-style switchers).
- Setup-tokens (`sk-ant-oat01…`) are rejected by `/api/oauth/usage` and `/api/oauth/profile` (missing `user:profile` scope).

Resolution chain (client-side, in the hook helper):
1. `~/.config/{namespace}/account.json` — `{"email","uuid","source","updated_at"}`. The forward contract: written manually today, later by token-slayer's own account-switching feature or by ccm/claudehub.
2. Fallback: `~/.claude.json → .oauthAccount` (`source=auto`).

Events POST `account_email`, `account_uuid`, `account_source`, `client_version`. Server-side, `AccountResolver` matches the claimed email against a cached org-account email map → `events.account_id` (null = personal/unknown; raw claim kept in `events.account_email` for later reconciliation/backfill).

## Provisioning (admin sets up an account for a user)

Provisioning is folded into the Members tab's **Add member** action: a *provision* toggle (default on) runs the admin OAuth code-paste flow on the user's behalf, stores the encrypted grant, and writes the pivot as `pending`. Turning it off just adds a `tracked` membership. (The former standalone "Provision for user" button is retired.)

The user's machine finishes the handoff: `token-slayer setup` pulls each provisioned grant and, once configured, calls `POST /api/provisioned/confirm` (`hook.token` bearer) with the `organization_uuid`s it set up. `AccountProvisioningService::confirmSetup` then, per org:

- resolves the Account by `organization_uuid` — **never creates one** from client input (unknown orgs are skipped);
- promotes the membership to `tracked` **only if the user actually holds a live provisioned grant** for it (`provisionedUsers()`, `revoked_at` null) — this closes a self-graft where a client could claim membership of any account;
- is **additive-only** and idempotent: accounts the user set up that aren't ours, or weren't just provisioned, are ignored; already-`tracked` rows stay put. No cache invalidation — the email map self-expires (~1 day).

## Quota tracking

Server holds an **independent PKCE OAuth grant per account** (admin-driven code-paste connect flow; no collision with developers' own tokens). Constants in `config/token_slayer.php` (`anthropic.*`). A 5-minute `accounts:probe` command refreshes tokens (4 h headroom) and hits the free usage API → `account_usage_snapshots` (util 5h/7d as percent 0–100 + resets + raw JSON, pruned after 30 days). Refresh-token death → `accounts.status = needs_reauth`. A daily profile sync auto-updates `accounts.plan` from `organizationRateLimitTier`.

## Invariants

- Tokens at rest are always `encrypted` casts. Never log them.
- `events.account_id` is written once at ingest and never recomputed from membership — membership answers "who may use this account", events answer "who did".
- Deleting an account nulls `events.account_id` (raw email survives for re-attribution).
- Account stats keyed by `events.account_id`; a user active in two accounts must contribute to each correctly (the regression the old `users.account_id` join could not express).
