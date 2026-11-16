<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Statement of Accounts (all boarders) report: business registration lines for the header, and the
 * move-in charge categories that get their own column (advance, deposit, door key deposit).
 */
return new class extends Migration
{
    private const CATEGORIES = ['One Month Advance', 'One Month Deposit', 'Door Key Deposit'];

    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('dti_registration_no')->nullable()->after('address');
            $table->string('business_permit_no')->nullable()->after('dti_registration_no');
        });

        foreach (self::CATEGORIES as $name) {
            DB::table('charge_categories')->insertOrIgnore(['name' => $name, 'kind' => 'other', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['dti_registration_no', 'business_permit_no']);
        });
    }
};
