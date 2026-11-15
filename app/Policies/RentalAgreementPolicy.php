<?php

namespace App\Policies;

use App\Models\User;

class RentalAgreementPolicy
{
    /** Rents and agreements are owner-only. */
    public function create(User $user): bool
    {
        return $user->isOwner();
    }
}
