# Domain: Attribution Contract

## Overview

This is why slayer-cli ships inside the token-slayer repo rather than as a standalone tool: every switch writes a small JSON file that the token-slayer hook reads to attribute usage events to the correct account. Without it, the hook has no reliable way to tell which of several accounts on a machine produced a given event.

## The `active.json` Contract

```
switch_to(name)
  → resolve org_uuid (from the slot, or re-resolved via beacon if stale)
      → write ~/.config/<ns>/account-provider/active.json
            {"org_uuid": ..., "email": ..., "uuid": <optional>, "source": "switcher"}
                → token-slayer hook reads active.json on each event
                    → events.account_id attributed by matching org_uuid
```

**`org_uuid` is mandatory.** A blank value makes the hook reject the file outright — no event gets attributed to that write, not even as "unknown."

## `org_uuid` Resolution — the Beacon

`org_uuid` is resolved by a zero-cost beacon: a POST to the Anthropic messages endpoint whose *response header* carries the organization id. slayer-cli never calls a dedicated "who am I" endpoint — it piggybacks on a call it would make anyway (or a minimal one) and reads the header.

## Key Models

| Model | Role |
|-------|------|
| `ActiveJson` | Shape of `active.json`. A field validator rejects a blank `org_uuid` at construction time, not at write time — a bad value can never be serialized. |

## Key Files

| File | Purpose |
|------|---------|
| `provider/writer.py` | Serializes `ActiveJson` to `account-provider/active.json` |
| `models/provider.py` | `ActiveJson` pydantic model + validator |
| `auth/beacon.py` | Beacon POST + response-header parsing to resolve `org_uuid` |

## Key Invariants

- **`org_uuid` is never blank in a written `active.json`** — enforced by `ActiveJson`'s validator, not by a caller-side `if`.
- **Attribution itself happens server-side, per-event** — slayer-cli's only responsibility is keeping `active.json` truthful; it never computes `events.account_id` itself.
- **The beacon call must stay zero-cost** — it must not consume quota beyond what a normal request would.
