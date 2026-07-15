<?php

namespace App\Services\Connect;

use App\Models\Account;

/**
 * Outcome of a PKCE connect resolution: either an existing account whose token
 * was just updated ({@see existing()}), or a pending draft for a new account
 * awaiting the admin's confirm-and-create step ({@see pending()}).
 */
final readonly class ConnectResolution
{
    /**
     * Build a resolution. Exactly one of `$account` / `$draft` is non-null.
     *
     * @param  ?Account  $account  the existing account whose token was updated, or null
     * @param  ?ConnectDraft  $draft  the draft for a new account, or null
     * @return void
     */
    private function __construct(
        public ?Account $account,
        public ?ConnectDraft $draft,
    ) {}

    /**
     * A resolution against an existing account whose token was just updated.
     *
     * @param  Account  $account  the matched, freshly-updated account
     * @return self
     */
    public static function existing(Account $account): self
    {
        return new self($account, null);
    }

    /**
     * A resolution for a brand-new identity awaiting confirm-and-create.
     *
     * @param  ConnectDraft  $draft  the profile-derived draft
     * @return self
     */
    public static function pending(ConnectDraft $draft): self
    {
        return new self(null, $draft);
    }

    /**
     * Whether this resolution matched an existing account (vs a new draft).
     *
     * @return bool
     */
    public function isExisting(): bool
    {
        return $this->account !== null;
    }
}
