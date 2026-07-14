# Testing (slayer-cli)

## TDD is mandatory

Every behavior change starts with a failing test — write it, watch it fail for the right reason, then implement.

## Test tiers

1. **pytest** for pure logic — the dominant tier. Models, services, path resolution, parsing.
2. **Textual `run_test` / pilot** for interaction — driving widgets and app-level key/mouse events.
3. **`pytest-textual-snapshot`** for TUI visuals — rendered widget/app output.

## HTTP faking

All HTTP is faked via httpx's `MockTransport` or `respx` — never a real network call, and never a real token in a fixture. Anthropic and token-slayer endpoints are both mocked in tests.

## Running

```
cd slayer-cli && python -m pytest -q                 # all tests
cd slayer-cli && python -m pytest -q path/to/test.py  # single file
cd slayer-cli && python -m pytest -q -k "test_name"    # by pattern
```
