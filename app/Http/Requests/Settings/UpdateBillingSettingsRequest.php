<?php

namespace App\Http\Requests\Settings;

use App\Models\AppSetting;
use App\Rules\Pesos;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class UpdateBillingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', AppSetting::class);
    }

    public function rules(): array
    {
        $percent = $this->input('late_fee_policy') === 'percent';

        return [
            'company_name' => ['required', 'string', 'max:150'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_email' => ['nullable', 'email', 'max:150'],
            'payment_instructions' => ['present', 'array', 'max:20'],
            'payment_instructions.*' => ['array:method,label,account_name,account_number,notes'],
            'payment_instructions.*.method' => ['required', Rule::in(['bank', 'gcash', 'maya', 'other'])],
            'payment_instructions.*.label' => ['required', 'string', 'max:100'],
            'payment_instructions.*.account_name' => ['required', 'string', 'max:150'],
            'payment_instructions.*.account_number' => ['required', 'string', 'max:100'],
            'payment_instructions.*.notes' => ['nullable', 'string', 'max:500'],
            'statement_notes' => ['nullable', 'string', 'max:2000'],
            'auto_send_email' => ['required', 'boolean'],
            'auto_generate' => ['required', 'boolean'],
            'generate_lead_days' => ['required', 'integer', 'between:0,31'],
            'reminder_days_before' => ['required', 'integer', 'between:0,31'],
            'reminder_on_due' => ['required', 'boolean'],
            'reminder_days_after' => ['required', 'integer', 'between:0,60'],
            'late_fee_policy' => ['required', Rule::in(['none', 'fixed', 'percent'])],
            'late_fee_value' => $percent
                ? ['bail', 'required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', function ($a, $v, $fail) {
                    try {
                        if (Money::toCents((string) $v) > 10000) {
                            $fail('The late fee percentage can be at most 100.');
                        }
                    } catch (InvalidArgumentException) {
                        $fail('Enter a percentage like 2 or 2.5, up to 100.');
                    }
                }]
                : ['nullable', new Pesos('late fee amount', allowZero: true)],
            'late_fee_grace_days' => ['required', 'integer', 'between:0,60'],
        ];
    }

    /** Reject keys we do not know about. */
    public function after(): array
    {
        return [function ($validator) {
            foreach (array_diff(array_keys($this->all()), array_keys(Settings::DEFAULTS)) as $key) {
                $validator->errors()->add($key, "{$key} is not a setting.");
            }
        }];
    }

    /** Validated values in storage units (centavos for a fixed fee, basis points for a percentage). */
    public function settings(): array
    {
        $data = $this->validated();
        $policy = $data['late_fee_policy'];
        $value = $data['late_fee_value'] ?? null;

        try {
            // A percentage like "2.5" parses to 250 "cents" = 250 basis points.
            $data['late_fee_value'] = $policy === 'none' || $value === null || $value === '' ? 0 : Money::toCents((string) $value);
        } catch (InvalidArgumentException) {
            $data['late_fee_value'] = 0;
        }

        $data['payment_instructions'] = array_map(fn ($r) => [
            'method' => $r['method'], 'label' => $r['label'], 'account_name' => $r['account_name'],
            'account_number' => $r['account_number'], 'notes' => $r['notes'] ?? '',
        ], array_values($data['payment_instructions']));

        foreach (['generate_lead_days', 'reminder_days_before', 'reminder_days_after', 'late_fee_grace_days'] as $k) {
            $data[$k] = (int) $data[$k];
        }
        foreach (['auto_send_email', 'auto_generate', 'reminder_on_due'] as $k) {
            $data[$k] = $this->boolean($k);
        }

        foreach (['company_address', 'company_phone', 'company_email', 'statement_notes'] as $k) {
            $data[$k] ??= '';
        }

        return $data;
    }
}
