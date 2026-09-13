<?php

namespace App\Services\Accounts;

/**
 * Improves the arrangement people are already in, one worthwhile change at a
 * time.
 *
 * Two earlier shapes were wrong in opposite ways. Walking the overflowing
 * accounts and pushing their heaviest member at whoever had the most room can
 * only ever produce one-way traffic, and with a single roomy account it sent
 * every recommendation to the same place. Repacking the whole fleet from
 * scratch fixed that but went too far the other way: it ignored where people
 * already were and handed back thirty moves for thirty people, which no admin
 * is going to carry out.
 *
 * So this starts from the current arrangement and repeatedly applies the
 * single best change — moving one person, or exchanging two — stopping as
 * soon as nothing left would meaningfully relieve the fullest account. Swaps
 * are first-class rather than emergent, because a swap is often the only
 * thing that helps: with 60+30 on one account and 50+10 on another, no
 * one-way move improves anything, and exchanging the 30 for the 10 takes the
 * worst account from 90 down to 80.
 *
 * Pure: no database, no clock. Everything it needs is passed in.
 */
final class RebalancePlanner
{
    /**
     * Ceiling on the extra room reserved for a spiky person, as a fraction
     * of their demand. Burstiness says their peak hour runs far above their
     * average, so a plan that fits only their average will still trip a
     * short window — but the pad is capped, because burstiness is a rough
     * signal and should never dominate the arithmetic.
     *
     * @var float
     */
    private const float MAX_BURST_PAD = 0.25;

    /**
     * How much a change must take off the fullest account to be worth
     * proposing, as a fraction of that account's usable capacity. Below one
     * percentage point the fleet is level enough, and every further move
     * costs a real person a re-authentication for no practical gain.
     *
     * @var float
     */
    private const float MIN_GAIN = 0.01;

    /**
     * Most moves a single plan will propose. Not an optimisation limit — an
     * attention limit. A page of ten changes is a batch someone can actually
     * execute; the next run picks up from wherever this one stopped.
     *
     * @var int
     */
    private const int MAX_MOVES = 10;

    /**
     * Build the improved arrangement and the moves that reach it.
     *
     * @param  array<int, float>  $capacities  account id => weekly capacity in tokens
     * @param  array<array-key, float>  $demands  user id => weekly demand in tokens
     * @param  array<array-key, int>  $current  user id => the account they sit on today
     * @param  array<array-key, float>  $burstFactors  user id => peak-hour-over-mean-hour ratio
     * @param  float  $safetyMargin  fraction of every account's capacity to leave unplanned
     * @return array{assignment: array<array-key, int>, moves: array<int, array{user: array-key, from: int, to: int, swap_with: array-key|null}>, fill_before: array<int, float>, fill_after: array<int, float>, effective_demands: array<array-key, float>, overflow: array<int, float>}
     */
    public function plan(
        array $capacities,
        array $demands,
        array $current,
        array $burstFactors,
        float $safetyMargin,
    ): array {
        $effective = [];
        foreach ($demands as $userId => $demand) {
            $effective[$userId] = $demand * (1 + $this->burstPad($burstFactors[$userId] ?? 1.0));
        }

        $usable = array_map(fn (float $capacity): float => $capacity * (1 - $safetyMargin), $capacities);
        $assignment = $this->seedAssignment($effective, $current, $usable);
        $assignment = $this->improve($assignment, $effective, $usable);

        return [
            'assignment' => $assignment,
            'moves' => $this->movesBetween($current, $assignment),
            'fill_before' => $this->fills($capacities, $demands, $current),
            'fill_after' => $this->fills($capacities, $demands, $assignment),
            'effective_demands' => $effective,
            'overflow' => $this->overflow($this->fills($capacities, $effective, $assignment), $usable),
        ];
    }

    /**
     * The arrangement to start improving from: where everyone sits today,
     * except that anyone whose account is not among the ones being planned
     * is seeded onto the emptiest of those. They have to run somewhere, and
     * leaving them out would plan a fleet that does not include them.
     *
     * @param  array<array-key, float>  $effective  user id => padded demand
     * @param  array<array-key, int>  $current  user id => account id today
     * @param  array<int, float>  $usable  account id => plannable capacity
     * @return array<array-key, int>
     */
    private function seedAssignment(array $effective, array $current, array $usable): array
    {
        $assignment = [];
        $load = array_fill_keys(array_keys($usable), 0.0);

        foreach ($effective as $userId => $demand) {
            $accountId = $current[$userId] ?? null;
            if ($accountId === null || ! array_key_exists($accountId, $usable)) {
                $accountId = $this->emptiest($load, $usable);
                if ($accountId === null) {
                    continue;
                }
            }

            $assignment[$userId] = $accountId;
            $load[$accountId] += $demand;
        }

        return $assignment;
    }

    /**
     * Apply the best available change until none is worth making.
     *
     * @param  array<array-key, int>  $assignment  user id => account id
     * @param  array<array-key, float>  $effective  user id => padded demand
     * @param  array<int, float>  $usable  account id => plannable capacity
     * @return array<array-key, int>
     */
    private function improve(array $assignment, array $effective, array $usable): array
    {
        $load = $this->loads($assignment, $effective, $usable);
        $moves = 0;

        while ($moves < self::MAX_MOVES) {
            $best = $this->bestChange($assignment, $effective, $usable, $load);
            if ($best === null) {
                break;
            }

            foreach ($best['changes'] as $userId => $accountId) {
                $load[$assignment[$userId]] -= $effective[$userId];
                $load[$accountId] += $effective[$userId];
                $assignment[$userId] = $accountId;
                $moves++;
            }
        }

        return $assignment;
    }

    /**
     * The single change — one person moved, or two exchanged — that most
     * relieves the fullest account, or null when nothing clears
     * {@see MIN_GAIN}.
     *
     * Swaps are evaluated alongside single moves rather than after them: an
     * exchange is frequently the only change that helps at all, and a search
     * that tries single moves first would stop before ever reaching it.
     *
     * @param  array<array-key, int>  $assignment  user id => account id
     * @param  array<array-key, float>  $effective  user id => padded demand
     * @param  array<int, float>  $usable  account id => plannable capacity
     * @param  array<int, float>  $load  account id => padded demand currently on it
     * @return array{changes: array<array-key, int>, score: array{0: float, 1: float, 2: float}}|null
     */
    private function bestChange(array $assignment, array $effective, array $usable, array $load): ?array
    {
        $baseline = $this->score($load, $usable);
        $best = null;

        foreach ($assignment as $userId => $fromAccountId) {
            foreach ($usable as $toAccountId => $capacity) {
                if ($toAccountId === $fromAccountId) {
                    continue;
                }

                $candidate = $load;
                $candidate[$fromAccountId] -= $effective[$userId];
                $candidate[$toAccountId] += $effective[$userId];

                $best = $this->betterOf($best, [
                    'changes' => [$userId => $toAccountId],
                    'score' => [...$this->score($candidate, $usable), $effective[$userId]],
                ]);
            }
        }

        foreach ($assignment as $userId => $fromAccountId) {
            foreach ($assignment as $otherUserId => $otherAccountId) {
                if ($otherAccountId === $fromAccountId || $otherUserId <= $userId) {
                    continue; // same account, or the mirror image of a pair already tried
                }

                $delta = $effective[$userId] - $effective[$otherUserId];
                if ($delta === 0.0) {
                    continue; // an even exchange moves no load at all
                }

                $candidate = $load;
                $candidate[$fromAccountId] -= $delta;
                $candidate[$otherAccountId] += $delta;

                $best = $this->betterOf($best, [
                    'changes' => [$userId => $otherAccountId, $otherUserId => $fromAccountId],
                    'score' => [...$this->score($candidate, $usable), $effective[$userId] + $effective[$otherUserId]],
                ]);
            }
        }

        if ($best === null || $best['score'][0] > $baseline[0] - self::MIN_GAIN) {
            return null;
        }

        return $best;
    }

    /**
     * Whichever of two candidates scores lower, preferring the incumbent on
     * an exact tie so a run is reproducible.
     *
     * @param  array{changes: array<array-key, int>, score: array{0: float, 1: float, 2: float}}|null  $best  the incumbent, or null
     * @param  array{changes: array<array-key, int>, score: array{0: float, 1: float, 2: float}}  $candidate  the challenger
     * @return array{changes: array<array-key, int>, score: array{0: float, 1: float, 2: float}}
     */
    private function betterOf(?array $best, array $candidate): array
    {
        if ($best === null) {
            return $candidate;
        }

        return $candidate['score'] < $best['score'] ? $candidate : $best;
    }

    /**
     * How bad an arrangement is: the fullest account's share of its usable
     * capacity, then the sum of every account's squared share.
     *
     * The first term is the thing being fixed — an account running out
     * before its reset. The second only separates arrangements that tie on
     * it, favouring the one that spreads the remaining load rather than
     * leaving a second account nearly as full as the first. The caller adds
     * a third, the demand a change relocates: equally good arrangements are
     * common (moving the whale off a full account and moving the mouse onto
     * an empty one are often worth exactly the same), and when they are, the
     * one that disturbs fewer tokens — and so fewer, lighter people — is the
     * one to propose.
     *
     * @param  array<int, float>  $load  account id => padded demand on it
     * @param  array<int, float>  $usable  account id => plannable capacity
     * @return array{0: float, 1: float}
     */
    private function score(array $load, array $usable): array
    {
        $peak = 0.0;
        $spread = 0.0;

        foreach ($load as $accountId => $tokens) {
            $capacity = $usable[$accountId] ?? 0.0;
            $ratio = $capacity > 0.0 ? $tokens / $capacity : ($tokens > 0.0 ? PHP_FLOAT_MAX : 0.0);
            $peak = max($peak, $ratio);
            $spread += $ratio ** 2;
        }

        return [$peak, $spread];
    }

    /**
     * Padded demand sitting on each account under the given arrangement.
     *
     * @param  array<array-key, int>  $assignment  user id => account id
     * @param  array<array-key, float>  $effective  user id => padded demand
     * @param  array<int, float>  $usable  account id => plannable capacity, for the key set
     * @return array<int, float>
     */
    private function loads(array $assignment, array $effective, array $usable): array
    {
        $load = array_fill_keys(array_keys($usable), 0.0);

        foreach ($assignment as $userId => $accountId) {
            if (array_key_exists($accountId, $load)) {
                $load[$accountId] += $effective[$userId];
            }
        }

        return $load;
    }

    /**
     * The account carrying the least, ties going to the lowest id.
     *
     * @param  array<int, float>  $load  account id => demand placed so far
     * @param  array<int, float>  $usable  account id => plannable capacity
     * @return int|null
     */
    private function emptiest(array $load, array $usable): ?int
    {
        $best = null;

        foreach ($load as $accountId => $tokens) {
            $capacity = $usable[$accountId] ?? 0.0;
            $ratio = $capacity > 0.0 ? $tokens / $capacity : PHP_FLOAT_MAX;

            if ($best === null || $ratio < $best['ratio']) {
                $best = ['id' => $accountId, 'ratio' => $ratio];
            }
        }

        return $best['id'] ?? null;
    }

    /**
     * Extra fraction of demand to reserve for a person with this burst
     * ratio: nothing for flat usage, rising with spikiness, capped.
     *
     * @param  float  $burstFactor  peak-hour over mean-hour usage
     * @return float
     */
    private function burstPad(float $burstFactor): float
    {
        return min(self::MAX_BURST_PAD, max(0.0, ($burstFactor - 1.0) / 8));
    }

    /**
     * Every user whose planned account differs from their current one, with
     * the counterpart flagged when two people cross in opposite directions
     * between the same pair — that pairing is the swap an admin is really
     * being asked to make, and half of it applied alone leaves the fleet
     * worse off than doing nothing.
     *
     * @param  array<array-key, int>  $current  user id => current account id
     * @param  array<array-key, int>  $assignment  user id => planned account id
     * @return array<int, array{user: array-key, from: int, to: int, swap_with: array-key|null}>
     */
    private function movesBetween(array $current, array $assignment): array
    {
        $moves = [];
        foreach ($assignment as $userId => $accountId) {
            $from = $current[$userId] ?? null;
            if ($from === null || $from === $accountId) {
                continue;
            }

            $moves[] = ['user' => $userId, 'from' => $from, 'to' => $accountId, 'swap_with' => null];
        }

        foreach ($moves as $index => $move) {
            foreach ($moves as $otherIndex => $other) {
                if ($index !== $otherIndex
                    && $moves[$index]['swap_with'] === null
                    && $other['swap_with'] === null
                    && $other['from'] === $move['to']
                    && $other['to'] === $move['from']
                ) {
                    $moves[$index]['swap_with'] = $other['user'];
                    $moves[$otherIndex]['swap_with'] = $move['user'];

                    break;
                }
            }
        }

        return $moves;
    }

    /**
     * Tokens landing on each account under the given arrangement.
     *
     * @param  array<int, float>  $capacities  account id => capacity, for the key set
     * @param  array<array-key, float>  $demands  user id => weekly demand
     * @param  array<array-key, int>  $arrangement  user id => account id
     * @return array<int, float>
     */
    private function fills(array $capacities, array $demands, array $arrangement): array
    {
        $fills = array_fill_keys(array_keys($capacities), 0.0);

        foreach ($arrangement as $userId => $accountId) {
            if (array_key_exists($accountId, $fills)) {
                $fills[$accountId] += $demands[$userId] ?? 0.0;
            }
        }

        return $fills;
    }

    /**
     * Tokens each account is planned to carry beyond what it can actually
     * serve. Reported per account rather than per person: once several
     * people share an account there is no honest way to say whose tokens
     * are the ones that do not fit, and an overflowing account is the thing
     * an admin has to act on anyway.
     *
     * @param  array<int, float>  $load  account id => padded demand planned onto it
     * @param  array<int, float>  $usable  account id => plannable capacity
     * @return array<int, float>
     */
    private function overflow(array $load, array $usable): array
    {
        $over = [];

        foreach ($load as $accountId => $tokens) {
            $excess = $tokens - ($usable[$accountId] ?? 0.0);
            if ($excess > 0.0) {
                $over[$accountId] = $excess;
            }
        }

        return $over;
    }
}
