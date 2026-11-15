<?php

namespace App\Services\Billing;

use App\Models\Property;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Per-property, per-month invoice numbers: {CODE}-{YYYYMM}-{seq 4}. Call inside the caller's transaction. */
class InvoiceNumberGenerator
{
    public function next(Property $p, CarbonInterface $month): string
    {
        $key = ['property_id' => $p->id, 'month' => $month->format('Y-m-01')];

        // One INSERT ... ON DUPLICATE KEY UPDATE creates or increments the row and takes its
        // exclusive lock in a single step, so concurrent callers serialise on the row until the
        // caller's transaction ends. (insertOrIgnore + SELECT FOR UPDATE would take a shared lock
        // on a duplicate first, and two callers upgrading S -> X deadlock.)
        DB::table('invoice_counters')->upsert([$key + ['last_seq' => 1]], ['property_id', 'month'], ['last_seq' => DB::raw('last_seq + 1')]);
        $seq = (int) DB::table('invoice_counters')->where($key)->lockForUpdate()->value('last_seq');

        return sprintf('%s-%s-%04d', $p->code, $month->format('Ym'), $seq);
    }
}
