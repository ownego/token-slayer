<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomMinion;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Who may see and take down players' custom minions. A player can always remove
 * their own; taking down someone else's needs the `Delete:CustomMinion`
 * permission, and the admin list needs `ViewAny:CustomMinion`. These are
 * minion-specific: the fighter's `*:CustomCharacter` permissions grant nothing here.
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
        return $authUser->can('ViewAny:CustomMinion');
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
        return $authUser->getAuthIdentifier() === $customMinion->user_id || $authUser->can('Delete:CustomMinion');
    }
}
