<?php

namespace App\Http\Requests;

class UpdateTenantRequest extends StoreTenantRequest
{
    public function authorize(): bool
    {
        // Must be allowed on the tenant's current property AND the property it is saved to.
        $user = $this->user();
        $tenant = $this->route('tenant');

        return $user->can('update', $tenant) && $user->can('create', [$tenant::class, $this->integer('property_id') ?: null]);
    }
}
