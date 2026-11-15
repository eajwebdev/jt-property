<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /** Everyone signed in may open the lists; controllers scope staff to their properties. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->canAccessProperty($payment->property_id);
    }

    /** Owners anywhere; staff only in an assigned property (their payments wait for approval). Accountants are read-only. */
    public function create(User $user, ?int $propertyId = null): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return $user->role === Role::Staff && ($propertyId === null || $user->canAccessProperty($propertyId));
    }

    public function approve(User $user, Payment $payment): bool
    {
        return $user->isOwner();
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $user->isOwner();
    }

    public function allocate(User $user, Payment $payment): bool
    {
        return $user->isOwner();
    }

    public function refund(User $user): bool
    {
        return $user->isOwner();
    }

    public function writeOff(User $user): bool
    {
        return $user->isOwner();
    }
}
