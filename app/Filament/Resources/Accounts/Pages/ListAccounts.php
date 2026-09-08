<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Concerns\ConnectsAccounts;
use App\Filament\Concerns\ConnectsCodexAccounts;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * The Account index page of `AccountResource`. Hosts the open "Connect Claude
 * account" header action and its method-resolved "confirmCreateAccount"
 * follow-up modal, plus the "Connect Codex account" device-code header
 * action — all three collapsed into one "..." menu so the index page keeps a
 * single header control regardless of how many providers it grows to.
 */
class ListAccounts extends ListRecords
{
    use ConnectsAccounts;
    use ConnectsCodexAccounts;

    /**
     * The resource this page belongs to.
     *
     * @var class-string<AccountResource>
     */
    protected static string $resource = AccountResource::class;

    /**
     * Header actions available on the index page: one "..." group, so
     * mounting any of the three by name (`mountAction('connectAccount')`
     * etc.) still resolves exactly as it did as a standalone button.
     *
     * @return array<ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                CreateAction::make(),
                $this->connectAccountAction(),
                $this->connectCodexAccountAction(),
            ])
                ->iconButton()
                ->tooltip('Account actions'),
        ];
    }
}
