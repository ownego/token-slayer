<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only detail page for one `User`: basic identity (via the shared
 * `UserResource::form()` schema), the accounts they're a member of, and
 * the usage events they've logged, in relation-manager tabs. Reached by
 * clicking a row on the index page.
 */
class ViewUser extends ViewRecord
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<UserResource>
     */
    protected static string $resource = UserResource::class;

    /**
     * Header actions rendered above the record view: an Edit button, since
     * this page otherwise has no way to reach the edit form from the detail
     * view.
     *
     * @return array<int, EditAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
