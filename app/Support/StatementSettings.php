<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * The few settings a statement prints. Kept in one place so the source can be swapped:
 * reads App\Support\Settings when it exists and the table is there, otherwise plain defaults.
 */
final class StatementSettings
{
    /** @return array{company_name:string,company_address:string,company_phone:string,company_email:string,payment_instructions:list<array<string,mixed>>,statement_notes:string} */
    public static function get(): array
    {
        $defaults = [
            'company_name' => 'Mubarak Boardinghouse',
            'company_address' => '',
            'company_phone' => '',
            'company_email' => '',
            'payment_instructions' => [],
            'statement_notes' => '',
        ];

        if (! class_exists(Settings::class) || ! Schema::hasTable('app_settings')) {
            return $defaults;
        }

        $out = [];
        foreach ($defaults as $key => $default) {
            $value = Settings::get($key, $default);
            $out[$key] = $value ?? $default;
        }

        return $out;
    }
}
