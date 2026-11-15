<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rent_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_agreement_id')->constrained();
            $table->unsignedBigInteger('rent_cents');
            $table->date('effective_from');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['rental_agreement_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_rates');
    }
};
