<?php

namespace App\Services\Profile;

use App\Livewire\FighterSheet;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Derives the fighter sheet's dismissible alert lines (quota critical/
 * warning, CLI outdated) from data {@see AccountQuotaCards::for()} and
 * {@see FighterSheet::attributionStatus()} already compute —
 * this service does no querying of its own.
 */
class AccountAlerts
{
    /**
     * Utilization at or above which a window is critical.
     *
     * @var int
     */
    private const int CRITICAL_PERCENT = 95;

    /**
     * The dismissible alert lines for the fighter sheet's banner, quota
     * alerts first then a trailing CLI-outdated line.
     *
     * @param  array<int, array>  $accountRows  rows from AccountQuotaCards::for()
     * @param  array{outdated: bool, clientVersion?: ?string, latestVersion?: ?string}  $attribution
     * @return array<int, array{id: string, severity: 'critical'|'warning'|'info', text: string}>
     */
    public function for(array $accountRows, array $attribution): array
    {
        $alerts = [];

        foreach ($accountRows as $row) {
            $alerts = [...$alerts, ...$this->quotaAlerts($row)];
        }

        if ($attribution['outdated'] ?? false) {
            $alerts[] = [
                'id' => 'cli-outdated',
                'severity' => 'info',
                'text' => "Your CLI is {$attribution['clientVersion']}. Version {$attribution['latestVersion']} is out.",
            ];
        }

        return $alerts;
    }

    /**
     * The critical/warning alerts for one Claude account's 5h/7d windows,
     * or its Codex windows via codexAlerts() when it reports those instead.
     *
     * @param  array  $row  one AccountQuotaCards row
     * @return array<int, array{id: string, severity: 'critical'|'warning'|'info', text: string}>
     */
    private function quotaAlerts(array $row): array
    {
        if (! empty($row['codex_windows'])) {
            return $this->codexAlerts($row);
        }

        $alerts = [];

        foreach (['5h' => ['util_5h', 'reset_5h_at', 'projected_5h'], '7d' => ['util_7d', 'reset_7d_at', 'projected_7d']] as $window => [$utilKey, $resetKey, $projectedKey]) {
            $util = $row[$utilKey] ?? null;
            $reset = $row[$resetKey] ?? null;
            $projected = $row[$projectedKey] ?? null;

            if ($util === null) {
                continue;
            }

            // A reset time already in the past means this snapshot is
            // stale (the account's probe has been failing, or hasn't run
            // since the window actually refilled) — its util% can no
            // longer be trusted enough to alert on.
            if ($reset !== null && $reset->isPast()) {
                continue;
            }

            if ($util >= self::CRITICAL_PERCENT) {
                $alerts[] = [
                    'id' => "account-{$row['account_id']}-critical-{$window}",
                    'severity' => 'critical',
                    'text' => "{$row['email']} is at {$util}% of its {$window} quota".($reset ? '. It refills in '.$this->until($reset).'.' : '.'),
                ];

                continue;
            }

            if ($projected !== null && $projected >= 100 && $reset) {
                $alerts[] = [
                    'id' => "account-{$row['account_id']}-warning-{$window}",
                    'severity' => 'warning',
                    'text' => "At this pace {$row['email']} runs out of its {$window} quota before it refills on {$reset->format('D H:i')}.",
                ];
            }
        }

        return $alerts;
    }

    /**
     * The critical alerts for one Codex account's own reported windows
     * (a 30-day cap on the free tier, or its own paid-tier windows).
     *
     * @param  array  $row  one AccountQuotaCards row (Codex provider)
     * @return array<int, array{id: string, severity: 'critical'|'warning'|'info', text: string}>
     */
    private function codexAlerts(array $row): array
    {
        $alerts = [];

        foreach ($row['codex_windows'] as $window) {
            if ($window['percent'] >= self::CRITICAL_PERCENT) {
                $label = $window['label'];
                $alerts[] = [
                    'id' => "account-{$row['account_id']}-critical-codex-{$label}",
                    'severity' => 'critical',
                    'text' => "{$row['email']} is at {$window['percent']}% of its {$label} quota.",
                ];
            }
        }

        return $alerts;
    }

    /**
     * A short absolute-duration phrase ("2 hours", never "2 hours ago" or
     * "in 2 hours") until a reset timestamp.
     *
     * @param  Carbon  $at
     * @return string
     */
    private function until(Carbon $at): string
    {
        return now()->diffForHumans($at, ['parts' => 1, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    }
}
