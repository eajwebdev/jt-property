<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Property photos for the public listing pages, and the owner's switch to publish a property. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('path'); // on the "public" disk, served through PropertyPhotoController@show
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('sort')->default(0); // lowest sort is the cover photo
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['property_id', 'sort']);
        });

        Schema::table('properties', function (Blueprint $table) {
            // Off by default: nothing about a property is public until the owner turns this on.
            $table->boolean('show_on_website')->default(false)->after('auto_send_email');
        });
    }

    public function down(): void
    {
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('show_on_website'));
        Schema::dropIfExists('property_photos');
    }
};
