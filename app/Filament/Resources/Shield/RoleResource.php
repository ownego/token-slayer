<?php

namespace App\Filament\Resources\Shield;

use App\Filament\Resources\Shield\Pages\CreateRole;
use App\Filament\Resources\Shield\Pages\EditRole;
use App\Filament\Resources\Shield\Pages\ListRoles;
use App\Filament\Resources\Shield\Pages\ViewRole;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as BaseRoleResource;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Schemas\Schema;

/**
 * Extends Shield's role resource to add an `is_default` toggle: a role
 * marked default is applied implicitly to every user (its permissions are
 * granted via a Gate::before hook — see
 * `App\Services\Roles\DefaultRolePermissions`; no per-user assignment row is
 * written). Registered on the admin panel in place of Shield's own resource
 * so the toggle appears in the create/edit form and Shield's
 * `FilamentShieldPlugin::register()` suppresses its duplicate.
 */
class RoleResource extends BaseRoleResource
{
    /**
     * Append the `is_default` toggle to Shield's role form. `components()`
     * replaces the schema's component list wholesale, so the existing
     * (resolved) components from Shield's form are re-passed alongside the
     * new toggle — see `Filament\Schemas\Concerns\HasComponents::components()`
     * / `getComponents()` in `vendor/filament/schemas`.
     *
     * @param  Schema  $schema  the schema being configured by Filament
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $schema->components([
            ...$schema->getComponents(),
            Toggle::make('is_default')
                ->label('Assign to every user by default')
                ->helperText('Every user (existing and new) receives this role. Keep its permissions view-only.'),
        ]);
    }

    /**
     * Route pages through our own subclasses instead of Shield's (which
     * hardcode `$resource` to Shield's own base resource) so `form()`
     * resolves to our override and `is_default` survives create/edit saves.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
