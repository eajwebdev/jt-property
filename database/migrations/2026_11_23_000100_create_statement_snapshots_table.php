<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained();
            $table->string('path');
            $table->char('sha256', 64);
            $table->json('totals');
            $table->dateTime('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_snapshots');
    }
};
