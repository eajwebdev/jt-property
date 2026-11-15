<?php

namespace App\Policies;

use App\Models\Occupancy;
use App\Models\User;

class OccupancyPolicy
{
    /** A resident's move-out changes the tenancy, so it is owner-only like the rest. */
    public function update(User $user, Occupancy $occupancy): bool
    {
        return $user->isOwner();
    }
}
