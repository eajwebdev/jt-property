<?php

namespace App\Http\Requests;

use App\Models\RentalAgreement;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StoreAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RentalAgreement::class);
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;

        $this->merge([
            'rent' => $clean($this->rent),
            'deposit' => $clean($this->deposit),
            'end_date' => $clean($this->end_date),
            'override_reason' => $clean($this->override_reason),
            'resident_tenant_ids' => $this->resident_tenant_ids ?? [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'bed_id' => ['nullable', 'integer', Rule::exists('beds', 'id')],
            'rent' => ['required', 'string', $this->pesos('rent')],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'cycle_start_day' => ['required', 'integer', 'between:1,28'],
            'issue_lead_days' => ['required', 'integer', 'between:0,31'],
            'prorate' => ['required', 'boolean'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'resident_tenant_ids' => ['array'],
            'resident_tenant_ids.*' => ['integer', 'distinct'],
            'deposit' => ['nullable', 'string', $this->pesos('deposit')],
            'override_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The end date cannot be before the start date.',
            'override_reason.min' => 'Explain the override in at least 10 characters.',
        ];
    }

    public function rentCents(): int
    {
        return Money::toCents($this->validated('rent'));
    }

    public function depositCents(): ?int
    {
        $deposit = $this->validated('deposit');

        return $deposit === null ? null : Money::toCents($deposit);
    }

    /** A peso amount above zero, written like 2500, 2,500 or 2,500.50. */
    private function pesos(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label) {
            try {
                $cents = Money::toCents((string) $value);
            } catch (InvalidArgumentException) {
                $cents = null;
            }

            if ($cents === null || $cents <= 0) {
                $fail("Enter the {$label} in pesos above zero, like 2500 or 2,500.00.");
            }
        };
    }
}
