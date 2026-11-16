<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function view(User $user, Unit $unit): bool
    {
        return $user->canAccessProperty($unit->property_id);
    }

    public function create(User $user, Property $property): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->isOwner();
    }

    /** Meter readings and master bills: the owner and staff assigned to the property. The accountant only reads. */
    public function enterUtilities(User $user, Unit $unit): bool
    {
        return ($user->isOwner() || $user->role === Role::Staff) && $user->canAccessProperty($unit->property_id);
    }
}
