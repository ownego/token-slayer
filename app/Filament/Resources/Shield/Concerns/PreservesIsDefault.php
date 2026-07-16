<?php

namespace App\Filament\Resources\Shield\Concerns;

use Illuminate\Support\Arr;

/**
 * Shared `is_default` pull/restore logic for `CreateRole` and `EditRole`.
 * Shield's own `mutateFormDataBeforeCreate`/`mutateFormDataBeforeSave`
 * implementations treat every form key other than
 * `name`/`guard_name`/`select_all`/the tenant key as a permission name to
 * sync onto the role, then whitelist only `name`/`guard_name` back into the
 * persisted attributes. `is_default` would otherwise be swept into that
 * permission-name collection and dropped entirely. Both pages pull it out
 * before delegating to Shield's parent mutator, then merge it back onto the
 * whitelisted result via these two helpers.
 */
trait PreservesIsDefault
{
    /**
     * Pulls `is_default` out of the raw form data before it reaches Shield's
     * permission-name collection logic, casting the result to a boolean.
     *
     * @param  array<string, mixed>  $data  the raw form state, modified by reference
     * @return bool
     */
    protected function pullIsDefault(array &$data): bool
    {
        return (bool) Arr::pull($data, 'is_default', false);
    }

    /**
     * Restores a previously pulled `is_default` value onto the data Shield's
     * parent mutator whitelisted back (`name`/`guard_name`/...).
     *
     * @param  array<string, mixed>  $mutated  the whitelisted data returned by Shield's parent mutator
     * @param  bool  $isDefault  the value pulled via `pullIsDefault()`
     * @return array<string, mixed>
     */
    protected function restoreIsDefault(array $mutated, bool $isDefault): array
    {
        $mutated['is_default'] = $isDefault;

        return $mutated;
    }
}
