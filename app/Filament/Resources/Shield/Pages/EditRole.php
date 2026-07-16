<?php

namespace App\Filament\Resources\Shield\Pages;

use App\Filament\Resources\Shield\Concerns\PreservesIsDefault;
use App\Filament\Resources\Shield\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as BaseEditRole;

/**
 * Re-points Shield's edit page at our `RoleResource` subclass so the
 * `is_default` toggle actually renders (Shield's own page hardcodes
 * `$resource` to its own base resource — see `ListRoles` in this namespace)
 * and survives the save.
 */
class EditRole extends BaseEditRole
{
    use PreservesIsDefault;

    /**
     * The resource this page belongs to.
     *
     * @var class-string<RoleResource>
     */
    protected static string $resource = RoleResource::class;

    /**
     * The `is_default` value pulled out of the form data by
     * `mutateFormDataBeforeSave()`, applied to the record in `afterSave()`.
     *
     * @var bool
     */
    protected bool $pulledIsDefault = false;

    /**
     * Preserves `is_default` across Shield's permission-name collection
     * logic — see `PreservesIsDefault` for why this pull is needed. Unlike
     * `CreateRole`, this value is deliberately NOT restored onto `$data`
     * here: `EditRecord::handleRecordUpdate()` persists via
     * `$record->update($data)`, genuine mass-assignment that would depend on
     * `is_default` being guardable at the moment of the call. Instead the
     * pulled value is stashed and applied directly in `afterSave()` via
     * `forceFill()`, sidestepping mass-assignment entirely.
     *
     * @param  array<string, mixed>  $data  the raw form state
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pulledIsDefault = $this->pullIsDefault($data);

        return parent::mutateFormDataBeforeSave($data);
    }

    /**
     * Applies the pulled `is_default` value to the saved record via
     * `forceFill()->save()`, after Shield's own `afterSave()` has synced
     * permissions. This is a genuine attribute write (not mass-assignment),
     * so it is immune to the guardable-columns cache landmine.
     *
     * @return void
     */
    protected function afterSave(): void
    {
        parent::afterSave();

        $this->record->forceFill(['is_default' => $this->pulledIsDefault])->save();
    }
}
