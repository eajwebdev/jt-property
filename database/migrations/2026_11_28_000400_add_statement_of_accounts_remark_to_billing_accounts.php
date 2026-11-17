<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The remark written on a paper Statement of Accounts (e.g. imported from the October 2026 sheet) and the date
     * that sheet is "as of". The printed sheet for that month shows it word for word; other months write their own.
     */
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->string('soa_remarks', 500)->nullable()->after('status');
            $table->date('soa_remarks_as_of')->nullable()->after('soa_remarks');
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn(['soa_remarks', 'soa_remarks_as_of']);
        });
    }
};
