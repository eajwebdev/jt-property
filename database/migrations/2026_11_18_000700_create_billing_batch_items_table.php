<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_batch_id')->constrained();
            $table->foreignId('rental_agreement_id')->constrained();
            $table->string('status');
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['billing_batch_id', 'rental_agreement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_batch_items');
    }
};
