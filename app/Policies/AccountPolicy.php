<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Account');
    }

    public function view(AuthUser $authUser, Account $account): bool
    {
        return $authUser->can('View:Account');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Account');
    }

    public function update(AuthUser $authUser, Account $account): bool
    {
        return $authUser->can('Update:Account');
    }

    public function delete(AuthUser $authUser, Account $account): bool
    {
        return $authUser->can('Delete:Account');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Account');
    }

    public function restore(AuthUser $authUser, Account $account): bool
    {
        return $authUser->can('Restore:Account');
    }

    public function forceDelete(AuthUser $authUser, Account $account): bool
    {
        return $authUser->can('ForceDelete:Account');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Account');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Account');
    }

    public function replicate(AuthUser $authUser, Account $account): bool
    {
        return $authUser->can('Replicate:Account');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Account');
    }
}
