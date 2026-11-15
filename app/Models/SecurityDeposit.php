<?php

namespace App\Models;

use App\Enums\DepositStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityDeposit extends Model
{
    use HasFactory;

    protected $fillable = ['billing_account_id', 'rental_agreement_id', 'amount_cents', 'received_on', 'status', 'notes'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'received_on' => 'date',
            'status' => DepositStatus::class,
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function rentalAgreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class, 'rental_agreement_id');
    }
}
