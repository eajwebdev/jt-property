<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['method', 'reference']);
            // NULL once reversed, so a bounced check's reference can be entered again.
            $table->string('active_reference')->nullable()->virtualAs("IF(status = 'reversed', NULL, reference)")->after('reference');
            $table->unique(['method', 'active_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['method', 'active_reference']);
            $table->dropColumn('active_reference');
            $table->unique(['method', 'reference']);
        });
    }
};
