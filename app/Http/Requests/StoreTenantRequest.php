<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMethod;
use App\Models\RentalAgreement;
use App\Models\Tenant;
use App\Rules\Pesos;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    /** Adding the tenant needs tenant rights; also moving them into a room (starting a rental) is owner-only. */
    public function authorize(): bool
    {
        return $this->user()->can('create', [Tenant::class, $this->integer('property_id') ?: null])
            && (! $this->movesIn() || $this->user()->can('create', RentalAgreement::class));
    }

    /** A room was chosen: the rental starts together with the tenant. */
    public function movesIn(): bool
    {
        return filled($this->input('unit_id'));
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $id = fn ($v) => $v === '' || $v === null ? null : $v;

        $this->merge([
            'full_name' => is_string($this->full_name) ? trim($this->full_name) : $this->full_name,
            'email' => $clean($this->email),
            'mobile' => $clean($this->mobile),
            'emergency_contact_name' => $clean($this->emergency_contact_name),
            'emergency_contact_phone' => $clean($this->emergency_contact_phone),
            'remarks' => $clean($this->remarks),
            'unit_id' => $id($this->unit_id),
            'bed_id' => $id($this->bed_id),
            'rent' => $clean($this->rent),
            'deposit' => $clean($this->deposit),
            'start_date' => $clean($this->start_date),
            'due_day' => $id($this->due_day),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'integer', Rule::exists('properties', 'id')],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'mobile' => ['nullable', 'regex:/^(09|\+639)\d{9}$/'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'preferred_delivery' => ['required', Rule::enum(DeliveryMethod::class)],
            'email_opt_in' => ['required', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            // Optional move-in. TenancyService re-checks the room (same property, bed free, not archived) when saving.
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'bed_id' => ['nullable', 'integer', Rule::exists('beds', 'id')],
            'rent' => ['nullable', 'required_with:unit_id', 'string', new Pesos('rent')],
            'start_date' => ['nullable', 'required_with:unit_id', 'date_format:Y-m-d'],
            'due_day' => ['nullable', 'required_with:unit_id', 'integer', 'between:1,31'],
            'deposit' => ['nullable', 'string', new Pesos('deposit')],
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

    public function messages(): array
    {
        return [
            'rent.required_with' => 'Enter the monthly rent for this room.',
            'start_date.required_with' => 'Enter the move-in date.',
            'due_day.required_with' => 'Choose the rent due day.',
            'mobile.regex' => 'Use a Philippine mobile number like 09171234567 or +639171234567.',
            'email.email' => 'Enter a valid email address, or leave it blank.',
        ];
    }
}
