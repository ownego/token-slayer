<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomCharacter;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Who may see and take down players' custom fighters. A player can always
 * remove their own; taking down someone else's (a fighter that should not be
 * shown to the team) needs the `Delete:CustomCharacter` permission, and the
 * admin list needs `ViewAny:CustomCharacter`.
 */
class CustomCharacterPolicy
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
     * Checks whether an admin may rename a player's custom fighter.
     *
     * @param  AuthUser  $authUser
     * @param  CustomCharacter  $customCharacter
     * @return bool
     */
    public function update(AuthUser $authUser, CustomCharacter $customCharacter): bool
    {
        return $authUser->can('Update:CustomCharacter');
    }

    /**
     * Checks whether the owner or a moderator may remove this fighter.
     *
     * @param  AuthUser  $authUser
     * @param  CustomCharacter  $customCharacter
     * @return bool
     */
    public function delete(AuthUser $authUser, CustomCharacter $customCharacter): bool
    {
        return $authUser->getAuthIdentifier() === $customCharacter->user_id || $authUser->can('Delete:CustomCharacter');
    }
}
