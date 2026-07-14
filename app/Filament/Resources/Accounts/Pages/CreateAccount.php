<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * The "new Account" page of `AccountResource`.
 */
class CreateAccount extends CreateRecord
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<AccountResource>
     */
    protected static string $resource = AccountResource::class;
}
