<?php

namespace Database\Seeders;

use App\Enums\DeliveryMethod;
use App\Enums\PropertyType;
use App\Enums\RentMode;
use App\Enums\Role;
use App\Enums\UnitStatus;
use App\Models\BillingAccount;
use App\Models\ChargeCategory;
use App\Models\OneTimeCharge;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\User;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\OpeningBalanceService;
use App\Services\Billing\PaymentService;
use App\Services\TenancyService;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mubarak Boarding House exactly as "2026 Statement of Accounts (October).xlsx" (as of 5 October 2026): its 61
 * "Current Boarders" rows, in the same order, with the same room, rate, amounts and remark, so the app's printed
 * Statement of Accounts as of 5 October 2026 is the same sheet. The system starts in October 2026:
 *
 *  - Before October ("previous unpaid", entered once, never billed): every BH month before October and EB that
 *    spans several months -> one approved opening balance per account,
 *    dated 30 Sep 2026, with BH / EB lines per month (the same thing an owner enters in Opening Balances).
 *  - October = the first bills the system makes: October BH (monthly rate) + the September EB shown on the paper,
 *    as a one-time "Electricity - September 2026" charge. Boarders the paper does not list for October BH already
 *    paid it: a payment ("paid before the system started") settles their October bill.
 *  - From November the system bills as usual; anything still unpaid is the "previous balance" on the next bill.
 *  - Free stay (student assistant, rate 0): a ₱0 rental in the room, so the boarder is listed; pays no electricity.
 *  - Each account keeps the sheet's remark word for word for the 5 October 2026 sheet (later sheets write their own).
 *  - Old accounts of previous boarders are not on this sheet: see MubarakPreviousBoardersSeeder (optional).
 *
 * Source data: database/seeders/data/mubarak-statement-2026-10.json (parsed from the spreadsheet).
 *
 * Assumptions the sheet does not state (all editable in the app afterwards):
 *  - rentals start 1 January 2026 and are due on the 5th (the sheet is "as of October 05");
 *  - rooms rented at ₱1,000 or more are whole rooms, the rest are bedspaces with one bed per boarder;
 *  - boarders without "(no deposit)" paid a one-month deposit equal to their monthly rate;
 *  - electricity is one bill per room, split equally between its paying boarders (enter it monthly); water is free.
 */
class MubarakBoardingHouseSeeder extends Seeder
{
    public const CODE = 'MBH';

    /** Opening balances: everything owed before the first system month. */
    private const AS_OF = '2026-09-30';

    private const FIRST_MONTH = '2026-10';

    private const RENTAL_START = '2026-01-01';

    /** The date the paper sheet is "as of". */
    public const SHEET_AS_OF = '2026-10-05';

    private const DUE_DAY = 5;

    public function run(TenancyService $tenancy, OpeningBalanceService $openings, InvoiceGenerationService $billing, PaymentService $payments): void
    {
        if (Property::where('code', self::CODE)->exists()) {
            return; // safe to run twice
        }
        $owner = User::where('role', Role::Owner)->orderBy('id')->first()
            ?? throw new RuntimeException('Create the owner user before seeding Mubarak Boarding House.');

        try {
            Auth::setUser($owner);
            DB::transaction(fn () => $this->seed($tenancy, $openings, $billing, $payments, $owner));
        } finally {
            Auth::forgetGuards();
        }
    }

    private function seed(TenancyService $tenancy, OpeningBalanceService $openings, InvoiceGenerationService $billing, PaymentService $payments, User $owner): void
    {
        $rows = collect(json_decode((string) file_get_contents(database_path('seeders/data/mubarak-statement-2026-10.json')), true, flags: JSON_THROW_ON_ERROR));
        $start = Carbon::parse(self::RENTAL_START);
        $asOf = Carbon::parse(self::AS_OF);
        $first = Carbon::parse(self::FIRST_MONTH.'-01');
        $eb = $first->copy()->subMonth()->format('Y-m'); // the October bill carries September's electricity

        Settings::set('company_name', 'Mubarak Boarding House', $owner);
        Settings::set('company_address', 'Purok CPSU Uno, Barangay Camingawan, Kabankalan City', $owner);
        Settings::set('soa_prepared_by', 'CHRISTIAN G. TAGUINES', $owner);
        Settings::set('soa_prepared_by_title', 'Owner', $owner);

        $property = Property::create([
            'name' => 'Mubarak Boarding House',
            'code' => self::CODE,
            'type' => PropertyType::BoardingHouse,
            'address' => 'Purok CPSU Uno, Barangay Camingawan, Kabankalan City',
            'dti_registration_no' => '1366594',
            'description' => "Creekside House, BIND House, Jerry's House (Main and Annex) and Main House.",
        ]);

        $current = $rows->where('section', 'current')->values();
        $electricity = ChargeCategory::where('name', 'Electricity')->value('id');

        $units = $this->units($property, $current);
        $nextBed = [];
        $accounts = []; // name => [agreement, row, opening lines, October BH still owed]

        // 1. Rooms, boarders, rentals, deposits, and the September EB to put on the October bill.
        foreach ($current as $row) {
            $unit = $units[$row['building'].'|'.$row['room']];
            $tenant = $this->tenant($property, $row);
            if ($row['rate_cents'] <= 0) {
                // Free stay (e.g. student assistant): a ₱0 rental keeps them on the sheet and in their bed.
                $tenant->update(['remarks' => trim('Free stay (no rent, no electricity share). '.$row['remark'])]);
            }

            $bed = null;
            if ($unit->rent_mode === RentMode::PerBed) {
                $index = $nextBed[$unit->id] = ($nextBed[$unit->id] ?? -1) + 1;
                $bed = $unit->beds()->orderBy('id')->skip($index)->first();
            }
            $agreement = $tenancy->startAgreement($tenant, $unit, $bed, $row['rate_cents'], self::DUE_DAY, $start);
            // The sheet's remark, word for word, for the 5 October 2026 sheet.
            $agreement->billingAccount->update(['soa_remarks' => $row['remark'], 'soa_remarks_as_of' => self::SHEET_AS_OF]);
            if (! $row['no_deposit'] && $row['rate_cents'] > 0) {
                $tenancy->recordDeposit($agreement->billingAccount, $row['rate_cents'], $start, $agreement, 'Assumed one-month deposit (Oct 2026 Statement of Accounts lists no "(no deposit)" for this boarder).');
            }

            $opening = [];
            $octoberRent = 0;
            foreach ($row['breakdown'] as $line) {
                $single = ($line['from'] ?? $line['month']) === $line['month'];
                if ($line['category'] === 'rent' && $line['month'] === self::FIRST_MONTH) {
                    $octoberRent += $line['amount_cents']; // billed by the system as the October rent
                } elseif ($line['category'] === 'electric' && $single && $line['month'] === $eb && ! $line['partial']) {
                    OneTimeCharge::create([
                        'billing_account_id' => $agreement->billing_account_id, 'rental_agreement_id' => $agreement->id,
                        'charge_category_id' => $electricity, 'label' => 'Electricity – '.Carbon::parse($eb.'-01')->format('F Y'),
                        'kind' => 'charge', 'amount_cents' => $line['amount_cents'], 'bill_month' => $first->toDateString(), 'created_by' => $owner->id,
                    ]);
                } else {
                    $opening[] = $line; // before October, or an EB amount that spans several months
                }
            }
            $accounts[$row['name']] = [$agreement, $row, $opening, $octoberRent];
        }

        // 2. October 2026: the first bills made by the system (rent + the September electricity above).
        $billing->generate($first->year, $first->month, $owner, $property->id);

        // 3. October BH the paper does not list as unpaid was paid before the system started. Recorded before the
        //    opening balances exist, so the payment settles the October bill and not an older month.
        foreach ($accounts as [$agreement, $row, , $octoberRent]) {
            $paid = $row['rate_cents'] - $octoberRent;
            if ($paid > 0) {
                $payments->record($agreement->billingAccount, [
                    'amount_cents' => $paid, 'paid_on' => $first->toDateString(), 'method' => 'other',
                    'notes' => 'October 2026 rent paid before the system started (not unpaid on the 5 Oct 2026 Statement of Accounts).',
                ], $owner);
            }
        }

        // 4. Previous unpaid (before October): approved opening balances with BH / EB lines.
        foreach ($accounts as [$agreement, $row, $opening]) {
            $this->opening($openings, $agreement->billingAccount, $opening, $asOf, $row['remark']);
        }

        // 5. From November each room's electric bill is entered monthly and split equally between its boarders.
        foreach ($units as $unit) {
            UnitUtility::create(['unit_id' => $unit->id, 'type' => 'electricity', 'mode' => 'shared', 'split_method' => 'equal']);
        }
    }

    /** @return array<string, Unit> keyed "Building|Room #N" */
    private function units(Property $property, Collection $current): array
    {
        $units = [];
        foreach ($current->groupBy(fn ($r) => $r['building'].'|'.$r['room']) as $key => $boarders) {
            $whole = $boarders->max('rate_cents') >= 100000;
            $first = $boarders->first();
            $unit = $property->units()->create([
                'building' => $first['building'],
                'code' => $first['room'],
                'capacity' => $boarders->count(),
                'rent_mode' => $whole ? RentMode::Whole : RentMode::PerBed,
                'status' => UnitStatus::Available,
            ]);
            if (! $whole) {
                foreach (range(1, $boarders->count()) as $n) {
                    $unit->beds()->create(['label' => "Bed $n"]);
                }
            }
            $units[$key] = $unit;
        }

        return $units;
    }

    public function tenant(Property $property, array $row): Tenant
    {
        return $property->tenants()->create([
            'full_name' => $row['name'],
            'preferred_delivery' => DeliveryMethod::Print,
            'email_opt_in' => false,
            'remarks' => 'Imported from the October 2026 Statement of Accounts.',
        ]);
    }

    /** One approved opening balance per account; each breakdown line keeps its category (rent/electric) and months. */
    public function opening(OpeningBalanceService $openings, BillingAccount $account, array $breakdown, Carbon $asOf, string $notes): void
    {
        $total = array_sum(array_column($breakdown, 'amount_cents'));
        if ($total <= 0) {
            return;
        }
        // e.g. "BH 2026-09 (balance)" or "EB 2026-05 to 2026-09", shown in the opening-balance breakdown.
        $lines = array_map(function (array $l) {
            $from = $l['from'] ?? $l['month'];
            $period = $from === $l['month'] ? $l['month'] : "{$from} to {$l['month']}";

            return $l + ['note' => ($l['category'] === 'rent' ? 'BH ' : 'EB ').$period.(($l['partial'] ?? false) ? ' (balance)' : '')];
        }, $breakdown);
        $openings->approve($openings->draft($account, $total, $asOf, array_values($lines), $notes, 'Balance per October 2026 Statement of Accounts'));
    }
}
