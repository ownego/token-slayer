<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the CLI's reconcile confirmation body. Accepts
 * `{set_up:[{org_uuid}], removed:[{org_uuid}]}`; also accepts the legacy
 * `{accounts:[{org_uuid}]}` as `set_up` (old clients).
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
     * `set_up` / `removed` / legacy `accounts` are mutually optional — at
     * least one of the three must be present.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'set_up' => ['required_without_all:accounts,removed', 'array'],
            'set_up.*.org_uuid' => ['required_with:set_up', 'uuid'],
            'removed' => ['required_without_all:accounts,set_up', 'array'],
            'removed.*.org_uuid' => ['required_with:removed', 'uuid'],
            'accounts' => ['required_without_all:set_up,removed', 'array'],
            'accounts.*.org_uuid' => ['required_with:accounts', 'uuid'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
