<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('property_id')->nullable()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->string('status')->default('running');
            $table->json('counts')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE billing_batches ADD CONSTRAINT billing_batches_month CHECK (month BETWEEN 1 AND 12)');
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_batches');
    }
};
