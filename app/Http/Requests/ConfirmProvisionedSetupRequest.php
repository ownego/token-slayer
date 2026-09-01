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
            'expiring.*.refresh_token_expires_at' => ['required_with:expiring', 'integer'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
