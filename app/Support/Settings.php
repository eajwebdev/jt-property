<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Application settings (company, payment instructions, automation, reminders, late fees).
 * Money-like values are stored in centavos (fixed late fee) or basis points (percent late fee).
 */
class Settings
{
    public const DEFAULTS = [
        'company_name' => 'Mubarak Boardinghouse',
        'company_address' => '',
        'company_phone' => '',
        'company_email' => '',
        'payment_instructions' => [],
        'statement_notes' => '',
        'auto_send_email' => false,
        'auto_generate' => false,
        'generate_lead_days' => 7,
        'reminder_days_before' => 3,
        'reminder_on_due' => true,
        'reminder_days_after' => 3,
        'late_fee_policy' => 'none',
        'late_fee_value' => 0,
        'late_fee_grace_days' => 0,
        // Signature block of the printed Statement of Accounts ("Prepared by:").
        'soa_prepared_by' => '',
        'soa_prepared_by_title' => 'Owner',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** The app instance the cache belongs to (a fresh app, e.g. in tests, starts clean). */
    private static ?object $owner = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string, mixed> every known key, stored value or default. */
    public static function all(): array
    {
        if (self::$owner !== app()) {
            self::$owner = app();
            self::$cache = null;
        }

        return self::$cache ??= array_merge(
            self::DEFAULTS,
            AppSetting::whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all(),
        );
    }

    public static function set(string $key, mixed $value, User $by): void
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown setting: [{$key}]");
        }

        $old = self::get($key);
        $attributes = ['value' => $value, 'updated_by' => $by->id];

        try {
            $setting = AppSetting::firstOrNew(['key' => $key]);
            $setting->fill($attributes)->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent first save created the row; update it instead.
            $setting = AppSetting::where('key', $key)->firstOrFail();
            $setting->fill($attributes)->save();
        }
        self::forget();

        Audit::log('settings.updated', $setting, ['key' => $key, 'old' => $old, 'new' => $value]);
    }

    /** Clear the in-request cache (also used by tests). */
    public static function forget(): void
    {
        self::$cache = null;
    }
}
