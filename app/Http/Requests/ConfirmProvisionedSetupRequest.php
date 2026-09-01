<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the CLI's reconcile confirmation body. Accepts
 * `{set_up:[{org_uuid}], removed:[{org_uuid}], expiring:[{org_uuid, refresh_token_expires_at}]}`;
 * also accepts the legacy `{accounts:[{org_uuid}]}` as `set_up` (old clients).
 */
final class ConfirmProvisionedSetupRequest extends FormRequest
{
    /**
     * The `hook.token` middleware already gates this route.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `set_up` / `removed` / legacy `accounts` / `expiring` are mutually
     * optional — at least one of the four must be present.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'set_up' => ['required_without_all:accounts,removed,expiring', 'array'],
            'set_up.*.org_uuid' => ['required_with:set_up', 'uuid'],
            'removed' => ['required_without_all:accounts,set_up,expiring', 'array'],
            'removed.*.org_uuid' => ['required_with:removed', 'uuid'],
            'accounts' => ['required_without_all:set_up,removed,expiring', 'array'],
            'accounts.*.org_uuid' => ['required_with:accounts', 'uuid'],
            'expiring' => ['required_without_all:accounts,set_up,removed', 'array'],
            'expiring.*.org_uuid' => ['required_with:expiring', 'uuid'],
            // Anthropic's refresh-token deadline is a fixed ~27.5-day ceiling
            // from original login; 45 days is a generous but implausible-past
            // upper bound. Without it, a client reporting an absurdly
            // far-future timestamp would permanently suppress that org from
            // the admin warning page — nothing regresses the deadline
            // downward once stored (see the never-regress guard in
            // AccountProvisioningService's `expiring` loop).
            'expiring.*.refresh_token_expires_at' => ['required_with:expiring', 'integer', 'max:'.now()->addDays(45)->getTimestampMs()],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
