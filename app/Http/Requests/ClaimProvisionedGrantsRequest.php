<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the claim pull. `device_id` is the optional client machine
 * fingerprint — absent on old CLI versions, which the claim algorithm
 * treats as the legacy `'default'` device.
 */
final class ClaimProvisionedGrantsRequest extends FormRequest
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
     * `device_id` is optional and opaque; length-capped only.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
