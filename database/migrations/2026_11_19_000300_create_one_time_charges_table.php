<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_time_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_account_id')->constrained();
            $table->foreignId('rental_agreement_id')->nullable()->constrained();
            $table->foreignId('charge_category_id')->constrained();
            $table->string('label');
            $table->string('kind');
            // Always positive; kind says whether it adds to or reduces the bill.
            $table->bigInteger('amount_cents');
            // First day of the billing month the charge is picked up in.
            $table->date('bill_month');
            // Set by invoice generation when consumed; a charge with an invoice is never billed again.
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['billing_account_id', 'bill_month']);
        });

        DB::statement("ALTER TABLE one_time_charges ADD CONSTRAINT one_time_charges_valid CHECK (amount_cents > 0 AND kind IN ('charge', 'credit'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('one_time_charges');
    }
};
