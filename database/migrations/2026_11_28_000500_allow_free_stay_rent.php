<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A free stay (e.g. a student assistant) is a ₱0 rent: allowed. Negative rent is still impossible.
     * The rent form still asks for a rent above zero; free stays are set up by the owner on purpose.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE rent_rates DROP CONSTRAINT rent_rates_positive');
        DB::statement('ALTER TABLE rent_rates ADD CONSTRAINT rent_rates_positive CHECK (rent_cents >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rent_rates DROP CONSTRAINT rent_rates_positive');
        DB::statement('ALTER TABLE rent_rates ADD CONSTRAINT rent_rates_positive CHECK (rent_cents > 0)');
    }
};
