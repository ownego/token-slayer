<?php

namespace App\Services\CustomCharacter;

use App\Enums\CustomMinionOutcome;
use App\Models\CustomMinion;

/**
 * The result of confirming a minion draft: the minion now in the set, and what
 * confirming did to the set (so the page can say "Added" only when something was
 * added).
 */
final class ConfirmedMinion
{
    /**
     * Create the result.
     *
     * @param  CustomMinion  $minion  the minion that holds the draft's look
     * @param  CustomMinionOutcome  $outcome  what confirming did to the set
     * @return void
     */
    public function __construct(
        public readonly CustomMinion $minion,
        public readonly CustomMinionOutcome $outcome,
    ) {}
}
