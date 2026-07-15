<?php

namespace App\Filament\Concerns;

use App\Exceptions\AccountConnectException;
use App\Services\AccountConnectService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Hosts the two-step "Connect account" flow (open PKCE attempt → resolve pasted
 * code by identity → confirm-and-create for a brand-new account) so any Filament
 * page can offer it. Filament resolves `connectAccount`/`confirmCreateAccount`
 * actions by the `{name}Action` method convention, which finds these trait
 * methods on the using class.
 */
trait ConnectsAccounts
{
    /**
     * The open "Connect account" header action: starts a fresh PKCE attempt,
     * shows the authorize URL, and on submit resolves the pasted code by
     * identity. An existing account has its token updated in place; a brand-new
     * identity opens the {@see confirmCreateAccountAction()} modal.
     *
     * @return Action
     */
    public function connectAccountAction(): Action
    {
        return Action::make('connectAccount')
            ->label('Connect account')
            ->icon('heroicon-o-link')
            ->modalHeading('Connect a Claude account')
            ->modalDescription('Open the authorize URL, log in as the account you want to add, approve, then paste the code back here.')
            ->modalSubmitActionLabel('Continue')
            ->fillForm(function (): array {
                $started = app(AccountConnectService::class)->start();

                return [
                    'authorize_url' => $started['url'],
                    'state' => $started['state'],
                    'code' => '',
                ];
            })
            ->schema([
                TextInput::make('authorize_url')
                    ->label('Authorize URL')
                    ->readOnly()
                    ->copyable(),
                Hidden::make('state'),
                TextInput::make('code')
                    ->label('Paste the code here')
                    ->required(),
            ])
            ->action(function (array $data, Component $livewire): void {
                try {
                    $resolution = app(AccountConnectService::class)->resolve($data['state'], $data['code']);
                } catch (AccountConnectException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Connect failed')
                        ->body(match ($exception->reason) {
                            'connect_state_expired' => 'This connect link expired or was already used. Start again.',
                            'connect_no_identity' => 'Could not read an email from the authorized Claude account.',
                            default => 'Something went wrong completing the connect.',
                        })
                        ->send();

                    return;
                }

                if ($resolution->isExisting()) {
                    Notification::make()
                        ->success()
                        ->title('Token updated')
                        ->body("Updated the token for {$resolution->account->email}.")
                        ->send();

                    return;
                }

                $draft = $resolution->draft;
                $livewire->replaceMountedAction('confirmCreateAccount', [
                    'key' => $draft->handoffKey,
                    'email' => $draft->email,
                    'orgUuid' => $draft->orgUuid,
                    'plan' => $draft->plan,
                    'name' => $draft->name,
                ]);
            });
    }

    /**
     * The follow-up "confirm and create" modal for a brand-new identity,
     * mounted by name from {@see connectAccountAction()} via
     * `replaceMountedAction()`. It is resolved on demand by Filament's
     * `{name}Action` method convention (never rendered as its own button).
     * Email and organization uuid are read-only; plan and name are editable.
     *
     * @return Action
     */
    public function confirmCreateAccountAction(): Action
    {
        return Action::make('confirmCreateAccount')
            ->modalHeading('Create and connect account')
            ->modalDescription('This Claude account is new. Confirm the details to create it.')
            ->modalSubmitActionLabel('Create & connect')
            ->fillForm(fn (array $arguments): array => [
                'email' => $arguments['email'] ?? '',
                'organization_uuid' => $arguments['orgUuid'] ?? null,
                'plan' => $arguments['plan'] ?? 'max-20x',
                'name' => $arguments['name'] ?? null,
            ])
            ->schema([
                TextInput::make('email')
                    ->label('Email')
                    ->readOnly(),
                TextInput::make('organization_uuid')
                    ->label('Organization UUID')
                    ->readOnly(),
                TextInput::make('plan')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->maxLength(255),
            ])
            ->action(function (array $data, array $arguments): void {
                try {
                    $account = app(AccountConnectService::class)->createFromPending(
                        $arguments['key'],
                        $data['plan'],
                        ($data['name'] ?? null) ?: null,
                    );
                } catch (AccountConnectException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Connect failed')
                        ->body($exception->reason === 'connect_state_expired'
                            ? 'This connect session expired. Start the connect again.'
                            : 'Something went wrong creating the account.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Account connected')
                    ->body("Created and connected {$account->email}.")
                    ->send();
            });
    }
}
