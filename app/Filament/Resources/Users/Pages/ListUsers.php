<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The User index page of `UserResource`. No header "New" action — users
 * self-register via Slack OAuth, not through the admin panel.
 */
class ListUsers extends ListRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<UserResource>
     */
    protected static string $resource = UserResource::class;
}
