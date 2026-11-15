<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_account_id')->constrained();
            $table->foreignId('rental_agreement_id')->nullable()->constrained();
            $table->unsignedBigInteger('amount_cents');
            $table->date('received_on');
            $table->string('status')->default('held');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE security_deposits ADD CONSTRAINT security_deposits_positive CHECK (amount_cents > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_deposits');
    }
};
