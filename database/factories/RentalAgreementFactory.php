<?php

namespace Database\Factories;

use App\Enums\AgreementStatus;
use App\Models\BillingAccount;
use App\Models\RentalAgreement;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RentalAgreement>
 */
class RentalAgreementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_account_id' => BillingAccount::factory(),
            'unit_id' => Unit::factory(),
            'bed_id' => null,
            'due_day' => 5,
            'cycle_start_day' => 1,
            'issue_lead_days' => 7,
            'prorate' => false,
            'start_date' => '2026-01-01',
            'end_date' => null,
            'status' => AgreementStatus::Active,
        ];
    }
}
