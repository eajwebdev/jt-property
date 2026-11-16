<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_account_id')->constrained();
            $table->bigInteger('amount_cents');
            $table->date('as_of');
            $table->string('description')->default('Unpaid balance brought forward before JT System');
            $table->json('breakdown')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending'); // pending | approved | reversed
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
            $table->dateTime('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
            // One live (pending or approved) opening balance per account; reversed rows free the slot (NULLs never collide).
            $table->unsignedBigInteger('active_key')->nullable()->virtualAs("IF(status = 'reversed', NULL, billing_account_id)")->unique();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE opening_balances ADD CONSTRAINT opening_balances_positive CHECK (amount_cents > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
