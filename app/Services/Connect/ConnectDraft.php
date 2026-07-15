<?php

namespace App\Services\Connect;

use App\Services\AccountConnectService;

/**
 * Profile-derived draft of a not-yet-existing account, carried from
 * {@see AccountConnectService::resolve()} to the confirm-and-create
 * step so the admin can adjust the plan and name before the row is created. The
 * `handoffKey` points at the cached token material the create step consumes.
 */
final readonly class ConnectDraft
{
    /**
     * Build the draft from the authorized account's profile.
     *
     * @param  string  $email  the authorized account's email (identity)
     * @param  ?string  $orgUuid  the authorized organization uuid, if present
     * @param  string  $plan  the plan derived from organization.organization_type
     * @param  ?string  $name  a suggested display name (organization name or full name)
     * @param  string  $handoffKey  cache key of the stashed token material for the create step
     * @return void
     */
    public function __construct(
        public string $email,
        public ?string $orgUuid,
        public string $plan,
        public ?string $name,
        public string $handoffKey,
    ) {}
}
