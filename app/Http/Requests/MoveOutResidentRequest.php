<?php

namespace App\Http\Requests;

class MoveOutResidentRequest extends MoveOutRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('occupancy'));
    }
}
