<?php

namespace Tests\Feature\Billing;

use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\LedgerEntry;
use App\Models\OpeningBalance;
use App\Models\Property;
use App\Models\User;
use App\Services\Billing\OpeningBalanceService;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OpeningBalanceHttpTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private BillingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->property = Property::factory()->create();
        $this->account = BillingAccount::factory()->for($this->property)->create();
    }

    private function draftAs(User $user, ?BillingAccount $account = null, int $cents = 180000): OpeningBalance
    {
        $this->actingAs($user);

        return app(OpeningBalanceService::class)->draft($account ?? $this->account, $cents, Carbon::parse('2026-10-31'), null, null);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['billing_account_id' => $this->account->id, 'amount' => '1,800', 'as_of' => '2026-10-31'], $overrides);
    }

    public function test_owner_drafts_without_posting_anything(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->post(route('opening-balances.store'), $this->payload())->assertRedirect();

        $this->assertSame(180000, OpeningBalance::firstOrFail()->amount_cents);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_owner_can_draft_and_approve_in_one_step_with_a_breakdown(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->post(route('opening-balances.store'), $this->payload([
            'approve' => true,
            'breakdown' => [['month' => '2026-09', 'amount' => '1,000', 'note' => 'Rent'], ['month' => '2026-10', 'amount' => '800', 'note' => '']],
        ]))->assertRedirect();

        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Opening)->count());
        $this->assertSame(180000, app(ReceivableLedgerService::class)->balance($this->account));
        $this->assertSame(100000, OpeningBalance::first()->breakdown[0]['amount_cents']);
    }

    public function test_bad_input_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->post(route('opening-balances.store'), $this->payload(['amount' => '18,00']))->assertSessionHasErrors('amount');
        $this->post(route('opening-balances.store'), $this->payload(['as_of' => '2026-12-01']))->assertSessionHasErrors('as_of');
        $this->post(route('opening-balances.store'), $this->payload(['breakdown' => [['month' => '2026-10', 'amount' => '100']]]))->assertSessionHasErrors('breakdown');
        $this->assertSame(0, OpeningBalance::count());

        $this->post(route('opening-balances.store'), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('opening-balances.store'), $this->payload())->assertSessionHasErrors('billing_account_id');
    }

    public function test_owner_approves_a_pending_row_and_reverses_it(): void
    {
        $owner = User::factory()->owner()->create();
        $ob = $this->draftAs($owner);

        $this->post(route('opening-balances.approve', $ob))->assertRedirect();
        $this->post(route('opening-balances.approve', $ob))->assertRedirect();
        $this->assertSame(1, LedgerEntry::count());

        $this->post(route('opening-balances.reverse', $ob), [])->assertSessionHasErrors('reason');
        $this->post(route('opening-balances.reverse', $ob), ['reason' => 'Wrong account'])->assertRedirect();
        $this->assertSame('reversed', $ob->refresh()->status);
        $this->assertSame(0, app(ReceivableLedgerService::class)->balance($this->account));
    }

    public function test_staff_and_accountant_cannot_write(): void
    {
        $owner = User::factory()->owner()->create();
        $ob = $this->draftAs($owner);

        foreach ([User::factory()->create(), User::factory()->accountant()->create()] as $user) {
            $user->properties()->attach($this->property);
            $this->actingAs($user);
            $this->post(route('opening-balances.store'), $this->payload(['billing_account_id' => BillingAccount::factory()->for($this->property)->create()->id]))->assertForbidden();
            $this->post(route('opening-balances.approve', $ob))->assertForbidden();
            $this->post(route('opening-balances.reverse', $ob), ['reason' => 'x'])->assertForbidden();
            $this->post(route('opening-balances.import'), ['file' => UploadedFile::fake()->createWithContent('a.csv', "account_no,amount,as_of,description\n")])->assertForbidden();
        }

        $this->assertSame('pending', $ob->refresh()->status);
        $this->assertSame(1, OpeningBalance::count());
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_index_lists_status_per_account_and_staff_only_see_their_properties(): void
    {
        $owner = User::factory()->owner()->create();
        $elsewhere = BillingAccount::factory()->create();
        $this->draftAs($owner);
        $this->draftAs($owner, $elsewhere);

        $this->actingAs($owner)->get(route('opening-balances.index'))
            ->assertInertia(fn (Assert $p) => $p->component('opening-balances/index')
                ->has('accounts', 2)
                ->where('canManage', true)
                ->where('accounts.0.status', 'pending'));

        $this->actingAs($owner)->get(route('opening-balances.index', ['property_id' => $this->property->id]))
            ->assertInertia(fn (Assert $p) => $p->has('accounts', 1)->where('accounts.0.account_no', $this->account->account_no));

        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);
        $this->actingAs($staff)->get(route('opening-balances.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('accounts', 1)->where('canManage', false));

        $this->actingAs(User::factory()->accountant()->create())->get(route('opening-balances.index'))->assertOk();
    }

    public function test_index_flags_accounts_that_already_have_bills(): void
    {
        $owner = User::factory()->owner()->create();
        $other = BillingAccount::factory()->for($this->property)->create();
        $this->actingAs($owner);
        $invoiceless = app(ReceivableLedgerService::class);
        // A charge entry on $this->account dated 2026-10-05 (source can be any model).
        $invoiceless->post($this->account, LedgerEntryType::Charge, 350000, Carbon::parse('2026-10-05'), $this->account);

        $this->get(route('opening-balances.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('accounts.0.first_charge_date', '2026-10-05')
                ->where('accounts.1.first_charge_date', null));
        $this->assertNotNull($other->id);
    }

    public function test_owner_imports_a_csv_and_sees_the_results(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $bad = BillingAccount::factory()->for($this->property)->create();
        $body = "account_no,amount,as_of,description\n{$this->account->account_no},\"1,800.00\",2026-10-31,Old\n{$bad->account_no},\"18,00\",2026-10-31,\n";

        $this->post(route('opening-balances.import'), ['file' => UploadedFile::fake()->createWithContent('opening.csv', $body)])
            ->assertRedirect(route('opening-balances.index'));

        $this->assertSame(1, OpeningBalance::count());
        $this->get(route('opening-balances.index'))
            ->assertInertia(fn (Assert $p) => $p->where('importResult.created', 1)->has('importResult.errors', 1));
    }

    public function test_import_file_type_and_size_are_enforced(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->post(route('opening-balances.import'), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');
        $this->post(route('opening-balances.import'), ['file' => UploadedFile::fake()->create('big.csv', 2048, 'text/csv')])->assertSessionHasErrors('file');
        $this->post(route('opening-balances.import'), [])->assertSessionHasErrors('file');
    }
}
