<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULTS = [
        ['Rent', 'rent'], ['Rice cooker', 'appliance'], ['Refrigerator', 'appliance'], ['Aircon', 'appliance'],
        ['Electric fan', 'appliance'], ['Internet', 'service'], ['Parking', 'service'], ['Water', 'utility'],
        ['Electricity', 'utility'], ['Repair', 'service'], ['Cleaning', 'service'], ['Miscellaneous', 'other'],
    ];

    public function up(): void
    {
        Schema::create('charge_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('kind');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE charge_categories ADD CONSTRAINT charge_categories_kind CHECK (kind IN ('rent', 'appliance', 'utility', 'service', 'other'))");

        DB::table('charge_categories')->insert(array_map(
            fn ($c) => ['name' => $c[0], 'kind' => $c[1], 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            self::DEFAULTS,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_categories');
    }
};
