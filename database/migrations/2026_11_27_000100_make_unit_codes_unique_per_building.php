<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room numbers repeat across the buildings of one property ("Room #2" in Creekside House and in BIND House),
 * so a code is unique per building, not per property. Rooms are shown as "Building - Code" (Unit::label).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // NULL buildings must still collide with each other, so compare on '' instead of NULL.
            $table->string('building_key')->virtualAs("COALESCE(building, '')")->after('building');
        });
        Schema::table('units', function (Blueprint $table) {
            // Add the new index first: it starts with property_id, so the foreign key keeps an index while the old one goes.
            $table->unique(['property_id', 'building_key', 'code'], 'units_property_building_code_unique');
            $table->dropUnique(['property_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unique(['property_id', 'code']);
            $table->dropUnique('units_property_building_code_unique');
        });
        Schema::table('units', fn (Blueprint $table) => $table->dropColumn('building_key'));
    }
};
