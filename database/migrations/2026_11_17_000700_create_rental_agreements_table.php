<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_account_id')->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->foreignId('bed_id')->nullable()->constrained();
            $table->unsignedTinyInteger('due_day');
            $table->unsignedTinyInteger('cycle_start_day')->default(1);
            $table->unsignedTinyInteger('issue_lead_days')->default(7);
            $table->boolean('prorate')->default(false);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            $table->text('override_reason')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE rental_agreements ADD CONSTRAINT rental_agreements_ranges CHECK (due_day BETWEEN 1 AND 31 AND cycle_start_day BETWEEN 1 AND 28 AND issue_lead_days BETWEEN 0 AND 31 AND (end_date IS NULL OR end_date >= start_date))');
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_agreements');
    }
};
