<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /** Owner and accountant see every bill; staff only in their assigned properties. */
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->canAccessProperty($invoice->property_id);
    }
}
