<?php

namespace App\Services;

use App\Models\User;

class RoleService
{
    public function canManageRooms(User $user): bool
    {
        return in_array($user->role, [User::ROLE_ADMIN, User::ROLE_STAFF_MASTER_EXAMINER], true);
    }

    public function isAdmin(User $user): bool
    {
        return $user->role === User::ROLE_ADMIN;
    }
}

