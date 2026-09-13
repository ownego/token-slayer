<?php

namespace App\Services\Accounts;

/**
 * One recommended move: a person should switch from one account to another.
 *
 * Everything the decision rested on travels with it. A bare "move X to Y" is
 * not actionable — an admin has to be able to see the appetite it was sized
 * against, how that appetite was measured, how much history is behind it, and
 * what it does to both accounts, or the recommendation is just an assertion.
 */
final readonly class RebalanceRecommendation
{
    /**
     * @param  int  $userId  the person this move applies to
     * @param  int  $fromAccountId  the account they should move off of
     * @param  int  $toAccountId  the account they should move onto
     * @param  int|null  $swapWithUserId  the person crossing the other way between the same two accounts, when this move is half of a swap
     * @param  float  $demandWeeklyTokens  the weekly appetite this move was sized against, after quota weighting
     * @param  float  $demandPerDayTokens  the daily rate behind that weekly figure
     * @param  string  $demandBasis  which measure produced the figure: `'peak_week'` or `'trailing_average'`
     * @param  float  $trailingAvgPerDayTokens  their plain average across the whole window, shown for contrast
     * @param  float  $peakWeekTokens  the most they got through in any seven consecutive days, shown for contrast
     * @param  float  $burstFactor  busiest hour over mean hour: how concentrated their day is
     * @param  float  $quotaWeight  how fast their tokens burn quota relative to a typical member; 1.0 is typical
     * @param  int  $daysOfHistory  days of their recorded usage inside the window
     * @param  float  $fromFillBeforePercent  the source account's planned load as a percentage of its capacity, before
     * @param  float  $fromFillAfterPercent  the same, after every recommended move lands
     * @param  float  $toFillBeforePercent  the destination account's planned load as a percentage of its capacity, before
     * @param  float  $toFillAfterPercent  the same, after every recommended move lands
     * @param  bool  $confident  false when either account or the person has less than the configured minimum history
     */
    public function __construct(
        public int $userId,
        public int $fromAccountId,
        public int $toAccountId,
        public ?int $swapWithUserId,
        public float $demandWeeklyTokens,
        public float $demandPerDayTokens,
        public string $demandBasis,
        public float $trailingAvgPerDayTokens,
        public float $peakWeekTokens,
        public float $burstFactor,
        public float $quotaWeight,
        public int $daysOfHistory,
        public float $fromFillBeforePercent,
        public float $fromFillAfterPercent,
        public float $toFillBeforePercent,
        public float $toFillAfterPercent,
        public bool $confident,
    ) {}
}
