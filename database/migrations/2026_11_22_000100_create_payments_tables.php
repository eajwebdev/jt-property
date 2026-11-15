<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_account_id')->constrained();
            $table->foreignId('property_id')->constrained();
            $table->bigInteger('amount_cents');
            $table->date('paid_on');
            $table->string('method'); // cash | bank_transfer | gcash | maya | check | other
            $table->string('reference')->nullable();
            $table->string('payer_name')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users');
            $table->string('status')->default('pending'); // pending | approved | reversed
            $table->string('proof_path')->nullable(); // private disk
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('ack_number')->nullable()->unique();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->dateTime('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->timestamps();
            // NULL references never collide, so this is "unique per method when a reference is given".
            $table->unique(['method', 'reference']);
            $table->index(['billing_account_id', 'status']);
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_positive CHECK (amount_cents > 0)');

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('ledger_entry_id')->constrained('ledger_entries');
            $table->bigInteger('amount_cents');
            $table->dateTime('reversed_at')->nullable(); // set when the payment is reversed; rows are never deleted
            $table->timestamps();
            $table->index('ledger_entry_id');
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_positive CHECK (amount_cents > 0)');

        Schema::create('payment_counters', function (Blueprint $table) {
            $table->foreignId('property_id')->constrained();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_seq')->default(0);
            $table->primary(['property_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_counters');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
