# Code Style (project-specific)

These rules extend the Boost/Laravel defaults above. When they conflict, these win.

## PHP

- Full PHPDoc blocks on every method and property:
  - Properties: `@var type`
  - Methods: one-line description + `@param type $name` per parameter + `@return type`
  - Use `@inheritDoc` when implementing/overriding an interface method
- Do NOT rely on Pint to preserve PHPDoc: the stock `laravel` preset strips `@param`/`@return` tags it considers superfluous. A local `pint.json` with `"no_superfluous_phpdoc_tags": false` is the guard (kept per-machine, not committed).
- Constructor property promotion is used (Laravel 13 style) — unlike some sibling projects, it is allowed here.
- Exceptions: throw named domain exceptions (`App\Exceptions\...`), never a bare `\Exception`. Name them after the failure, not the layer (`UsageProbeException`, not `ServiceException`).
- `env()` is only ever called inside `config/*.php`. App code reads `config('token_slayer.…')`. Cast numerics in the config file, not at call sites.
- Enum keys in TitleCase; string-backed enums for anything that persists or broadcasts.
- Descriptive names over short ones: `isRegisteredForDiscounts()`, not `discount()`.

## Comments

- PHP: prefer PHPDoc over inline comments; inline comments only for genuinely non-obvious logic (race workarounds, protocol quirks) — state the constraint, not what the next line does.
- JavaScript: manager/class public methods get Google-style JSDoc (`@param`/`@returns`); pure/utility functions get a single-line comment at most. Do not apply the PHP DocBlock convention to JS.

## Git

- Commit messages follow the `commit` skill (Angular convention with project scopes).
- Never commit spec files, implementation plans, or design docs (`docs/superpowers/**`, ad-hoc `*.md` planning files). Agent-config under `.ai/` and `.claude/` IS committed.
