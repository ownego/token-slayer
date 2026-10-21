<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomMinion;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Who may see and take down players' custom minions. A player can always remove
 * their own; taking down someone else's needs the `Delete:CustomCharacter`
 * permission, and the admin list needs `ViewAny:CustomCharacter`. Minions share
 * the fighter's permissions: there is no minion-specific permission.
 */
class CustomMinionPolicy
{
    use HandlesAuthorization;

    /**
     * Checks whether the admin list is available to this user.
     *
     * @param  AuthUser  $authUser
     * @return bool
     */
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CustomCharacter');
    }

    /**
     * Checks whether the owner or a moderator may remove this minion.
     *
     * @param  AuthUser  $authUser
     * @param  CustomMinion  $customMinion
     * @return bool
     */
    public function delete(AuthUser $authUser, CustomMinion $customMinion): bool
    {
        return $authUser->getAuthIdentifier() === $customMinion->user_id || $authUser->can('Delete:CustomCharacter');
    }
}
