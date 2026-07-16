<?php

namespace App\Filament\Resources\Shield\Pages;

use App\Filament\Resources\Shield\Concerns\PreservesIsDefault;
use App\Filament\Resources\Shield\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as BaseCreateRole;

/**
 * Re-points Shield's create page at our `RoleResource` subclass so the
 * `is_default` toggle actually renders (Shield's own page hardcodes
 * `$resource` to its own base resource — see `ListRoles` in this namespace)
 * and survives the save.
 */
class CreateRole extends BaseCreateRole
{
    use PreservesIsDefault;

    /**
     * The resource this page belongs to.
     *
     * @var class-string<RoleResource>
     */
    protected static string $resource = RoleResource::class;

    /**
     * Preserves `is_default` across Shield's permission-name collection
     * logic — see `PreservesIsDefault` for why this pull/restore is needed.
     * `CreateRecord::handleRecordCreation()` constructs the model directly
     * (`new Role($data)`), so `is_default` reaches the record via the
     * constructor rather than mass-assignment.
     *
     * @param  array<string, mixed>  $data  the raw form state
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $isDefault = $this->pullIsDefault($data);

        $data = parent::mutateFormDataBeforeCreate($data);

        return $this->restoreIsDefault($data, $isDefault);
    }
}
