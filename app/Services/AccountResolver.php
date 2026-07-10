<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Cache;

final class AccountResolver
{
    /**
     * Cache key of the lowercase-email → account-id map. Invalidated by Account model events.
     *
     * @var string
     */
    public const string CACHE_KEY = 'accounts:email-map';

    /**
     * How long the email map is cached before a natural refresh.
     *
     * @var int
     */
    private const int CACHE_TTL_SECONDS = 3600;

    /**
     * Match a hook-claimed account email against the org accounts table.
     *
     * @param  ?string  $email  raw email claimed by the client, any case
     * @return ?int the matching account id, or null when unknown/absent
     */
    public function resolve(?string $email): ?int
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
}
