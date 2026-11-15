<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->unsignedSmallInteger('sort');
            $table->string('category');
            $table->string('label');
            $table->text('description')->nullable();
            $table->bigInteger('qty_e3')->nullable();
            $table->bigInteger('unit_rate_e4')->nullable();
            // Signed: credits are stored negative.
            $table->bigInteger('amount_cents');
            $table->nullableMorphs('source');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_items');
    }
};
