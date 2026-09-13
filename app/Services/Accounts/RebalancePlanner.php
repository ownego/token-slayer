<?php

namespace App\Services\Accounts;

/**
 * Works out where everyone SHOULD sit, then reports the difference from
 * where they sit now.
 *
 * Planning the whole assignment and diffing it is what makes two-way moves
 * fall out on their own. The earlier design walked the overflowing accounts
 * and pushed one person off each onto whichever account had the most room,
 * which can only ever produce one-way traffic — and, with a single roomy
 * account, produced the degenerate result of every recommendation pointing
 * at the same target. Balancing the fleet as a whole instead naturally
 * answers "this person goes there, and that person comes back the other
 * way", because it is choosing an arrangement rather than a series of
 * escapes.
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
     * Build the balanced arrangement and the moves that reach it.
     *
     * Longest-processing-time first: the heaviest person is placed first,
     * into whichever account has the most room left in absolute tokens.
     * Placing the big items while every bin is still open is what keeps a
     * whale from being stranded after the small fry have fragmented the
     * space.
     *
     * @param  array<int, float>  $capacities  account id => weekly capacity in tokens
     * @param  array<array-key, float>  $demands  user id => weekly demand in tokens
     * @param  array<array-key, int>  $current  user id => the account they sit on today
     * @param  array<array-key, float>  $burstFactors  user id => peak-hour-over-mean-hour ratio
     * @param  float  $safetyMargin  fraction of every account's capacity to leave unplanned
     * @return array{assignment: array<array-key, int>, moves: array<int, array{user: array-key, from: int, to: int, swap_with: array-key|null}>, fill_before: array<int, float>, fill_after: array<int, float>, effective_demands: array<array-key, float>, unplaced: array<array-key, float>}
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

        arsort($effective);

        $remaining = $usable;
        $memberCount = array_fill_keys(array_keys($capacities), 0);
        $assignment = [];
        $unplaced = [];

        foreach ($effective as $userId => $demand) {
            $target = $this->roomiest($remaining, $memberCount);
            if ($target === null) {
                continue; // no accounts at all to place anyone on
            }

            if ($demand > $remaining[$target]) {
                $unplaced[$userId] = $demand - max(0.0, $remaining[$target]);
            }

            $assignment[$userId] = $target;
            $remaining[$target] -= $demand;
            $memberCount[$target]++;
        }

        return [
            'assignment' => $assignment,
            'moves' => $this->movesBetween($current, $assignment),
            'fill_before' => $this->fills($capacities, $demands, $current),
            'fill_after' => $this->fills($capacities, $demands, $assignment),
            'effective_demands' => $effective,
            'unplaced' => $unplaced,
        ];
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
     * The account with the most room left; ties go to whichever is carrying
     * fewer people, then to the lowest id so a run is reproducible.
     *
     * @param  array<int, float>  $remaining  account id => tokens still free
     * @param  array<int, int>  $memberCount  account id => people placed so far
     * @return int|null
     */
    private function roomiest(array $remaining, array $memberCount): ?int
    {
        $best = null;

        foreach ($remaining as $accountId => $room) {
            if ($best === null) {
                $best = $accountId;

                continue;
            }

            $bestRoom = $remaining[$best];
            if ($room > $bestRoom
                || ($room === $bestRoom && $memberCount[$accountId] < $memberCount[$best])
                || ($room === $bestRoom && $memberCount[$accountId] === $memberCount[$best] && $accountId < $best)
            ) {
                $best = $accountId;
            }
        }

        return $best;
    }

    /**
     * Every user whose planned account differs from their current one, with
     * the counterpart flagged when two people cross in opposite directions
     * between the same pair — that pairing is the swap an admin is really
     * being asked to make, and it reads as nonsense unless the two halves
     * are shown as belonging together.
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
     * Tokens landing on each account under the given arrangement, using the
     * raw (unpadded) demand — the pad is planning slack, not usage anyone
     * will actually consume, so it must not show up in a reported fill.
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
}
