<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    /** Everyone signed in may open the list; the controller scopes it to what they can access. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->canAccessProperty($tenant->property_id);
    }

    /** Owners anywhere, staff only in an assigned property. Pass the property id when it is known. */
    public function create(User $user, ?int $propertyId = null): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return $user->role === Role::Staff && ($propertyId === null || $user->canAccessProperty($propertyId));
    }

    /** Archived tenants are read-only. */
    public function update(User $user, Tenant $tenant): bool
    {
        return $tenant->archived_at === null && $this->create($user, $tenant->property_id);
    }

    public function archive(User $user, Tenant $tenant): bool
    {
        return $this->update($user, $tenant);
    }
}
