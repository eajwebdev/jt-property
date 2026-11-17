<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When a payment's receipt was last emailed, and to which address. */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('receipt_emailed_at')->nullable()->after('ack_number');
            $table->string('receipt_emailed_to')->nullable()->after('receipt_emailed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['receipt_emailed_at', 'receipt_emailed_to']);
        });
    }
};
