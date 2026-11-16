<?php

namespace App\Http\Requests;

use App\Enums\PropertyType;
use App\Enums\Role;
use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Property::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'regex:/^[A-Z0-9]{2,10}$/', 'unique:properties,code'],
            'type' => ['required', Rule::enum(PropertyType::class)],
            'address' => ['required', 'string', 'max:1000'],
            'dti_registration_no' => ['nullable', 'string', 'max:100'],
            'business_permit_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'amenities' => ['nullable', 'string', 'max:5000'],
            'auto_send_email' => ['sometimes', 'boolean'],
            'show_on_website' => ['sometimes', 'boolean'],
            'staff_ids' => ['sometimes', 'array'],
            'staff_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', Role::Staff->value)],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Use 2 to 10 capital letters or numbers, for example RIV or HOUSE1.',
            'code.unique' => 'Another property already uses this short code.',
            'staff_ids.*.exists' => 'Only staff members can be assigned to a property.',
        ];
    }
}
