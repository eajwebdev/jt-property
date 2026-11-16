<?php

namespace App\Policies;

use App\Models\User;

class AppSettingPolicy
{
    /** Everyone signed in may see the company details and payment instructions. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return $user->isOwner();
    }
}
