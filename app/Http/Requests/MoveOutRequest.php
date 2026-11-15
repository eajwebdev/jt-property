<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('agreement'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'move_out_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
