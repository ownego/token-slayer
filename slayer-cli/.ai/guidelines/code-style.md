# Code Style (slayer-cli)

## Python

- `from __future__ import annotations` at the top of every module.
- Type hints on every function/method signature and class attribute.
- Docstrings on every public function and class — Google-ish, one-liner is fine (`"""Fetch quota from Anthropic for the given org."""`). Private helpers get a minimal one-liner too.
- Python 3.10+ idioms: `match`/`case`, `X | None` unions, `@dataclass` where a pydantic model isn't warranted.

## Pydantic v2

- Models inherit `BaseModel`.
- Use the v2 API only: `model_validate()`, `model_validate_json()`, `model_dump()`, `model_dump_json()`. Never the v1 `.dict()`/`.json()`/`.parse_obj()` spellings.
- Validation is declarative (`Field(pattern=...)`, `field_validator`), not ad-hoc `if` checks in callers.

## Constants

- All constants (namespace, filenames, TTLs) come from `slayer_cli.constants` — no magic strings/numbers scattered across modules.
- OS-specific paths are computed in `platform/paths.py`, never hardcoded (`~/.config/...`) at call sites.

## Errors

- Every raised error is a `SlayerError` subclass (`errors.py`), named after the failure (`AccountNotFound`, `CredentialError`, `UsageFetchError`) — never a bare `Exception` or `ValueError`.
- CLI/TUI layers catch `SlayerError` and print a user-friendly message; no raw stack traces surfaced to the user.

## Tokens

**Hard invariant: tokens are NEVER logged, printed, put in error messages, or shown in the TUI — even at `DEBUG` level.** The TUI surfaces email/org/utilization only. Test fixtures use the literal token `sk-ant-oat01-TESTTOKEN`, never a real-looking generated one.

## Commits

Angular convention, scope `(slayer)`: `feat(slayer): ...`, `fix(slayer): ...`, `test(slayer): ...`.
