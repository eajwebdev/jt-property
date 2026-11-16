<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occupancies', function (Blueprint $table) {
            $table->index(['unit_id', 'move_in_date']);
            $table->index(['tenant_id', 'move_in_date']);
        });

        Schema::table('rental_agreements', function (Blueprint $table) {
            $table->index(['unit_id', 'start_date']);
        });
    }

    public function down(): void
    {
        // The foreign keys now rely on these composite indexes, so give them their own first.
        Schema::table('occupancies', function (Blueprint $table) {
            $table->index('unit_id');
            $table->index('tenant_id');
            $table->dropIndex(['unit_id', 'move_in_date']);
            $table->dropIndex(['tenant_id', 'move_in_date']);
        });

        Schema::table('rental_agreements', function (Blueprint $table) {
            $table->index('unit_id');
            $table->dropIndex(['unit_id', 'start_date']);
        });
    }
};
