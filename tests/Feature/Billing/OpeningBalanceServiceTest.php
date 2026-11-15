<?php

namespace Tests\Feature\Billing;

use App\Enums\LedgerEntryType;
use App\Models\ActivityLog;
use App\Models\BillingAccount;
use App\Models\InvoiceLineItem;
use App\Models\LedgerEntry;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Services\Billing\OpeningBalanceService;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OpeningBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private OpeningBalanceService $service;

    private BillingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->actingAs(User::factory()->owner()->create());
        $this->service = app(OpeningBalanceService::class);
        $this->account = BillingAccount::factory()->create();
    }

    private function draft(?BillingAccount $account = null, int $cents = 180000, string $asOf = '2026-10-31', ?array $breakdown = null): OpeningBalance
    {
        return $this->service->draft($account ?? $this->account, $cents, Carbon::parse($asOf), $breakdown, null);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('opening.csv', $body);
    }

    public function test_draft_is_pending_and_posts_nothing(): void
    {
        $ob = $this->draft();

        $this->assertSame('pending', $ob->status);
        $this->assertSame(180000, $ob->amount_cents);
        $this->assertSame('Unpaid balance brought forward before JT System', $ob->refresh()->description);
        $this->assertSame(0, LedgerEntry::count());
        $this->assertTrue(ActivityLog::where('action', 'opening_balance.drafted')->exists());
    }

    public function test_approve_posts_exactly_one_opening_entry(): void
    {
        $ob = $this->draft();

        $entry = $this->service->approve($ob);

        $this->assertSame(LedgerEntryType::Opening, $entry->type);
        $this->assertSame(180000, $entry->amount_cents);
        $this->assertSame('2026-10-31', $entry->effective_date->toDateString());
        $this->assertSame(1, LedgerEntry::where('billing_account_id', $this->account->id)->count());
        $this->assertSame(180000, app(ReceivableLedgerService::class)->balance($this->account));

        $ob->refresh();
        $this->assertSame('approved', $ob->status);
        $this->assertSame($entry->id, $ob->ledger_entry_id);
        $this->assertSame(auth()->id(), $ob->approved_by);
        $this->assertTrue(ActivityLog::where('action', 'opening_balance.approved')->exists());
    }

    public function test_approving_twice_posts_no_second_entry(): void
    {
        $ob = $this->draft();

        $first = $this->service->approve($ob);
        $second = $this->service->approve(OpeningBalance::find($ob->id));
        $stale = $this->service->approve($ob); // stale in-memory copy still says pending

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $stale->id);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_second_live_draft_for_the_same_account_is_rejected(): void
    {
        $this->draft();

        try {
            $this->draft();
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('billing_account_id', $e->errors());
        }
        $this->assertSame(1, OpeningBalance::count());
    }

    public function test_database_itself_rejects_two_live_rows_for_one_account(): void
    {
        $this->draft();

        $this->expectException(QueryException::class);
        DB::table('opening_balances')->insert([
            'billing_account_id' => $this->account->id, 'amount_cents' => 100, 'as_of' => '2026-10-31',
            'description' => 'x', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_breakdown_must_add_up_to_the_amount(): void
    {
        $bad = [['month' => '2026-09', 'amount_cents' => 100000, 'note' => null], ['month' => '2026-10', 'amount_cents' => 70000, 'note' => null]];

        try {
            $this->draft(breakdown: $bad);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('breakdown', $e->errors());
        }

        $good = [['month' => '2026-09', 'amount_cents' => 100000, 'note' => 'Sept rent'], ['month' => '2026-10', 'amount_cents' => 80000, 'note' => null]];
        $ob = $this->draft(breakdown: $good);
        $this->assertSame($good, $ob->refresh()->breakdown);
    }

    public function test_amount_must_be_positive_and_date_not_in_the_future(): void
    {
        foreach ([[0, '2026-10-31', 'amount'], [-5, '2026-10-31', 'amount'], [1000, '2026-11-17', 'as_of']] as [$cents, $asOf, $key]) {
            try {
                $this->draft(cents: $cents, asOf: $asOf);
                $this->fail("Expected an error on {$key}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }

        $this->assertSame(0, OpeningBalance::count());
        $this->draft(asOf: '2026-11-16'); // today is allowed
    }

    public function test_reverse_posts_a_reversal_and_allows_a_redraft(): void
    {
        $ob = $this->draft();
        $entry = $this->service->approve($ob);

        $this->service->reverse($ob, 'Entered on the wrong account');

        $this->assertSame('reversed', $ob->refresh()->status);
        $this->assertSame(0, app(ReceivableLedgerService::class)->balance($this->account));
        $reversal = LedgerEntry::where('reverses_id', $entry->id)->firstOrFail();
        $this->assertSame(-180000, $reversal->amount_cents);
        $this->assertTrue(ActivityLog::where('action', 'opening_balance.reversed')->exists());

        $again = $this->draft(cents: 150000);
        $this->service->approve($again);
        $this->assertSame(150000, app(ReceivableLedgerService::class)->balance($this->account));
        $this->assertSame(2, OpeningBalance::count());

        // The old opening entry is still reversed once only; reversing the first record again is refused.
        $this->expectException(ValidationException::class);
        $this->service->reverse($ob, 'again');
    }

    public function test_a_pending_draft_can_be_cancelled_without_touching_the_ledger(): void
    {
        $ob = $this->draft();

        $this->service->reverse($ob, 'Not needed');

        $this->assertSame('reversed', $ob->refresh()->status);
        $this->assertSame(0, LedgerEntry::count());
        $this->draft(); // account is free again
        $this->assertSame(2, OpeningBalance::count());
    }

    public function test_a_reversed_balance_cannot_be_approved(): void
    {
        $ob = $this->draft();
        $this->service->reverse($ob, 'No');

        $this->expectException(ValidationException::class);
        $this->service->approve($ob);
    }

    public function test_opening_entry_is_never_attached_to_an_invoice_line(): void
    {
        $entry = $this->service->approve($this->draft());

        $this->assertNull($entry->invoice_id);
        $this->assertSame(0, InvoiceLineItem::count());
    }

    public function test_csv_import_creates_pending_drafts_and_reports_bad_rows(): void
    {
        $other = BillingAccount::factory()->create();
        $third = BillingAccount::factory()->create();
        $body = "account_no,amount,as_of,description\n"
            .$this->account->account_no.",\"1,800.00\",2026-10-31,Old debt\n"
            .$other->account_no.",\"18,00\",2026-10-31,Typo\n"
            .$third->account_no.",500,2026-10-15,\n";

        $result = $this->service->importCsv($this->csv($body));

        $this->assertSame(2, $result['created']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Row 3', $result['errors'][0]);
        $this->assertStringContainsString($other->account_no, $result['errors'][0]);
        $this->assertSame(['pending'], OpeningBalance::pluck('status')->unique()->all());
        $this->assertSame(180000, OpeningBalance::where('billing_account_id', $this->account->id)->value('amount_cents'));
        $this->assertSame('Old debt', OpeningBalance::where('billing_account_id', $this->account->id)->value('description'));
        $this->assertSame('Unpaid balance brought forward before JT System', OpeningBalance::where('billing_account_id', $third->id)->value('description'));
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_csv_rejects_unknown_duplicate_future_and_already_set_accounts(): void
    {
        $dup = BillingAccount::factory()->create();
        $future = BillingAccount::factory()->create();
        $taken = BillingAccount::factory()->create();
        $this->draft($taken);

        $body = "account_no,amount,as_of,description\n"
            ."JT-999999,100,2026-10-31,\n"
            .$dup->account_no.",100,2026-10-31,\n"
            .$dup->account_no.",200,2026-10-31,\n"
            .$future->account_no.",100,2026-12-31,\n"
            .$taken->account_no.",100,2026-10-31,\n"
            .$this->account->account_no.",100,31/10/2026,\n";

        $result = $this->service->importCsv($this->csv($body));

        $this->assertSame(1, $result['created']); // only the first $dup row
        $this->assertCount(5, $result['errors']);
        $this->assertSame(10000, OpeningBalance::where('billing_account_id', $dup->id)->value('amount_cents'));
    }

    public function test_csv_with_wrong_header_or_bad_encoding_creates_nothing(): void
    {
        $r = $this->service->importCsv($this->csv("acct,amt\nJT-000001,5\n"));
        $this->assertSame(0, $r['created']);
        $this->assertNotEmpty($r['errors']);

        $r = $this->service->importCsv($this->csv("account_no,amount,as_of,description\n".$this->account->account_no.",5,2026-10-31,caf\xE9\n"));
        $this->assertSame(0, $r['created']);
        $this->assertSame(0, OpeningBalance::count());
    }

    public function test_csv_tolerates_a_utf8_bom_and_blank_lines(): void
    {
        $body = "\xEF\xBB\xBFaccount_no,amount,as_of,description\r\n\r\n".$this->account->account_no.",250.50,2026-10-31,\r\n";

        $result = $this->service->importCsv($this->csv($body));

        $this->assertSame(['created' => 1, 'errors' => []], $result);
        $this->assertSame(25050, OpeningBalance::value('amount_cents'));
    }
}
