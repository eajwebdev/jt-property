<?php

namespace App\Http\Requests;

use App\Models\RentalAgreement;
use App\Rules\Pesos;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordDeposit', RentalAgreement::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['notes' => is_string($this->notes) && trim($this->notes) === '' ? null : $this->notes]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', new Pesos('deposit')],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'rental_agreement_id' => ['nullable', 'integer', Rule::exists('rental_agreements', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function amountCents(): int
    {
        return Money::toCents($this->validated('amount'));
    }
}
