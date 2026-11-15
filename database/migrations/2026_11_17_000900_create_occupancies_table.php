<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occupancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->foreignId('bed_id')->nullable()->constrained();
            $table->foreignId('rental_agreement_id')->nullable()->constrained();
            $table->boolean('is_payer')->default(false);
            $table->date('move_in_date');
            $table->date('move_out_date')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE occupancies ADD CONSTRAINT occupancies_dates CHECK (move_out_date IS NULL OR move_out_date >= move_in_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('occupancies');
    }
};
