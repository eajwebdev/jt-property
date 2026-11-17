<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The owner's rule: a room's electric bill is divided equally between the boarders in the room
     * (₱1,000 with 4 boarders = ₱250 each). Rooms set to "shared master bill, by days" switch to "equally".
     * Percent / fixed-amount splits someone set up on purpose are left alone.
     */
    public function up(): void
    {
        DB::table('unit_utilities')->where('type', 'electricity')->where('mode', 'shared')->where('split_method', 'days')
            ->update(['split_method' => 'equal', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Not reversed: which rooms were "by days" before is not recorded, and equal is the intended rule.
    }
};
