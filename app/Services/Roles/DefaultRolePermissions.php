<?php

namespace App\Services\Roles;

use Spatie\Permission\Models\Role;

/**
 * Resolves the permissions granted implicitly to every user by the roles
 * flagged `is_default`. Default roles are virtual: no `model_has_roles` row is
 * written per user — instead a `Gate::before` hook consults this service so
 * every user carries the default roles' permissions, and any user reaches the
 * panel while a default role exists. Bound as a singleton so the lookups run
 * at most once per request.
 */
final class DefaultRolePermissions
{
    /**
     * Memoised set of permission names granted by the default roles.
     *
     * @var array<int, string>|null
     */
    private ?array $names = null;

    /**
     * Memoised flag: whether any role is currently flagged default.
     *
     * @var bool|null
     */
    private ?bool $hasDefault = null;

    /**
     * The distinct permission names granted by every `is_default` role
     * (guard `web`), memoised for the request.
     *
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        return $this->names ??= Role::query()
            ->where('guard_name', 'web')
            ->where('is_default', true)
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role): array => $role->permissions->pluck('name')->all())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether the given permission is granted by any default role.
     *
     * @param  string  $permission  the permission name to check
     * @return bool
     */
    public function grants(string $permission): bool
    {
        return in_array($permission, $this->permissionNames(), true);
    }

    /**
     * Whether at least one role is currently flagged default (guard `web`).
     *
     * @return bool
     */
    public function hasAnyDefaultRole(): bool
    {
        return $this->hasDefault ??= Role::query()
            ->where('guard_name', 'web')
            ->where('is_default', true)
            ->exists();
    }
}
