<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_account_id')->constrained();
            $table->string('type');
            // + increases what the tenant owes, - decreases it. Balance = SUM(amount_cents).
            $table->bigInteger('amount_cents');
            $table->date('effective_date');
            $table->dateTime('posted_at')->useCurrent();
            $table->morphs('source');
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('ledger_entries');
            $table->text('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['billing_account_id', 'effective_date']);
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_nonzero CHECK (amount_cents <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
