<?php

namespace App\Filament\Concerns;

use App\Exceptions\CodexConnectException;
use App\Services\CodexConnectService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Hosts the "Connect a Codex account" header action: a device-code flow —
 * no pasted code, no loopback listener. The admin opens the shown
 * verification URL in their own browser, enters the shown `user_code`,
 * approves, then comes back and clicks "Check now". A still-pending check
 * re-opens the same attempt (via {@see Component::replaceMountedAction()})
 * rather than closing, so the admin can click it again once they've
 * approved. Filament resolves `connectCodexAccount` by the `{name}Action`
 * method convention, so any Filament page can host this by using this
 * trait.
 */
trait ConnectsCodexAccounts
{
    /**
     * The "Connect Codex account" header action: starts (or resumes) a
     * device-code attempt and lets the admin check its status on demand.
     *
     * @return Action
     */
    public function connectCodexAccountAction(): Action
    {
        return Action::make('connectCodexAccount')
            ->label('Connect Codex account')
            ->icon('heroicon-o-link')
            ->modalHeading('Connect a Codex account')
            ->modalDescription('Open the link below, enter the code, and approve as the account you want to add. Once approved, click "Check now".')
            ->modalSubmitActionLabel('Check now')
            ->fillForm(function (array $arguments): array {
                if (isset($arguments['state'])) {
                    return $arguments;
                }

                $started = app(CodexConnectService::class)->start();

                return [
                    'name' => '',
                    'state' => $started['state'],
                    'user_code' => $started['user_code'],
                    'verification_url' => $started['verification_url'],
                ];
            })
            ->schema([
                TextInput::make('verification_url')
                    ->label('Open this URL')
                    ->readOnly()
                    ->copyable(),
                TextInput::make('user_code')
                    ->label('Enter this code')
                    ->readOnly()
                    ->copyable(),
                TextInput::make('name')
                    ->label('Display name')
                    ->required(),
                Hidden::make('state'),
            ])
            ->action(function (array $data, Component $livewire): void {
                try {
                    $result = app(CodexConnectService::class)->poll($data['state'], $data['name']);
                } catch (CodexConnectException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Connect failed')
                        ->body($exception->getMessage())
                        ->send();

                    return;
                }

                if ($result->status === 'done') {
                    Notification::make()
                        ->success()
                        ->title('Codex account connected')
                        ->body("Connected {$result->account->email}.")
                        ->send();

                    return;
                }

                if ($result->status === 'expired') {
                    Notification::make()
                        ->danger()
                        ->title('Code expired')
                        ->body('This code expired. Click Connect Codex account to start again.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->warning()
                    ->title('Not approved yet')
                    ->body('Open the link, enter the code, approve, then click Check now again.')
                    ->send();

                $livewire->replaceMountedAction('connectCodexAccount', $data);
            });
    }
}
