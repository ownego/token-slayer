<?php

namespace App\Services\Accounts;

/**
 * Works out what each person's tokens cost against a quota, from windows
 * they mostly shared with other people.
 *
 * Every quota window is one equation: the utilisation it consumed equals
 * each participant's tokens times their own burn rate, added up. Enough
 * windows with the participants in different proportions and the individual
 * rates fall out — a window where the dial moved a long way on a few of one
 * person's tokens prices that person, even though they were never alone.
 *
 * The previous approach only used windows with exactly one person in them.
 * On the real fleet that was one to four windows per account out of fifteen
 * to nineteen, so almost every measurement was thrown away and the few that
 * survived were too thin to trust.
 *
 * Pure arithmetic: no database, no clock.
 */
final class QuotaCostSolver
{
    /**
     * Passes of the multiplicative update. It converges quickly on systems
     * this small; the count is a ceiling, not a target.
     *
     * @var int
     */
    private const int ITERATIONS = 400;

    /**
     * How much a person's share of a window must vary across the windows
     * before their rate is separable from the people they share with. Two
     * people who are always together in the same proportion cannot be told
     * apart by any amount of arithmetic, and a confident-looking number for
     * them would be invented rather than measured.
     *
     * @var float
     */
    private const float MIN_SHARE_SPREAD = 0.1;

    /**
     * Largest share of the total error the fitted rates may leave
     * unexplained. Beyond this the windows are not behaving like a sum of
     * per-person costs at all — somebody else is using the account, or the
     * probe missed the movement — and the fit means nothing.
     *
     * @var float
     */
    private const float MAX_RESIDUAL = 0.2;

    /**
     * Tokens each person needs to move the account's utilisation by one
     * point, keyed as the input was. Empty when the windows cannot support
     * an answer: too few of them, or people whose proportions never change.
     *
     * @param  array<int, array{delta: float, tokens: array<array-key, float>}>  $windows  each window's utilisation movement and who spent what in it
     * @return array<array-key, float> participant => tokens per utilisation point
     */
    public function solve(array $windows): array
    {
        $windows = array_values(array_filter(
            $windows,
            fn (array $window): bool => $window['delta'] > 0.0 && array_sum($window['tokens']) > 0.0,
        ));

        $participants = $this->participants($windows);
        if ($participants === [] || count($windows) <= count($participants)) {
            return []; // fewer equations than unknowns: any answer fits, so none does
        }

        $rates = $this->fit($windows, $participants);
        if ($rates === [] || $this->residual($windows, $rates) > self::MAX_RESIDUAL) {
            return [];
        }

        $separable = $this->separable($windows, $participants);

        $costs = [];
        foreach ($rates as $participant => $rate) {
            if ($rate > 0.0 && in_array($participant, $separable, true)) {
                $costs[$participant] = 1 / $rate;
            }
        }

        return $costs;
    }

    /**
     * Everyone who spent anything in any window.
     *
     * @param  array<int, array{delta: float, tokens: array<array-key, float>}>  $windows  the usable windows
     * @return array<int, array-key>
     */
    private function participants(array $windows): array
    {
        $seen = [];

        foreach ($windows as $window) {
            foreach ($window['tokens'] as $participant => $tokens) {
                if ($tokens > 0.0) {
                    $seen[$participant] = true;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Fit a burn rate per person by multiplicative update: each rate is
     * nudged by how much the windows it appears in are under- or
     * over-predicted. Rates stay positive throughout, which matters because
     * a negative burn rate is not a thing that can happen and a general
     * least-squares fit would happily produce one.
     *
     * @param  array<int, array{delta: float, tokens: array<array-key, float>}>  $windows  the usable windows
     * @param  array<int, array-key>  $participants  everyone to solve for
     * @return array<array-key, float> participant => utilisation points per token
     */
    private function fit(array $windows, array $participants): array
    {
        $totalDelta = array_sum(array_column($windows, 'delta'));
        $totalTokens = 0.0;
        foreach ($windows as $window) {
            $totalTokens += array_sum($window['tokens']);
        }

        if ($totalTokens <= 0.0) {
            return [];
        }

        $rates = array_fill_keys($participants, $totalDelta / $totalTokens);

        for ($pass = 0; $pass < self::ITERATIONS; $pass++) {
            $predicted = [];
            foreach ($windows as $index => $window) {
                $sum = 0.0;
                foreach ($window['tokens'] as $participant => $tokens) {
                    $sum += $tokens * ($rates[$participant] ?? 0.0);
                }
                $predicted[$index] = $sum;
            }

            foreach ($participants as $participant) {
                $numerator = 0.0;
                $denominator = 0.0;

                foreach ($windows as $index => $window) {
                    $tokens = $window['tokens'][$participant] ?? 0.0;
                    if ($tokens <= 0.0) {
                        continue;
                    }

                    $numerator += $tokens * $window['delta'];
                    $denominator += $tokens * $predicted[$index];
                }

                if ($denominator > 0.0) {
                    $rates[$participant] *= $numerator / $denominator;
                }
            }
        }

        return $rates;
    }

    /**
     * How much of the total movement the fitted rates fail to explain, as a
     * fraction.
     *
     * @param  array<int, array{delta: float, tokens: array<array-key, float>}>  $windows  the usable windows
     * @param  array<array-key, float>  $rates  participant => utilisation points per token
     * @return float
     */
    private function residual(array $windows, array $rates): float
    {
        $error = 0.0;
        $total = 0.0;

        foreach ($windows as $window) {
            $predicted = 0.0;
            foreach ($window['tokens'] as $participant => $tokens) {
                $predicted += $tokens * ($rates[$participant] ?? 0.0);
            }

            $error += abs($window['delta'] - $predicted);
            $total += $window['delta'];
        }

        return $total > 0.0 ? $error / $total : 1.0;
    }

    /**
     * Those whose rate can be told apart from the people they share with:
     * either their share of a window varies across the windows, or there is
     * a window they had to themselves.
     *
     * @param  array<int, array{delta: float, tokens: array<array-key, float>}>  $windows  the usable windows
     * @param  array<int, array-key>  $participants  everyone solved for
     * @return array<int, array-key>
     */
    private function separable(array $windows, array $participants): array
    {
        $separable = [];

        foreach ($participants as $participant) {
            $shares = [];

            foreach ($windows as $window) {
                $total = array_sum($window['tokens']);
                if ($total > 0.0) {
                    $shares[] = ($window['tokens'][$participant] ?? 0.0) / $total;
                }
            }

            if ($shares === []) {
                continue;
            }

            // Varying company separates them; so does having no company at
            // all, where the movement can only have been theirs.
            if (max($shares) - min($shares) >= self::MIN_SHARE_SPREAD || max($shares) >= 0.999) {
                $separable[] = $participant;
            }
        }

        return $separable;
    }
}
