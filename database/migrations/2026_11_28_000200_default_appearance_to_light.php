<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Light is the default look. Users who never picked one ("system") start in light; a saved dark choice is kept. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('appearance')->default('light')->change();
        });
        DB::table('users')->where('appearance', 'system')->update(['appearance' => 'light']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('appearance')->default('system')->change();
        });
    }
};
