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

## Services & external integrations

- Prefer small, single-responsibility classes with a short action method over one broad service holding many methods. Put the descriptiveness in the CLASS name so the method can be a short verb — `execute()`, `handle()`, `__invoke()`, or `get()` for a query object. Reference sibling `mysbox-api`: e.g. `OrderResellerService::execute()`, not a grab-bag service with a long `processOrderReseller…()` method. When a service accretes several distinct responsibilities (or a method name grows qualified like `buildXForY`), split each into its own class named after the responsibility. This does NOT contradict "descriptive names over short ones" above: descriptive naming still governs predicate/query methods on entity-like classes; for action/query classes the descriptiveness lives in the class name and the single action method stays short. A single class exposing several tightly-related read queries (the existing `DamageTotals` shape) is acceptable, but a new aggregation surface should default to per-query classes.
- External integrations (Slack, email, third-party APIs) go through a shared, reusable abstraction — never duplicate transport (HTTP call, auth, retry) per feature. When a second consumer of an integration appears, extract the transport into a service under `app/Services/<Integration>/` (and, when it helps, an `app/Contracts/<Integration>/` interface bound in a provider); features depend on the abstraction and only build their payload. Reference sibling `mysbox-api` for the house shape.
- Slack outbound uses Laravel's Slack notification channel (`laravel/slack-notification-channel`): one `Notification` class per message type in `app/Notifications/`, payload built with the Block Kit `SlackMessage` builder in `toSlack()`, sent via `Notification::route('slack', …)->notify(new XNotification(…))`. Adding a message type = a new Notification class, never new transport code.
- No `Log::` on normal/production paths in service or integration code. Signal failure with a named domain exception (`App\Exceptions\…`), the `rescue()` helper, or a typed return value — not log-and-continue. Logging is a dev-only aid; gate any diagnostic behind `App::environment('local')`.

## Comments

- PHP: prefer PHPDoc over inline comments; inline comments only for genuinely non-obvious logic (race workarounds, protocol quirks) — state the constraint, not what the next line does.
- JavaScript: manager/class public methods get Google-style JSDoc (`@param`/`@returns`); pure/utility functions get a single-line comment at most. Do not apply the PHP DocBlock convention to JS.

## Git

- Commit messages follow the `commit` skill (Angular convention with project scopes).
- Never commit spec files, implementation plans, or design docs (`docs/superpowers/**`, ad-hoc `*.md` planning files). Agent-config under `.ai/` and `.claude/` IS committed.
