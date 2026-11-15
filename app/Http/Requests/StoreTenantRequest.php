<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMethod;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Tenant::class, $this->integer('property_id') ?: null]);
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $this->merge([
            'full_name' => is_string($this->full_name) ? trim($this->full_name) : $this->full_name,
            'email' => $clean($this->email),
            'mobile' => $clean($this->mobile),
            'emergency_contact_name' => $clean($this->emergency_contact_name),
            'emergency_contact_phone' => $clean($this->emergency_contact_phone),
            'remarks' => $clean($this->remarks),
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
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'Use a Philippine mobile number like 09171234567 or +639171234567.',
            'email.email' => 'Enter a valid email address, or leave it blank.',
        ];
    }
}
