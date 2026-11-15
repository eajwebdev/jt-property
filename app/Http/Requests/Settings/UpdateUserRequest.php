<?php

namespace App\Http\Requests\Settings;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            /** @var User $target */
            $target = $this->route('user');

            if ($target->isOwner() && $target->isActive() && $this->input('role') !== Role::Owner->value
                && ! User::hasOtherActiveOwner($target)) {
                $validator->errors()->add('role', 'There must be at least one active owner.');
            }
        }];
    }
}
