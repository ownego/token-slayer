<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

/**
 * The "edit User" page of `UserResource` — role assignment only.
 */
class EditUser extends EditRecord
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<UserResource>
     */
    protected static string $resource = UserResource::class;
}
