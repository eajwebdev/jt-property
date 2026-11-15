<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdatePropertyRequest extends StorePropertyRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('property'));
    }

    public function rules(): array
    {
        $property = $this->route('property');

        return array_merge(parent::rules(), [
            'code' => [
                'required',
                'regex:/^[A-Z0-9]{2,10}$/',
                Rule::unique('properties', 'code')->ignore($property),
                function (string $attribute, mixed $value, \Closure $fail) use ($property) {
                    if ($value !== $property->code && $property->billingAccounts()->exists()) {
                        $fail('This code is already used on invoices, so it can no longer be changed.');
                    }
                },
            ],
        ]);
    }
}
