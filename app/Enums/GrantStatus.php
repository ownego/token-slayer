<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle state of a provisioned OAuth grant on a device. String-backed
 * because the value persists on `account_provisioned_grants.status`;
 * implements Filament's label/color contracts so badges render without
 * ad-hoc closures.
 */
enum GrantStatus: string implements HasColor, HasLabel
{
    /**
     * Created by an admin; no machine has pulled it yet.
     */
    case Pending = 'pending';

    /**
     * A machine pulled the grant (the device may still be the legacy
     * `'default'` sentinel for pre-migration rows).
     */
    case Claimed = 'claimed';

    /**
     * Revoked by an admin (directly or as the first half of a Reissue).
     */
    case Revoked = 'revoked';

    /**
     * Human-readable label shown by Filament badges.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Claimed => 'Claimed',
            self::Revoked => 'Revoked',
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
            self::Pending => 'warning',
            self::Claimed => 'success',
            self::Revoked => 'danger',
        };
    }
}
