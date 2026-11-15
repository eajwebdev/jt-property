<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Unique;

class UpdateUnitRequest extends StoreUnitRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('unit'));
    }

    protected function propertyId(): int
    {
        return $this->route('unit')->property_id;
    }

    protected function uniqueCode(): Unique
    {
        return parent::uniqueCode()->ignore($this->route('unit'));
    }
}
