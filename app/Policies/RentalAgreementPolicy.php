<?php

namespace App\Policies;

use App\Models\RentalAgreement;
use App\Models\User;

class RentalAgreementPolicy
{
    /** Rents and agreements are owner-only. */
    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    /** Rent changes, transfers and move-outs are owner-only. */
    public function update(User $user, RentalAgreement $agreement): bool
    {
        return $user->isOwner();
    }

    /** Security deposits are owner-only. */
    public function recordDeposit(User $user): bool
    {
        return $user->isOwner();
    }
}
