<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_utilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained();
            $table->string('type');
            $table->string('mode');
            $table->bigInteger('fixed_cents')->nullable();
            // Pesos per unit of consumption in ten-thousandths (11.5432 -> 115432).
            $table->unsignedBigInteger('rate_e4')->nullable();
            $table->bigInteger('base_charge_cents')->nullable();
            $table->string('split_method')->nullable();
            $table->timestamps();
            $table->unique(['unit_id', 'type']);
        });

        Schema::create('utility_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained();
            $table->string('type');
            // First day of the billing month.
            $table->date('bill_month');
            // Readings in thousandths (42.5 kWh -> 42500); null for a shared master bill.
            $table->bigInteger('previous_reading_e3')->nullable();
            $table->bigInteger('current_reading_e3')->nullable();
            $table->date('reading_date')->nullable();
            $table->unsignedBigInteger('rate_e4')->nullable();
            $table->bigInteger('base_charge_cents')->default(0);
            $table->bigInteger('amount_cents');
            $table->string('evidence_path')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('entered_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['unit_id', 'type', 'bill_month']);
        });

        Schema::create('utility_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_bill_id')->constrained();
            $table->foreignId('rental_agreement_id')->constrained();
            $table->bigInteger('amount_cents');
            $table->string('basis');
            $table->timestamps();
            $table->unique(['utility_bill_id', 'rental_agreement_id']);
        });

        Schema::create('utility_split_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_utility_id')->constrained();
            $table->foreignId('rental_agreement_id')->constrained();
            // Basis points (10000 = 100%) for the percent method; centavos for the manual method.
            $table->unsignedInteger('percent_bp')->nullable();
            $table->bigInteger('manual_cents')->nullable();
            $table->timestamps();
            $table->unique(['unit_utility_id', 'rental_agreement_id']);
        });

        Schema::create('utility_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_agreement_id')->constrained();
            $table->string('type');
            $table->date('bill_month');
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['rental_agreement_id', 'type', 'bill_month']);
        });

        DB::statement("ALTER TABLE unit_utilities ADD CONSTRAINT unit_utilities_valid CHECK (type IN ('electricity', 'water') AND mode IN ('inclusive', 'fixed', 'metered', 'shared') AND (split_method IS NULL OR split_method IN ('equal', 'days', 'percent', 'manual')))");
        DB::statement("ALTER TABLE utility_bills ADD CONSTRAINT utility_bills_valid CHECK (type IN ('electricity', 'water') AND status IN ('draft', 'billed') AND amount_cents >= 0)");
        DB::statement("ALTER TABLE utility_exclusions ADD CONSTRAINT utility_exclusions_valid CHECK (type IN ('electricity', 'water'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_exclusions');
        Schema::dropIfExists('utility_split_shares');
        Schema::dropIfExists('utility_allocations');
        Schema::dropIfExists('utility_bills');
        Schema::dropIfExists('unit_utilities');
    }
};
