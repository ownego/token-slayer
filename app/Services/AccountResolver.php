<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ClaudeCredential;
use App\Models\CodexCredential;
use App\Support\CacheKeys;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class AccountResolver
{
    /**
     * Cache key of the lowercase-email → account-id map (Claude-provider
     * accounts only).
     *
     * @var string
     */
    public const string CACHE_KEY = CacheKeys::ACCOUNTS_EMAIL_MAP;

    /**
     * Cache key of the organization-uuid → account-id map.
     *
     * @var string
     */
    public const string ORG_CACHE_KEY = CacheKeys::ACCOUNTS_ORG_MAP;

    /**
     * How long each resolver map is cached before a natural refresh.
     *
     * @var int
     */
    private const int CACHE_TTL_SECONDS = 3600;

    /**
     * Match a hook-claimed account against the org accounts table for the
     * event's own provider — a Codex event resolves ONLY against Codex
     * accounts (by `chatgpt_account_id`, falling back to a Codex-only email
     * map), a Claude-family event (`claude-code`/`claude-ai`/`cowork`, or
     * any other non-`codex` value) resolves ONLY against Claude accounts
     * (unchanged from before). Each path writes only to its own provider's
     * credential table — this is the fix for a real vulnerability where a
     * Codex event's `account_org_id` (a `chatgpt_account_id`) could
     * previously get written straight into a Claude account's
     * `organization_uuid` via an email-matched claim.
     *
     * @param  ?string  $accountId  Anthropic organization uuid (Claude) or chatgpt_account_id (Codex), exact match
     * @param  ?string  $email  raw email claimed by the client, any case
     * @param  string  $provider  the event's declared provider (`events.provider` / the request's `provider` query param)
     * @return ?int the matching account id, or null when unknown/absent
     */
    public function resolve(?string $accountId, ?string $email, string $provider): ?int
    {
        return $provider === 'codex'
            ? $this->resolveCodex($accountId, $email)
            : $this->resolveClaude($accountId, $email);
    }

    /**
     * @param  ?string  $orgId  Anthropic organization uuid, exact match
     * @param  ?string  $email  raw email claimed by the client, any case
     * @return ?int the matching account id, or null when unknown/absent
     */
    private function resolveClaude(?string $orgId, ?string $email): ?int
    {
        $byOrg = $this->resolveByOrgId($orgId);
        if ($byOrg !== null) {
            return $byOrg;
        }

        $byEmail = $this->resolveByClaudeEmail($email);
        if ($byEmail !== null && $orgId !== null && trim($orgId) !== '') {
            $this->learnOrganizationUuid($byEmail, trim($orgId));
        }

        return $byEmail;
    }

    /**
     * @param  ?string  $chatgptAccountId  Codex chatgpt_account_id, exact match
     * @param  ?string  $email  raw email claimed by the client, any case
     * @return ?int the matching account id, or null when unknown/absent
     */
    private function resolveCodex(?string $chatgptAccountId, ?string $email): ?int
    {
        $byId = $this->resolveByChatgptAccountId($chatgptAccountId);
        if ($byId !== null) {
            return $byId;
        }

        $byEmail = $this->resolveByCodexEmail($email);
        if ($byEmail !== null && $chatgptAccountId !== null && trim($chatgptAccountId) !== '') {
            $this->learnChatgptAccountId($byEmail, trim($chatgptAccountId));
        }

        return $byEmail;
    }

    /**
     * @param  ?string  $orgId  Anthropic organization uuid, exact match
     * @return ?int the matching account id, or null when unknown/absent
     */
    private function resolveByOrgId(?string $orgId): ?int
    {
        if ($orgId === null || trim($orgId) === '') {
            return null;
        }

        $map = Cache::remember(self::ORG_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return ClaudeCredential::query()
                ->whereNotNull('organization_uuid')
                ->pluck('account_id', 'organization_uuid')
                ->all();
        });

        return $map[trim($orgId)] ?? null;
    }

    /**
     * @param  ?string  $chatgptAccountId  Codex chatgpt_account_id, exact match
     * @return ?int the matching account id, or null when unknown/absent
     */
    private function resolveByChatgptAccountId(?string $chatgptAccountId): ?int
    {
        if ($chatgptAccountId === null || trim($chatgptAccountId) === '') {
            return null;
        }

        $map = Cache::remember(CacheKeys::ACCOUNTS_CODEX_ORG_MAP, self::CACHE_TTL_SECONDS, function (): array {
            return CodexCredential::query()
                ->whereNotNull('chatgpt_account_id')
                ->pluck('account_id', 'chatgpt_account_id')
                ->all();
        });

        return $map[trim($chatgptAccountId)] ?? null;
    }

    /**
     * @param  ?string  $email  raw email claimed by the client, any case
     * @return ?int the matching account id, or null when unknown/absent
     */
    private function resolveByClaudeEmail(?string $email): ?int
    {
        return $this->resolveByProviderEmail($email, self::CACHE_KEY, 'claude');
    }

    /**
     * @param  ?string  $email  raw email claimed by the client, any case
     * @return ?int the matching account id, or null when unknown/absent
     */
    private function resolveByCodexEmail(?string $email): ?int
    {
        return $this->resolveByProviderEmail($email, CacheKeys::ACCOUNTS_CODEX_EMAIL_MAP, 'codex');
    }

    /**
     * Match a lowercase email against a provider-scoped email map — kept
     * separate per provider so a Codex event's email can never resolve
     * against a Claude account by coincidence, or vice versa.
     *
     * @param  ?string  $email  raw email claimed by the client, any case
     * @param  string  $cacheKey  the provider-scoped cache key to use
     * @param  string  $provider  the `accounts.provider` value to scope the map to
     * @return ?int the matching account id, or null when unknown/absent
     */
    private function resolveByProviderEmail(?string $email, string $cacheKey, string $provider): ?int
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $map = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($provider): array {
            return Account::query()
                ->where('provider', $provider)
                ->pluck('id', 'email')
                ->mapWithKeys(fn (int $id, string $email): array => [mb_strtolower($email) => $id])
                ->all();
        });

        return $map[mb_strtolower(trim($email))] ?? null;
    }

    /**
     * Teach the resolver an account's organization uuid after an email-matched
     * claim carried one. Leaves an already-set differing uuid untouched and
     * logs the conflict instead of overwriting a beacon-verified value.
     *
     * @param  int  $accountId  the account matched by email
     * @param  string  $orgId  the organization uuid claimed alongside the email
     * @return void
     */
    private function learnOrganizationUuid(int $accountId, string $orgId): void
    {
        $account = Account::query()->where('provider', 'claude')->find($accountId);
        if ($account === null || $account->organization_uuid === $orgId) {
            return;
        }

        if ($account->organization_uuid !== null) {
            Log::warning('Refusing to overwrite account organization uuid: mismatch between stored and claimed value.', [
                'account_id' => $accountId,
                'existing_organization_uuid' => $account->organization_uuid,
                'claimed_organization_uuid' => $orgId,
            ]);

            return;
        }

        $account->organization_uuid = $orgId;

        try {
            // Two concurrent email-matched claims can both see organization_uuid
            // as null and race to learn it; the unique constraint on
            // organization_uuid makes the losing save throw, which we treat as
            // already-learned rather than a failure.
            $account->save();
        } catch (QueryException) {
            return;
        }
    }

    /**
     * Teach the resolver a Codex account's chatgpt_account_id after an
     * email-matched claim carried one. Mirrors {@see learnOrganizationUuid()}
     * exactly, scoped to `provider = 'codex'` and `codex_credentials`.
     *
     * @param  int  $accountId  the account matched by email
     * @param  string  $chatgptAccountId  the chatgpt_account_id claimed alongside the email
     * @return void
     */
    private function learnChatgptAccountId(int $accountId, string $chatgptAccountId): void
    {
        $account = Account::query()->where('provider', 'codex')->find($accountId);
        if ($account === null) {
            return;
        }

        $credential = $account->codexCredential;
        if ($credential !== null && $credential->chatgpt_account_id === $chatgptAccountId) {
            return;
        }

        if ($credential !== null && $credential->chatgpt_account_id !== null) {
            Log::warning('Refusing to overwrite account chatgpt_account_id: mismatch between stored and claimed value.', [
                'account_id' => $accountId,
                'existing_chatgpt_account_id' => $credential->chatgpt_account_id,
                'claimed_chatgpt_account_id' => $chatgptAccountId,
            ]);

            return;
        }

        $credential = $credential ?? new CodexCredential(['account_id' => $account->id]);
        $credential->chatgpt_account_id = $chatgptAccountId;

        try {
            // Mirrors learnOrganizationUuid()'s race handling — the unique
            // constraint on chatgpt_account_id makes a losing concurrent
            // save throw, treated as already-learned rather than a failure.
            $credential->save();
        } catch (QueryException) {
            return;
        }
    }
}
