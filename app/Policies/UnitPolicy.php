<?php

namespace App\Policies;

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
}
