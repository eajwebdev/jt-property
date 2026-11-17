<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\BillingAccount;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\OpeningBalanceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OPTIONAL: 11 previous boarders with unpaid accounts (BH 6,461.15 + EB 781.56 = 7,242.71) from an earlier version
 * of the October 2026 sheet. The current "2026 Statement of Accounts (October).xlsx" does not list them, so the main
 * seed leaves them out. Run only if these debts are still to be collected:
 *     php artisan db:seed --class=MubarakPreviousBoardersSeeder --force
 * Each gets an archived tenant and an account with the old balance as an approved opening balance. They are not on
 * the printed sheet (it lists current boarders) but show in Payments / Collections. Safe to run twice.
 */
class MubarakPreviousBoardersSeeder extends Seeder
{
    public function run(OpeningBalanceService $openings): void
    {
        $property = Property::where('code', MubarakBoardingHouseSeeder::CODE)->first()
            ?? throw new RuntimeException('Run MubarakBoardingHouseSeeder first.');
        $owner = User::where('role', Role::Owner)->orderBy('id')->firstOrFail();
        $rows = json_decode((string) file_get_contents(database_path('seeders/data/mubarak-previous-boarders-2026-10.json')), true, flags: JSON_THROW_ON_ERROR);
        $main = app(MubarakBoardingHouseSeeder::class);
        $asOf = Carbon::parse('2026-09-30');

        try {
            Auth::setUser($owner);
            DB::transaction(function () use ($rows, $property, $main, $openings, $asOf) {
                foreach ($rows as $row) {
                    $note = $row['remark'] ?: 'Previous boarder with an unpaid account.';
                    // Already added (safe to run twice)?
                    if (BillingAccount::where('property_id', $property->id)->whereDoesntHave('agreements')
                        ->whereHas('payer', fn ($q) => $q->where('full_name', $row['name']))->exists()) {
                        continue;
                    }
                    // Still boarding (SHANGCO)? Same person, separate account for the old stay.
                    $tenant = Tenant::where('property_id', $property->id)->where('full_name', $row['name'])->first();
                    if ($tenant === null) {
                        $tenant = $main->tenant($property, $row);
                        $tenant->update(['archived_at' => $asOf]);
                    }
                    $account = BillingAccount::create(['property_id' => $property->id, 'payer_tenant_id' => $tenant->id]);
                    $main->opening($openings, $account, $row['breakdown'], $asOf, $note);
                }
            });
        } finally {
            Auth::forgetGuards();
        }
    }
}
