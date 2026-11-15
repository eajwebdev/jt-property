<?php

namespace App\Http\Requests;

use App\Enums\RentMode;
use App\Enums\UnitStatus;
use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Unit::class, $this->route('property')]);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    protected function propertyId(): int
    {
        return $this->route('property')->id;
    }

    protected function uniqueCode(): Unique
    {
        return Rule::unique('units', 'code')->where('property_id', $this->propertyId());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', $this->uniqueCode()],
            'building' => ['nullable', 'string', 'max:100'],
            'floor' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'rent_mode' => ['required', Rule::enum(RentMode::class)],
            'status' => ['required', Rule::enum(UnitStatus::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'amenities' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This property already has a room with that code.',
            'capacity.min' => 'A room must fit at least 1 person.',
        ];
    }
}
