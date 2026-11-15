<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_agreements', function (Blueprint $table) {
            // Set only by a transfer: the agreement this one replaced. One replacement per agreement.
            $table->foreignId('replaces_agreement_id')->nullable()->unique()->constrained('rental_agreements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_agreement_id');
        });
    }
};
