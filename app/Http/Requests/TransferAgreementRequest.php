<?php

namespace App\Http\Requests;

use App\Rules\Pesos;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('agreement'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['rent' => is_string($this->rent) && trim($this->rent) === '' ? null : $this->rent]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'bed_id' => ['nullable', 'integer', Rule::exists('beds', 'id')],
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            'rent' => ['nullable', 'string', new Pesos('new rent')],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** Null keeps the current rent. */
    public function rentCents(): ?int
    {
        $rent = $this->validated('rent');

        return $rent === null ? null : Money::toCents($rent);
    }
}
