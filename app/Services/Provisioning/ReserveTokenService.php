<?php

namespace App\Services\Provisioning;

use App\Exceptions\AccountConnectException;
use App\Exceptions\UsageProbeException;
use App\Models\Account;
use App\Models\AccountReserveToken;
use App\Models\User;
use App\Services\AccountConnectService;
use App\Services\AccountProvisioningService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The per-account pool of Claude tokens an admin mints ahead of time, so a
 * later assign or reissue can skip the authorize-URL / paste-code step. One
 * class for the pool's few tightly-related operations, the way
 * `DamageTotals` holds its related reads; handing a token out lives in
 * {@see AccountProvisioningService::provisionFromReserve()}.
 */
final class ReserveTokenService
{
    /**
     * Build the service with the identity-checked PKCE exchange.
     *
     * @param  AccountConnectService  $connect  exchanges a pasted code and verifies the authorized identity
     * @return void
     */
    public function __construct(private readonly AccountConnectService $connect) {}

    /**
     * Exchange a pasted code for `$account` and store the token in the
     * pool. The code must authorize `$account` itself. The session deadline
     * comes from the exchange's `refresh_token_expires_in`; when Anthropic
     * omits it, the typical lifetime is used and flagged as estimated.
     *
     * @param  Account  $account  the account the token is minted for
     * @param  User|null  $admin  the admin minting it
     * @param  string  $state  the state from {@see AccountConnectService::start()}
     * @param  string  $pastedCode  the code the admin pasted
     * @return AccountReserveToken
     *
     * @throws AccountConnectException when the link expired or the code authorizes a different account
     * @throws UsageProbeException when Anthropic rejects the code
     */
    public function mint(Account $account, ?User $admin, string $state, string $pastedCode): AccountReserveToken
    {
        $token = $this->connect->exchangeVerifiedToken($state, $pastedCode, $account);
        $hasRealDeadline = isset($token['refresh_token_expires_in']);
        $sessionSeconds = $hasRealDeadline
            ? (int) $token['refresh_token_expires_in']
            : GrantSessionExpiryBackfiller::TYPICAL_SESSION_LIFETIME_SECONDS;

        return $account->reserveTokens()->create([
            'created_by' => $admin?->id,
            'token_uuid' => $token['token_uuid'] ?? null,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'access_expires_at' => Carbon::now()->addSeconds((int) $token['expires_in']),
            'session_expires_at' => Carbon::now()->addSeconds($sessionSeconds),
            'session_expires_at_estimated' => ! $hasRealDeadline,
        ]);
    }

    /**
     * Remove a token from the pool. It stays valid at Anthropic until it
     * expires; the app simply never hands it out.
     *
     * @param  AccountReserveToken  $token  the token to discard
     * @return void
     */
    public function discard(AccountReserveToken $token): void
    {
        $token->update(['discarded_at' => Carbon::now()]);
    }

    /**
     * The account's assignable tokens, soonest-expiring first.
     *
     * @param  Account  $account  the account
     * @return Collection<int, AccountReserveToken>
     */
    public function availableFor(Account $account): Collection
    {
        return $account->reserveTokens()->available()->get();
    }

    /**
     * How many assignable tokens the account has.
     *
     * @param  Account  $account  the account
     * @return int
     */
    public function availableCount(Account $account): int
    {
        return $account->reserveTokens()->available()->count();
    }
}
