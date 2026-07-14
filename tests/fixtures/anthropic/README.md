# Anthropic OAuth response fixtures

Captured LIVE 2026-07-10 from a real PKCE grant (account ongtung2212002@gmail.com,
org 7f993a12-...). Tokens redacted to sk-ant-oat01-REDACTED / sk-ant-ort01-REDACTED.
DO NOT hand-edit shapes — recapture if the API changes.

- token.json    — authorization_code grant response (POST /v1/oauth/token)
- refresh.json  — refresh_token grant response (rotates the refresh token)
- profile.json  — GET /api/oauth/profile
- usage.json    — GET /api/oauth/usage

## Verified API facts (load-bearing for the prober)
- usage `utilization` is ALREADY a percent (five_hour=0.0, seven_day=25.0) — do NOT multiply by 100.
- Real usage bucket keys: five_hour, seven_day, seven_day_opus, seven_day_sonnet,
  seven_day_oauth_apps (+ many null codename buckets). Richer `limits[]` array carries
  per-kind percent/severity/resets_at/scope; `spend` object for credit usage.
- reset timestamps: `resets_at` ISO-8601 with microseconds + explicit offset.
- profile keys: account.{uuid,email,full_name,...}, organization.{uuid,name,rate_limit_tier,...},
  application, enabled_plugins. (Note: `email`, not `emailAddress`; `rate_limit_tier`, not
  `organizationRateLimitTier` — those camelCase names are the .claude.json cache, not this API.)
- **No revocation endpoint**: POST /v1/oauth/revoke → 404 not_found. Disconnect (Task 7b)
  cannot revoke server-side; it wipes stored tokens + relies on the owner runbook
  (claude.ai → revoke app access / sign out all sessions).
- **WAF**: platform.claude.com blocks curl/browser User-Agents with a bare 429 (no Retry-After).
  A `claude-cli/...` or `axios/...` UA passes. AnthropicOAuthClient MUST set a non-default UA.
- token/refresh grants require `Content-Type: application/json` (NOT form-encoded).
