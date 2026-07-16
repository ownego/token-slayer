<?php

namespace App\Filament\Resources\Shield\Pages;

use App\Filament\Resources\Shield\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles as BaseListRoles;

/**
 * Re-points Shield's list page at our `RoleResource` subclass. Shield's own
 * page classes hardcode `$resource` to Shield's base resource, which would
 * otherwise route table row actions (edit/view links) back to a resource
 * that is never registered on the panel (Shield suppresses its own
 * registration once ours is published — see `Utils::isResourcePublished()`).
 */
class ListRoles extends BaseListRoles
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<RoleResource>
     */
    protected static string $resource = RoleResource::class;
}
