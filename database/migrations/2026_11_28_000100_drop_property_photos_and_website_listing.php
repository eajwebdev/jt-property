<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The public website and property photos were removed; the login page is now the first page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('property_photos');

        if (Schema::hasColumn('properties', 'show_on_website')) {
            Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('show_on_website'));
        }
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('show_on_website')->default(false)->after('auto_send_email');
        });

        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['property_id', 'sort']);
        });
    }
};
