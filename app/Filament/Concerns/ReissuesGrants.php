<?php

namespace App\Filament\Concerns;

use App\Exceptions\AccountConnectException;
use App\Exceptions\ReserveTokenUnavailableException;
use App\Exceptions\UsageProbeException;
use App\Filament\Actions\TokenSourceFields;
use App\Filament\Pages\ExpiringAccounts;
use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\ProvisionsRelationManager;
use App\Models\AccountProvisionedGrant;
use App\Services\AccountConnectService;
use App\Services\AccountProvisioningService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * Reissue one Claude grant on the same device, reached by grant id so any
 * Filament surface can host it — the Provisions tab
 * ({@see ProvisionsRelationManager}), the Members tab's Claimed devices
 * modal ({@see MembersRelationManager}) and the Expiring page
 * ({@see ExpiringAccounts}). The confirm modal offers both the paste-code
 * path and the account's reserve pool ({@see TokenSourceFields}). Filament
 * resolves `reissueGrant`/`confirmReissue` by the `{name}Action` method
 * convention.
 */
trait ReissuesGrants
{
    /**
     * The "Reissue" button for the grant named by `$arguments['grant']`.
     *
     * @return Action
     */
    public function reissueGrantAction(): Action
    {
        return Action::make('reissueGrant')
            ->label('Reissue')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize(fn (): bool => self::canReissueGrants())
            ->action(fn (array $arguments, Component $livewire) => $this->startReissue((int) $arguments['grant'], $livewire));
    }

    /**
     * Open a fresh PKCE attempt (for the paste-code path) and swap in the
     * confirm modal for `$grantId`.
     *
     * @param  int  $grantId  the grant to reissue
     * @param  Component  $livewire  the hosting Livewire component
     * @return void
     */
    public function startReissue(int $grantId, Component $livewire): void
    {
        $started = app(AccountConnectService::class)->start();

        $livewire->replaceMountedAction('confirmReissue', [
            'grantId' => $grantId,
            'authorizeUrl' => $started['url'],
            'state' => $started['state'],
        ]);
    }

    /**
     * The confirm modal: pick a reserve token or paste a code, then issue a
     * new grant on the same device — the old one is revoked by
     * {@see AccountProvisioningService::issueGrant()}. Never rendered as its
     * own button; mounted by name from {@see startReissue()}.
     *
     * @return Action
     */
    public function confirmReissueAction(): Action
    {
        return Action::make('confirmReissue')
            ->modalHeading('Reissue this grant')
            ->modalDescription('Use a reserve token, or open the authorize URL, approve, and paste the code back here. The old grant on this device is revoked once the new one is issued.')
            ->modalSubmitActionLabel('Reissue')
            ->authorize(fn (): bool => self::canReissueGrants())
            ->fillForm(fn (array $arguments): array => TokenSourceFields::fill(
                AccountProvisionedGrant::query()->findOrFail($arguments['grantId'])->account,
                $arguments['authorizeUrl'] ?? '',
                $arguments['state'] ?? '',
            ))
            ->schema(fn (array $arguments): array => TokenSourceFields::schema(
                AccountProvisionedGrant::query()->findOrFail($arguments['grantId'])->account,
            ))
            ->action(function (array $data, array $arguments): void {
                $grant = AccountProvisionedGrant::query()->with(['account', 'device.user'])->findOrFail($arguments['grantId']);
                $service = app(AccountProvisioningService::class);

                try {
                    if (TokenSourceFields::usesReserve($data)) {
                        $service->provisionFromReserve($grant->device->user, $grant->account, $grant->device, (int) $data['reserve_token_id']);
                    } else {
                        $service->provisionForDevice($grant->device->user, $grant->account, $grant->device, $data['state'], $data['code']);
                    }
                } catch (AccountConnectException|UsageProbeException|ReserveTokenUnavailableException $exception) {
                    TokenSourceFields::notifyFailure($exception, 'Reissue failed');

                    return;
                }

                Notification::make()->success()->title('Grant reissued')->send();
            });
    }

    /**
     * Whether the signed-in admin may reissue grants: the same
     * `Update:Account` permission that guards the account's own pages. The
     * Expiring page itself only needs `view_usage_analytics`, so without this
     * an analytics-only role could revoke member grants and spend the
     * reserve pool from there.
     *
     * @return bool
     */
    private static function canReissueGrants(): bool
    {
        return auth()->user()?->can('Update:Account') ?? false;
    }
}
