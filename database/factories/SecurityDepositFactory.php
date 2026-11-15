<?php

namespace Database\Factories;

use App\Enums\DepositStatus;
use App\Models\BillingAccount;
use App\Models\SecurityDeposit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityDeposit>
 */
class SecurityDepositFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_account_id' => BillingAccount::factory(),
            'rental_agreement_id' => null,
            'amount_cents' => fake()->numberBetween(1, 10) * 100000,
            'received_on' => '2026-01-01',
            'status' => DepositStatus::Held,
        ];
    }
}
