<?php

namespace App\Services\Profile;

use App\Support\SheetFormat;

/**
 * Shapes AccountQuotaCards rows into the fighter sheet's account cards: one
 * meter per quota window the account actually reports, the card's overall
 * state (its fullest window, or a window on pace to run out), the hover
 * sentence that state reads as, and which card has the most room.
 */
final class AccountCardSummaries
{
    /**
     * How serious each state is, for picking a card's worst window.
     *
     * @var array<string, int>
     */
    private const array SEVERITY = ['good' => 0, 'warning' => 1, 'critical' => 2];

    /**
     * Claude's fixed windows: display name, tooltip, and the row keys holding
     * its utilization, reset and projection.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const array CLAUDE_WINDOWS = [
        ['5-hour', 'The rolling 5-hour limit everyone on this account shares. At 100% Claude pauses until it refills.', 'util_5h', 'reset_5h_at', 'projected_5h'],
        ['7-day', 'The weekly limit shared by everyone on this account. It refills all at once at the time shown.', 'util_7d', 'reset_7d_at', 'projected_7d'],
    ];

    /**
     * Each row with `windows`, `worst`, `summary` and `roomiest` added.
     *
     * @param  array<int, array<string, mixed>>  $accounts  AccountQuotaCards rows
     * @return array<int, array<string, mixed>>
     */
    public function present(array $accounts): array
    {
        $cards = array_map(fn (array $account): array => $this->card($account), $accounts);

        $probed = array_filter($cards, fn (array $card): bool => $card['windows'] !== []);
        $roomiest = count($probed) > 1
            ? collect($probed)->sortBy(fn (array $card): int => max(array_column($card['windows'], 'util')))->keys()->first()
            : null;

        foreach ($cards as $index => $card) {
            $cards[$index]['roomiest'] = $index === $roomiest;
        }

        return $cards;
    }

    /**
     * One account's windows, state and sentence.
     *
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    private function card(array $account): array
    {
        $windows = $this->windows($account);
        $worst = null;

        foreach ($windows as $window) {
            $status = SheetFormat::quotaStatus($window['util']);
            $runsOut = $status !== 'critical' && ($window['projected'] ?? 0) >= 100;
            $effective = $runsOut ? 'warning' : $status;

            if ($worst === null || self::SEVERITY[$effective] > self::SEVERITY[$worst['status']]) {
                $worst = ['status' => $effective, 'window' => $window, 'runsOut' => $runsOut];
            }
        }

        return [
            ...$account,
            'windows' => $windows,
            'worst' => $worst['status'] ?? null,
            'summary' => $worst ? $this->sentence($worst) : null,
        ];
    }

    /**
     * The windows an account reports, in the sheet's meter shape. Codex
     * reports its own windows (a free account has a single 30-day cap), so
     * they replace the Claude pair rather than sit under it.
     *
     * @param  array<string, mixed>  $account
     * @return array<int, array{name: string, tip: string, util: int, reset: mixed, projected: ?int}>
     */
    private function windows(array $account): array
    {
        if (! empty($account['codex_windows'])) {
            return array_map(fn (array $window): array => [
                'name' => $window['label'],
                'tip' => 'A usage limit shared by everyone on this account.',
                'util' => $window['percent'],
                'reset' => $window['resets_at'],
                'projected' => null,
            ], $account['codex_windows']);
        }

        $windows = [];
        foreach (self::CLAUDE_WINDOWS as [$name, $tip, $utilKey, $resetKey, $projectedKey]) {
            if ($account[$utilKey] !== null) {
                $windows[] = [
                    'name' => $name,
                    'tip' => $tip,
                    'util' => $account[$utilKey],
                    'reset' => $account[$resetKey],
                    'projected' => $account[$projectedKey],
                ];
            }
        }

        return $windows;
    }

    /**
     * The hover sentence for a card's worst window.
     *
     * @param  array{status: string, window: array<string, mixed>, runsOut: bool}  $worst
     * @return string
     */
    private function sentence(array $worst): string
    {
        $window = $worst['window'];
        $refills = $window['reset'] ? ', refills in '.SheetFormat::duration($window['reset']) : '';

        return match (true) {
            $window['reset'] !== null && $window['reset']->isPast() => "{$window['name']} hasn't refreshed since it last reset",
            $worst['status'] === 'critical' => "{$window['name']} almost out{$refills}",
            $worst['runsOut'] => "{$window['name']} runs out before it refills at this pace",
            $worst['status'] === 'warning' => "{$window['name']} is close{$refills}",
            default => 'Every quota has room',
        };
    }
}
