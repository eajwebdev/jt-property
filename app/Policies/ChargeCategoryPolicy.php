<?php

namespace App\Policies;

use App\Models\User;

class ChargeCategoryPolicy
{
    /** Everyone signed in may open Charges; the controller scopes rows to their properties. Writes are owner-only. */
    public function viewAny(User $user): bool
    {
        return true;
    }
}
