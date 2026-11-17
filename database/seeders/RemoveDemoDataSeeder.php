<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RemoveDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $properties = Property::whereIn('code', ['RIV', 'HIL'])->lockForUpdate()->get();
            if ($properties->isEmpty()) {
                $this->removeDemoStaff([]);

                return;
            }

            $expectedProperties = ['HIL' => 'Hillside Demo Apartments', 'RIV' => 'Riverside Demo Residences'];
            if ($properties->pluck('name', 'code')->sortKeys()->all() !== $expectedProperties) {
                throw new RuntimeException('The sample properties have changed. Review them before removing demo data.');
            }

            $propertyIds = $properties->pluck('id')->all();
            $unitIds = DB::table('units')->whereIn('property_id', $propertyIds)->pluck('id')->all();
            $accountIds = DB::table('billing_accounts')->whereIn('property_id', $propertyIds)->pluck('id')->all();
            $agreementIds = DB::table('rental_agreements')->whereIn('billing_account_id', $accountIds)->pluck('id')->all();
            $tenantNames = DB::table('tenants')->whereIn('property_id', $propertyIds)->orderBy('full_name')->pluck('full_name')->all();
            $unitCodes = DB::table('units')->whereIn('property_id', $propertyIds)->orderBy('code')->pluck('code')->all();
            $openingBalances = DB::table('opening_balances')->whereIn('billing_account_id', $accountIds)->get();
            $ledgerEntries = DB::table('ledger_entries')->whereIn('billing_account_id', $accountIds)->get();

            if ($tenantNames !== ['Ana Demo', 'Ben Demo', 'Cara Demo', 'Dan Demo', 'Eli Demo', 'Lita Santos Demo', 'Maria Santos Demo', 'Pedro Santos Demo', 'Santos Family Demo']
                || $unitCodes !== ['A101', 'A102', 'A103', 'B201', 'B202']
                || count($accountIds) !== 6 || count($agreementIds) !== 6
                || $openingBalances->count() !== 1 || (int) $openingBalances->first()->amount_cents !== 180000 || $openingBalances->first()->status !== 'approved'
                || $ledgerEntries->count() !== 1 || $ledgerEntries->first()->type !== 'opening' || (int) $ledgerEntries->first()->amount_cents !== 180000
                || DB::table('invoices')->whereIn('property_id', $propertyIds)->exists()
                || DB::table('payments')->whereIn('property_id', $propertyIds)->exists()
                || DB::table('security_deposits')->whereIn('billing_account_id', $accountIds)->exists()
                || DB::table('one_time_charges')->whereIn('billing_account_id', $accountIds)->exists()
                || DB::table('billing_batches')->whereIn('property_id', $propertyIds)->exists()
                || DB::table('utility_allocations')->whereIn('rental_agreement_id', $agreementIds)->exists()
                || DB::table('utility_exclusions')->whereIn('rental_agreement_id', $agreementIds)->exists()
                || DB::table('utility_split_shares')->whereIn('rental_agreement_id', $agreementIds)->exists()
            ) {
                throw new RuntimeException('The sample data has changed or has financial activity. Review it before removal.');
            }

            DB::table('property_user')->whereIn('property_id', $propertyIds)->delete();
            DB::table('opening_balances')->whereIn('billing_account_id', $accountIds)->delete();
            DB::table('ledger_entries')->whereIn('billing_account_id', $accountIds)->delete();
            DB::table('recurring_charges')->whereIn('rental_agreement_id', $agreementIds)->delete();
            DB::table('utility_bills')->whereIn('unit_id', $unitIds)->delete();
            DB::table('unit_utilities')->whereIn('unit_id', $unitIds)->delete();
            DB::table('rent_rates')->whereIn('rental_agreement_id', $agreementIds)->delete();
            DB::table('occupancies')->whereIn('rental_agreement_id', $agreementIds)->delete();
            DB::table('rental_agreements')->whereIn('id', $agreementIds)->delete();
            DB::table('beds')->whereIn('unit_id', $unitIds)->delete();
            DB::table('billing_accounts')->whereIn('id', $accountIds)->delete();
            DB::table('tenants')->whereIn('property_id', $propertyIds)->delete();
            DB::table('units')->whereIn('id', $unitIds)->delete();
            Property::whereIn('id', $propertyIds)->delete();
            $this->removeDemoStaff($propertyIds);
        });
    }

    /** @param  list<int>  $propertyIds */
    private function removeDemoStaff(array $propertyIds): void
    {
        $staff = User::where('email', 'staff@jt')->first();
        if ($staff === null) {
            return;
        }
        if ($staff->name !== 'Staff Demo' || $staff->role !== Role::Staff
            || DB::table('property_user')->where('user_id', $staff->id)->whereNotIn('property_id', $propertyIds)->exists()
            || DB::table('activity_logs')->where('user_id', $staff->id)->exists()) {
            throw new RuntimeException('The demo staff account has been used or changed. Review it before removal.');
        }

        DB::table('sessions')->where('user_id', $staff->id)->delete();
        $staff->delete();
    }
}
