<?php

namespace App\Policies;

use App\Models\OpeningBalance;
use App\Models\User;

class OpeningBalancePolicy
{
    /** Everyone signed in may look (the controller scopes staff to their properties). Writes are owner-only. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function approve(User $user, OpeningBalance $balance): bool
    {
        return $user->isOwner();
    }

    public function reverse(User $user, OpeningBalance $balance): bool
    {
        return $user->isOwner();
    }
}
