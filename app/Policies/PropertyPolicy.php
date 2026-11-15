<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    /** Everyone signed in may open the list; the controller scopes it to what they can access. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Property $property): bool
    {
        return $user->canAccessProperty($property);
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Property $property): bool
    {
        return $user->isOwner();
    }
}
