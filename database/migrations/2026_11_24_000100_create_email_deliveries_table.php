<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('auto_send_email')->default(false);
        });

        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->string('to_email')->nullable();
            $table->string('status');
            $table->string('idempotency_key')->unique();
            $table->text('reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('auto_send_email');
        });
    }
};
