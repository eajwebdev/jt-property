<?php

namespace App\Http\Requests;

use App\Rules\Pesos;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class ChangeRentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('agreement'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rent' => ['required', 'string', new Pesos('rent')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function rentCents(): int
    {
        return Money::toCents($this->validated('rent'));
    }
}
