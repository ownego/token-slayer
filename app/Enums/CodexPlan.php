<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Display-only mapping of a Codex account's raw `codex_credentials.plan_type`
 * (ChatGPT's own plan string) to a Filament badge label/color. Deliberately
 * separate from {@see AccountPlan} (Claude) — the two providers' plan
 * concepts aren't the same axis, and this enum is never persisted to a
 * database column, only used where a table/infolist renders a Codex
 * account's plan.
 */
enum CodexPlan: string implements HasColor, HasLabel
{
    /**
     * ChatGPT Free.
     */
    case Free = 'free';

    /**
     * ChatGPT Plus.
     */
    case Plus = 'plus';

    /**
     * ChatGPT Pro.
     */
    case Pro = 'pro';

    /**
     * ChatGPT Team.
     */
    case Team = 'team';

    /**
     * ChatGPT Enterprise.
     */
    case Enterprise = 'enterprise';

    /**
     * ChatGPT Business.
     */
    case Business = 'business';

    /**
     * Human-readable label shown by Filament badges.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Plus => 'Plus',
            self::Pro => 'Pro',
            self::Team => 'Team',
            self::Enterprise => 'Enterprise',
            self::Business => 'Business',
        };
    }

    /**
     * Badge color used by Filament table columns.
     *
     * @return string
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Free => 'gray',
            self::Plus => 'info',
            self::Pro, self::Business => 'warning',
            self::Team, self::Enterprise => 'success',
        };
    }
}
