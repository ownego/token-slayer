# Domain: Usage & Quota

## Overview

Per-account 5h and 7d utilization is not fetched from a dedicated usage endpoint — it rides along on the RESPONSE headers of a tiny probe call to Anthropic. This keeps quota checks cheap enough to run on demand from the TUI.

## Fetch → Cache → Display

```
fetch_usage(account)
  → tiny probe call to Anthropic
      → parse response headers:
            anthropic-ratelimit-unified-5h-utilization
            anthropic-ratelimit-unified-5h-reset
            anthropic-ratelimit-unified-5h-status
            anthropic-ratelimit-unified-7d-utilization
            anthropic-ratelimit-unified-7d-reset
          → UsageSnapshot
              → cached under usage-cache/<name>, 5-minute TTL
                  → TUI renders as utilization bars
```

A cache hit within the TTL skips the probe call entirely — repeated TUI refreshes don't burn quota.

## Key Models

| Model | Role |
|-------|------|
| `UsageSnapshot` | Parsed 5h/7d utilization + reset times + status, per account, with a cached-at timestamp. |

## Key Files

| File | Purpose |
|------|---------|
| `usage/fetcher.py` | Makes the probe call |
| `usage/parser.py` | Header → `UsageSnapshot` |
| `usage/service.py` | Cache-aware orchestration (TTL check, fetch-or-serve-cached) |
| `platform/cache.py` | Generic on-disk cache read/write used by `usage/service.py` |
| `tui/format.py` | Pure bar-width/color/limit-flag/reset-formatting helpers the TUI renders utilization with |
| `tui/app.py` | Fetches each account's `UsageSnapshot` via `UsageService.get` (concurrent worker threads) and redraws the bars |

## Key Invariants

- **The TUI surfaces email, org, and utilization only — never the token**, even when rendering a usage row next to the account name.
- **Cache TTL is 5 minutes**, sourced from `constants.USAGE_TTL_SECONDS` — not a literal `300` scattered across `usage/`.
- **A stale/expired cache entry always falls back to a fresh probe call**, never a stale silent success.
