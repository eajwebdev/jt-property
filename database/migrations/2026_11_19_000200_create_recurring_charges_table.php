<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_agreement_id')->constrained();
            $table->foreignId('charge_category_id')->constrained();
            $table->string('label');
            $table->bigInteger('amount_cents');
            $table->string('frequency')->default('monthly');
            $table->date('start_date');
            // Inclusive last day; ending a charge sets this, rows are never deleted.
            $table->date('end_date')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['rental_agreement_id', 'start_date']);
        });

        DB::statement("ALTER TABLE recurring_charges ADD CONSTRAINT recurring_charges_valid CHECK (amount_cents > 0 AND frequency = 'monthly' AND (end_date IS NULL OR end_date >= start_date))");
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_charges');
    }
};
