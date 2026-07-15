<?php

namespace App\Services;

use App\Models\Account;
use App\Support\CacheKeys;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class AccountResolver
{
    /**
     * Cache key of the lowercase-email → account-id map. Invalidated by Account model events.
     *
     * @var string
     */
    public const string CACHE_KEY = CacheKeys::ACCOUNTS_EMAIL_MAP;

    /**
     * Cache key of the organization-uuid → account-id map.
     *
     * Invalidated alongside the email map by Account model saved/deleted events.
     *
     * @var string
     */
    public const string ORG_CACHE_KEY = CacheKeys::ACCOUNTS_ORG_MAP;

    /**
     * How long the email map is cached before a natural refresh.
     *
     * @var int
     */
    private const int CACHE_TTL_SECONDS = 3600;

    /**
     * Match a hook-claimed account against the org accounts table, preferring
     * the beacon-verified organization uuid and falling back to the
     * self-reported email when the uuid is absent or unknown.
     *
     * @param  ?string  $orgId  Anthropic organization uuid, exact match
     * @param  ?string  $email  raw email claimed by the client, any case
     * @return ?int the matching account id, or null when unknown/absent
     */
    public function resolve(?string $orgId, ?string $email): ?int
    {
        $byOrg = $this->resolveByOrgId($orgId);
        if ($byOrg !== null) {
            return $byOrg;
        }

        $byEmail = $this->resolveByEmail($email);
        if ($byEmail !== null && $orgId !== null && trim($orgId) !== '') {
            $this->learnOrganizationUuid($byEmail, trim($orgId));
        }

        return $byEmail;
    }

    /**
     * Match an organization uuid against the org-uuid map. The map is cached
     * for CACHE_TTL_SECONDS and invalidated by Account::booted() on save/delete.
     *
     * @param  ?string  $orgId  Anthropic organization uuid, exact match
     * @return ?int the matching account id, or null when unknown/absent
     */
    private function resolveByOrgId(?string $orgId): ?int
    {
        if ($orgId === null || trim($orgId) === '') {
            return null;
        }

        $map = Cache::remember(self::ORG_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return Account::query()
                ->whereNotNull('organization_uuid')
                ->pluck('id', 'organization_uuid')
                ->all();
        });

        return $map[trim($orgId)] ?? null;
    }

    /**
     * Match a lowercase email against the email map. The map is cached for
     * CACHE_TTL_SECONDS and invalidated by Account::booted() on save/delete.
     *
     * @param  ?string  $email  raw email claimed by the client, any case
     * @return ?int the matching account id, or null when unknown/absent
     */
    private function resolveByEmail(?string $email): ?int
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $map = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return Account::query()
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
        $account = Account::query()->find($accountId);
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
}
