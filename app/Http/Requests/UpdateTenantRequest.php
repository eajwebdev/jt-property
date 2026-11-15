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

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function ($validator) {
            $tenant = $this->route('tenant');

            // Rooms and accounts are tied to the property: after the first rental, moving property is a transfer.
            if ($this->integer('property_id') !== (int) $tenant->property_id && ($tenant->occupancies()->exists() || $tenant->billingAccounts()->exists())) {
                $validator->errors()->add('property_id', 'This tenant has rentals at their current property. Start a transfer instead.');
            }
        }];
    }
}
