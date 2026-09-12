<?php

namespace App\Services\Accounts;

/**
 * One recommended move: a user should switch from one account to another.
 * Carries the projected-utilization numbers before and after the move on
 * both sides so the admin page can show its reasoning, not just the move
 * itself.
 */
final readonly class RebalanceRecommendation
{
    /**
     * @param  int  $userId  the user this move applies to
     * @param  int  $fromAccountId  the account they should move off of
     * @param  int  $toAccountId  the account they should move onto
     * @param  int  $fromProjectedBefore  the source account's projected util_7d at reset, before the move
     * @param  int  $fromProjectedAfter  the source account's projected util_7d at reset, after the move
     * @param  int  $toProjectedBefore  the target account's projected util_7d at reset, before the move
     * @param  int  $toProjectedAfter  the target account's projected util_7d at reset, after the move
     * @param  float  $demandTokensPerDay  the user's estimated daily token demand this move is sized against
     * @param  string  $demandBasis  which basis produced the demand figure: `'trailing_average'` or `'peak_rate'`
     * @param  bool  $confident  false when either account has fewer than the configured minimum days of history
     */
    public function __construct(
        public int $userId,
        public int $fromAccountId,
        public int $toAccountId,
        public int $fromProjectedBefore,
        public int $fromProjectedAfter,
        public int $toProjectedBefore,
        public int $toProjectedAfter,
        public float $demandTokensPerDay,
        public string $demandBasis,
        public bool $confident,
    ) {}
}
