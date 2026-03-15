<?php

namespace App\Services;

use App\Models\User;

class RoleService
{
    public function canManageRooms(User $user): bool
    {
        return $this->isAdmin($user) || $this->isStaffMasterExaminer($user);
    }

    public function isAdmin(User $user): bool
    {
        return $user->role === User::ROLE_ADMIN;
    }

    public function isStaffMasterExaminer(User $user): bool
    {
        return in_array($user->role, [User::ROLE_STAFF_MASTER_EXAMINER, 'faculty'], true);
    }
}
