<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_account_id')->constrained();
            $table->foreignId('rental_agreement_id')->constrained();
            $table->foreignId('billing_batch_id')->nullable()->constrained();
            $table->foreignId('property_id')->constrained();
            $table->string('number')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status')->default('finalized');
            // Signed: credits can exceed charges.
            $table->bigInteger('current_charges_cents');
            $table->string('idempotency_key')->unique();
            $table->dateTime('finalized_at');
            $table->dateTime('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['billing_account_id', 'period_start']);
            $table->index(['property_id', 'period_start']);
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_valid CHECK (period_end >= period_start AND status IN ('finalized', 'void') AND (status = 'finalized' OR voided_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
