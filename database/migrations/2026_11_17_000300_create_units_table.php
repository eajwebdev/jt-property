<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained();
            $table->string('code');
            $table->string('building')->nullable();
            $table->string('floor')->nullable();
            $table->unsignedTinyInteger('capacity')->default(1);
            $table->string('rent_mode');
            $table->string('status');
            $table->text('description')->nullable();
            $table->text('amenities')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'code']);
        });

        DB::statement('ALTER TABLE units ADD CONSTRAINT units_capacity_min CHECK (capacity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
