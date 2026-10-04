<?php

namespace App\Filament\Actions;

use App\Exceptions\AccountConnectException;
use App\Exceptions\ReserveTokenUnavailableException;
use App\Exceptions\UsageProbeException;
use App\Models\Account;
use App\Models\AccountReserveToken;
use App\Services\Provisioning\ReserveTokenService;
use App\Support\DaysLeft;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;

/**
 * The "where does the token come from" part of every modal that hands a
 * Claude grant to a device: paste a freshly authorized code, or pick one
 * from the account's reserve pool. A segmented switch rather than Filament
 * `Tabs`, because the submitted data has to say which source the admin
 * chose and `Tabs` never sends its active tab.
 */
final class TokenSourceFields
{
    /**
     * Form value of the paste-code source.
     *
     * @var string
     */
    public const string PASTE = 'paste';

    /**
     * Form value of the reserve-pool source.
     *
     * @var string
     */
    public const string RESERVE = 'reserve';

    /**
     * The form fields: the source switch, the paste-code fields and the
     * reserve-token picker, each shown only for its own source.
     *
     * @param  Account  $account  the account whose reserve pool is offered
     * @return array<int, Component>
     */
    public static function schema(Account $account): array
    {
        $available = app(ReserveTokenService::class)->availableFor($account);

        return [
            ToggleButtons::make('source')
                ->hiddenLabel()
                ->inline()
                ->options([
                    self::PASTE => 'Paste code',
                    self::RESERVE => "From reserve ({$available->count()})",
                ])
                ->disableOptionWhen(fn (string $value): bool => $value === self::RESERVE && $available->isEmpty())
                ->live()
                ->required(),
            TextInput::make('authorize_url')
                ->label('Authorize URL')
                ->readOnly()
                ->copyable()
                ->visible(fn (Get $get): bool => $get('source') === self::PASTE),
            Hidden::make('state'),
            TextInput::make('code')
                ->label('Paste the code here')
                ->visible(fn (Get $get): bool => $get('source') === self::PASTE)
                ->required(fn (Get $get): bool => $get('source') === self::PASTE),
            Select::make('reserve_token_id')
                ->label('Reserve token')
                ->options($available->mapWithKeys(fn (AccountReserveToken $token): array => [
                    $token->id => "#{$token->id} · ".DaysLeft::label($token->session_expires_at, $token->session_expires_at_estimated),
                ])->all())
                ->visible(fn (Get $get): bool => $get('source') === self::RESERVE)
                ->required(fn (Get $get): bool => $get('source') === self::RESERVE),
        ];
    }

    /**
     * The modal's initial state: the reserve pool preselected on its
     * soonest-expiring token when it has any (use old tokens first), paste
     * code otherwise.
     *
     * @param  Account  $account  the account whose reserve pool is offered
     * @param  string  $authorizeUrl  the PKCE authorize URL for the paste path
     * @param  string  $state  the PKCE state for the paste path
     * @return array{source: string, authorize_url: string, state: string, code: string, reserve_token_id: ?int}
     */
    public static function fill(Account $account, string $authorizeUrl, string $state): array
    {
        $soonest = app(ReserveTokenService::class)->availableFor($account)->first();

        return [
            'source' => $soonest === null ? self::PASTE : self::RESERVE,
            'authorize_url' => $authorizeUrl,
            'state' => $state,
            'code' => '',
            'reserve_token_id' => $soonest?->id,
        ];
    }

    /**
     * Whether the submitted form chose the reserve pool.
     *
     * @param  array<string, mixed>  $data  the submitted form data
     * @return bool
     */
    public static function usesReserve(array $data): bool
    {
        return ($data['source'] ?? self::PASTE) === self::RESERVE;
    }

    /**
     * Show the failure notification for any way handing out a token fails:
     * a reserve token gone meanwhile, an expired or mismatched connect link,
     * or Anthropic rejecting the pasted code.
     *
     * @param  Throwable  $exception  the failure
     * @param  string  $title  the notification title, e.g. 'Reissue failed'
     * @return void
     */
    public static function notifyFailure(Throwable $exception, string $title): void
    {
        $body = match (true) {
            $exception instanceof ReserveTokenUnavailableException => $exception->getMessage(),
            $exception instanceof AccountConnectException => match ($exception->reason) {
                'connect_identity_mismatch' => $exception->getMessage(),
                'connect_state_expired' => 'This connect link expired or was already used. Start again.',
                default => 'Something went wrong completing the connect.',
            },
            $exception instanceof UsageProbeException => $exception->reason === 'invalid_grant'
                ? 'Anthropic rejected that code — it may be stale or already used. Open a fresh authorize link and try again.'
                : "Anthropic error ({$exception->reason}): {$exception->getMessage()}",
            default => 'Something went wrong.',
        };

        Notification::make()->danger()->title($title)->body($body)->send();
    }
}
