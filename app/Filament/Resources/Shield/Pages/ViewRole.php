<?php

namespace App\Filament\Resources\Shield\Pages;

use App\Filament\Resources\Shield\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ViewRole as BaseViewRole;

/**
 * Re-points Shield's view page at our `RoleResource` subclass — see
 * `ListRoles` in this namespace for why this repointing is required.
 */
class ViewRole extends BaseViewRole
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<RoleResource>
     */
    protected static string $resource = RoleResource::class;
}
