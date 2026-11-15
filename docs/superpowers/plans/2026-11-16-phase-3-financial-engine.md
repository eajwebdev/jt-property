# Phase 3 — Financial Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. superpowers:test-driven-development is MANDATORY for every task here.

**Goal:** Correct, duplicate-proof monthly billing: per-agreement cycles and due dates, recurring add-ons, utilities, opening balances, an AR ledger, preview, and one-click generation of finalized invoices.

**Architecture:** Pure/domain services in `app/Services/Billing/*` (spec §12 names) operating on integer centavos; all writes in DB transactions with row locks and unique idempotency keys. Ledger (`ledger_entries`) is append-only; invoices post ONLY current charges; previous balance is derived, never re-posted.

**Tech Stack:** as master plan. Tests on MariaDB `jt_property_test`.

**Spec:** `JT_Property_Management_MASTER_PROMPT.md` §3–§7, §12, §13 + master plan Global Constraints + Phase 2 data model (`docs/superpowers/plans/2026-11-15-phase-2-properties-tenants.md`).

## Global Constraints (in addition to master + Phase 2)

- Integer centavos everywhere (`*_cents` BIGINT, signed where a credit is possible). Rounding: half-up to the centavo, only at the final amount of a line; never round intermediate shares except in allocation (largest-remainder).
- Utility rates are stored as integer **ten-thousandths of a peso** (`rate_e4`, ₱11.5432/kWh → 115432) and consumption as integer **thousandths** (`qty_e3`, 42.5 kWh → 42500). `amount_cents = round_half_up(qty_e3 * rate_e4 / 100000)`.
- Ledger sign: `+` increases what the tenant owes (charge, opening, refund, reversal of a payment), `−` decreases it (payment, credit, write-off, reversal of a charge). Balance = Σ amount_cents.
- Immutable: finalized invoices, invoice lines, ledger entries, billed meter readings / utility bills / allocations. Corrections = void (reversal entry) + audit, or credit/debit adjustment entries.
- Every money-moving action is owner-only (staff may enter meter readings and view previews); `Gate::authorize` in every action; `Audit::log` on every post/void/approve.
- Business dates in `Asia/Manila`. Agreement `end_date` and occupancy `move_out_date` are inclusive.
- Never send email or post anything from a preview.
- Once an agreement has a finalized invoice for a cycle, `TenancyService::changeRent` / `moveOut` / `transfer` must reject dates inside that invoiced period (key `effective_from` / `date`: "A bill was already issued for this period — void it first or pick a later date."). Owned by Task 3.7 (add the guard + tests there).
- Agreement/account liveness is always decided by dates (`activeOn`), never by the `status` column alone.

## Decided rules

**Billing month & cycle (3.1).** A billing month `M` (year, month) gives an agreement the cycle whose `period_start = date(M.year, M.month, cycle_start_day)`; `period_end = period_start + 1 month − 1 day`. Due date = the first date ≥ `period_start` whose day-of-month equals `due_day`, where in a month shorter than `due_day` the day is clamped to the month's last day (Feb 31 → Feb 28/29). Issue date = `due_date − issue_lead_days`. Eligible when the agreement overlaps `[period_start, period_end]` (inclusive end) and status isn't voided.
Examples: cycle_start 1, due 5, Nov 2026 → period Nov 1–30, due Nov 5, issue Oct 29 (lead 7). cycle_start 15, due 5, Nov 2026 → period Nov 15–Dec 14, due Dec 5. due 31, Feb 2026 → Feb 28; Feb 2028 → Feb 29; due 30, cycle_start 1, Apr → Apr 30.

**Rent line.** Amount = `agreement->rentOn(max(period_start, agreement.start_date))` (the first rate is dated the agreement start, so a first partial cycle must look up from the start date). Mid-cycle rate changes apply from the next cycle (documented on screen). If the agreement covers only part of the period: `prorate=false` → full rent; `prorate=true` → `round_half_up(rent * covered_days / period_days)` with the formula shown as the line's description (e.g. `Monthly Rental – Room A101 (12/30 days)`).

**Transfer month.** Agreements are linked by `rental_agreements.replaces_agreement_id` (set by `TenancyService::transfer`, Task 2.7). In the cycle where a transfer happens, BOTH the old agreement's last cycle and the new agreement's first cycle are prorated by covered days regardless of the `prorate` flag, so the tenant never pays two full rents for one month. Recurring charges are carried on transfer (Task 3.3 adds this to `transfer()`: active recurring charges of the old agreement get `end_date = transfer_date − 1` and copies start on the transfer date on the new agreement).

**Recurring charges.** Belong to a rental agreement. Included once per cycle when `active` and `start_date <= period_end` and (`end_date` null or `end_date >= period_start`). Amount snapshot copied to the line; later edits never touch finalized lines.

**One-time charges / credits.** Belong to a billing account (optional agreement); `kind` charge|credit (credit stored negative on the line); `bill_month` (first of month) — picked up by the first invoice for that account in that billing month; `invoice_id` set when consumed → never billed twice.

**Utilities.** Configured per unit and type (`electricity`, `water`): mode `inclusive` (nothing billed), `fixed` (fixed_cents per agreement per cycle), `metered` (one reading per unit per billing month, billed to the whole-unit agreement or split), `shared` (owner enters the master bill amount; split). Split method for per-bed units: `equal` (agreements active any day in the period), `days` (by covered days), `percent`, `manual`. Allocations use largest remainder so Σ allocations = bill amount exactly. A `metered`/`shared` unit with no utility bill for the month → preview status `needs_utility_input`; the owner can **exclude** that utility for that agreement+month (stored as `utility_exclusions`) or wait. A billed utility bill is immutable.

**Ledger & statement math.** Invoice finalization posts ONE `charge` ledger entry = invoice `total_cents` (current charges only), `effective_date = issue_date`. Opening balance posts ONE `opening` entry, `effective_date = as_of`. For an invoice: `previous_balance = Σ entries of the account with effective_date < period_start, excluding this invoice's charge`; `payments_since = Σ payment/credit entries with effective_date >= period_start`; `statement_total_due = previous_balance + current_charges + payments_since` (payments are negative). Acceptance: 1,800 + (3,500+200+300+150 = 4,150) = 5,950; a ₱500 payment dated after period_start → 5,450 with previous balance still 1,800.

**Generation.** Confirm creates **finalized** invoices directly (the preview is the draft — no editable draft state); one DB transaction per agreement-cycle; `invoices.idempotency_key` UNIQUE = `agreement:{id}:cycle:{period_start}`; the agreement row is `lockForUpdate`d; a duplicate key is reported as `already_billed`, never an error. Per-row outcome stored in `billing_batch_items` (generated | already_billed | skipped | needs_review | failed + message). Invoice number per property: `{PROPERTY_CODE}-{YYYYMM}-{seq 4}` from a locked per-property counter row.

## Review Focus

1. Same batch confirmed twice / two browser tabs → each agreement-cycle has exactly one invoice, one charge entry, one consumption of each one-time charge (3.7).
2. Rent raised after an invoice was finalized → that invoice's lines/total unchanged; next cycle uses the new rate (3.7).
3. Opening balance imported twice (same account) → second rejected; it never appears as a rent line (3.5, 3.7).
4. Shared meter of ₱1,000.00 split 3 ways → 333.34 + 333.33 + 333.33 = 1,000.00 (3.4).
5. Agreement ended mid-period with prorate off → billed full rent for that last cycle; with prorate on → prorated with the formula shown (3.1/3.6).

---

### Task 3.1: BillingCycleResolver (pure)

**Files:** `app/Services/Billing/BillingCycle.php` (readonly value object: `periodStart`, `periodEnd`, `issueDate`, `dueDate` as `CarbonImmutable`, `periodDays(): int`, `coveredDays(RentalAgreement): int`), `app/Services/Billing/BillingCycleResolver.php`; Test `tests/Unit/Billing/BillingCycleResolverTest.php`.

**Produces:** `BillingCycleResolver::cycleFor(RentalAgreement $a, int $year, int $month): ?BillingCycle` (null when not eligible); `BillingCycleResolver::dueDate(int $dueDay, CarbonImmutable $periodStart): CarbonImmutable`; `BillingCycleResolver::rentLine(RentalAgreement $a, BillingCycle $c): array{amount_cents:int, description:string}`.

- [ ] Tests (all examples in "Decided rules", incl. first partial cycle uses rentOn(start_date), and transfer month: old ends 11-15, new starts 11-16 (linked) with prorate=false → both prorated 15/30 and 15/30): due 31 in Feb 2026 → 2026-02-28; Feb 2028 → 2028-02-29; due 30 Apr → 04-30; 15th–14th cycle due 5 → next month's 5th; issue = due − lead; agreement starting 2026-11-20 with cycle_start 1, prorate off → full rent; prorate on → round_half_up(rent×11/30); ended 2026-11-10 (inclusive) prorate on → 10/30; agreement starting after period_end → null; rent change effective mid-period not used until next cycle.
- [ ] RED → implement → GREEN → commit `feat: billing cycle resolver`.

### Task 3.2: Ledger, invoices schema, ReceivableLedgerService

**Files:** also a migration adding composite indexes `occupancies (unit_id, move_in_date)`, `occupancies (tenant_id, move_in_date)`, `rental_agreements (unit_id, start_date)` (needed before the 100+ batch test); migrations `ledger_entries`, `invoices`, `invoice_line_items`, `invoice_counters` (property_id PK, `next_seq` per YYYYMM or `last_month`+`seq`), `billing_batches`, `billing_batch_items`; enums `LedgerEntryType`, `InvoiceStatus` (finalized|void), `BatchItemStatus`; models with append-only guards (LedgerEntry, InvoiceLineItem always; Invoice blocks updates except `status/voided_at/void_reason` transition finalized→void); `app/Services/Billing/ReceivableLedgerService.php`.

**Produces:** `ReceivableLedgerService::post(BillingAccount $acc, LedgerEntryType $type, int $cents, CarbonInterface $effective, Model $source, ?string $memo = null): LedgerEntry`; `balance(BillingAccount $acc, ?CarbonInterface $asOf = null): int` (effective_date <= asOf); `reverse(LedgerEntry $e, string $reason): LedgerEntry` (posts negated amount, `reverses_id` UNIQUE so an entry can be reversed once); `InvoiceNumberGenerator::next(Property $p, CarbonInterface $month): string`.

- [ ] Tests: balance sums signed amounts with asOf cutoff; reverse twice → second fails; LedgerEntry update/delete throw; invoice number `RIV-202611-0001`, `-0002`, new month restarts at 0001, other property independent; idempotency_key unique enforced by DB; CHECKs (`total_cents` may be negative? no: `total_cents >= 0` is NOT required — credits can exceed charges; leave unchecked).
- [ ] RED → GREEN → commit `feat: AR ledger and invoice schema`.

### Task 3.3: Charge categories, recurring and one-time charges

**Files:** migrations `charge_categories` (name unique, `kind` rent|appliance|utility|service|other, `is_system`), seed defaults (Rent, Rice cooker, Refrigerator, Aircon, Electric fan, Internet, Parking, Water, Electricity, Repair, Cleaning, Miscellaneous); `recurring_charges` (rental_agreement_id, charge_category_id, label, amount_cents >0, frequency `monthly`, start_date, end_date null, active); `one_time_charges` (billing_account_id, rental_agreement_id null, charge_category_id, label, kind charge|credit, amount_cents >0, bill_month DATE first-of-month, invoice_id null, created_by, reason null); `app/Services/Billing/RecurringChargeService.php`; controllers + pages `resources/js/pages/charges/*` (owner manages; staff view).

**Produces:** `RecurringChargeService::linesFor(RentalAgreement $a, BillingCycle $c): Collection<array{label,amount_cents,category_id,source}>`; `OneTimeChargeService::pendingFor(BillingAccount $acc, int $year, int $month): Collection<OneTimeCharge>`.

- [ ] Also: extend `TenancyService::transfer` to carry active recurring charges (end old at transfer_date − 1, copy to the new agreement from transfer_date) inside its transaction; test it.
- [ ] Tests: rice cooker ₱200 from 2026-11-01 → lines for Nov, Dec; ended 2026-12-31 → none for Jan 2027; inactive → none; starts mid-cycle → included; one-time credit appears negative; consumed one-time charge (invoice_id set) not returned; staff POST recurring → 403; updating a recurring charge's amount is allowed (affects future only) and audited.
- [ ] RED → GREEN → tsc/build → commit `feat: recurring and one-time charges`.

### Task 3.4: Utilities

**Files:** migrations `unit_utilities` (unit_id, type, mode, fixed_cents null, rate_e4 null, base_charge_cents null, split_method null, unique unit+type), `utility_bills` (unit_id, type, bill_month, previous_reading_e3 null, current_reading_e3 null, reading_date null, rate_e4 null, base_charge_cents, amount_cents, evidence_path null, status draft|billed, entered_by; unique unit+type+bill_month), `utility_allocations` (utility_bill_id, rental_agreement_id, amount_cents, basis string; unique pair), `utility_split_shares` (unit_utility_id, rental_agreement_id, percent_bp or manual_cents) for percent/manual, `utility_exclusions` (rental_agreement_id, type, bill_month, reason, created_by; unique); `app/Services/Billing/UtilityAllocationService.php`; reading-entry page `resources/js/pages/utilities/*` (staff of the property may enter readings; owner configures modes).

**Produces:** `UtilityAllocationService::amountFor(UtilityBill $b): int` (metered: round(qty×rate)+base; shared: entered amount); `allocate(UtilityBill $b, Collection $agreements, BillingCycle[] $cycles): array<int agreementId,int cents>` (largest remainder, Σ = bill); `linesFor(RentalAgreement $a, BillingCycle $c): array{lines: Collection, needs_input: list<string type>}` (inclusive → none; fixed → fixed line; metered/shared with bill → allocated line with usage text `Electricity – 42.500 kWh × ₱11.5432`; missing bill and not excluded → needs_input).

- [ ] Tests: 42.5 kWh × ₱11.5432 + ₱0 base = ₱490.59 (round half up of 490.586); ₱1,000.00 equal 3 ways = 33334/33333/33333; `days` split where one of 3 tenants covered 15/30 days sums exactly; percent 50/30/20 of ₱999.99 sums exactly; inclusive → no line; missing bill → needs_input contains type; exclusion → no line, no flag; billed utility bill update throws; reading lower than previous → validation error.
- [ ] RED → GREEN → tsc/build → commit `feat: utilities and allocations`.

### Task 3.5: Opening balances

**Files:** migration `opening_balances` (billing_account_id UNIQUE, amount_cents >0, as_of date, description default "Unpaid balance brought forward before JT System", breakdown json null, notes null, status pending|approved|reversed, created_by, approved_by null, ledger_entry_id null); `app/Services/Billing/OpeningBalanceService.php`; wizard pages `resources/js/pages/opening-balances/*` (list accounts without an opening balance, enter amount/as-of/breakdown, review, approve; CSV import `account_no,amount,as_of,description`).

**Produces:** `OpeningBalanceService::draft(BillingAccount, int $cents, CarbonInterface $asOf, ?array $breakdown, ?string $notes): OpeningBalance`; `approve(OpeningBalance $ob): LedgerEntry` (owner; posts `opening` entry once); `reverse(OpeningBalance $ob, string $reason): void` (ledger reverse + status reversed; a new one may then be drafted); `importCsv(UploadedFile $f): array{created:int, errors: list<string>}` (row-level errors, whole file in one transaction only for valid rows; duplicate account → error row).

- [ ] Tests: approve posts exactly one opening entry; approve twice → no second entry; second draft for same account → unique error; breakdown sums must equal amount; CSV with one bad amount (`18,00`) → that row errors, others created; reverse then redraft allowed; staff → 403; opening entry never produces an invoice line (assert after 3.7 too).
- [ ] RED → GREEN → tsc/build → commit `feat: opening balance migration`.

### Task 3.6: BillingPreviewService (no writes)

**Files:** `app/Services/Billing/BillingPreviewService.php`, `app/Services/Billing/PreviewRow.php` (readonly DTO).

**Produces:** `preview(int $year, int $month, ?int $propertyId = null, ?array $agreementIds = null, ?User $for = null): array{rows: list<PreviewRow>, summary: array}`. PreviewRow: agreement/account/tenant/unit labels, cycle dates, `lines` (rent, recurring, utilities, one-time), `current_charges_cents`, `previous_balance_cents` (ledger balance before period_start), `estimated_total_cents`, `status` (eligible|already_billed|needs_review|skipped), `flags` (needs_utility_input:type, no_email, inactive, overlap, existing_invoice). Summary: counts per status, total new charges, total prior outstanding, estimated statement total. Staff see only assigned properties.

- [ ] Tests: preview writes nothing (DB query log has no INSERT/UPDATE); A101 four tenants → 4 rows with their own due dates (5,12,18,28) and rents; tenant without email flagged `no_email` but eligible; missing water reading → `needs_review` with flag; existing invoice → `already_billed`; acceptance case row shows current 4,150, previous 1,800, estimated 5,950.
- [ ] RED → GREEN → commit `feat: billing preview`.

### Task 3.7: InvoiceGenerationService (confirm, idempotent) + void

**Files:** `app/Services/Billing/InvoiceGenerationService.php`; `app/Jobs/GenerateMonthlyBillsJob.php` (wraps the service; used by scheduler in Phase 5).

**Produces:** `generate(int $year, int $month, User $by, ?int $propertyId = null, ?array $agreementIds = null): BillingBatch` — re-runs the preview logic per agreement inside its own transaction (agreement row lockForUpdate; skip needs_review rows unless every flagged utility was excluded), creates invoice + line snapshots + consumes one-time charges + marks utility bills `billed` + posts one `charge` ledger entry, records a batch item; catches `UniqueConstraintViolationException` on idempotency_key → `already_billed`; any other exception → `failed` with message, logged, other rows continue. `retry(BillingBatchItem $item): BillingBatchItem`. `void(Invoice $inv, string $reason, User $by): void` (owner; reverses its charge entry, sets status void, releases consumed one-time charges? NO — one-time charges stay attached; a reissue gets a new invoice number and new key suffix `:reissue:{n}`).

- [ ] Tests (spec acceptance): rent 3,500 + rice cooker 200 + electricity 300 + water 150 → invoice total 4,150, ledger charge 4,150, opening 1,800 separate → account balance 5,950; run generate twice → 1 invoice, 1 charge entry, rice cooker line once; `retry` of a generated item → no duplicate; 100+ agreements across 2 properties → 100+ invoices, every line belongs to its own agreement (no cross-account leakage), one forced failure (mock a utility exception for one agreement) → that item `failed`, others generated, retry after fixing → generated; rent change after finalization → old invoice lines unchanged, next month uses new rent; void → reversal entry, balance drops by total, invoice keeps its number and lines.
- [ ] RED → GREEN → commit `feat: one-click invoice generation`.

### Task 3.8: Billing screens + demo data

**Files:** `BillingController` (preview GET with filters, generate POST owner-only, batch show, invoice index/show, void POST), pages `resources/js/pages/billing/{index,preview,batch,invoice}.tsx`; dashboard big button "Generate Monthly Bills"; sidebar items Charges & Utilities, Opening Balances, Billing; extend `DemoSeeder`: Ana Demo rice cooker ₱200 recurring from 2026-11-01, Ben Demo opening balance ₱1,800 approved (as of 2026-10-31), A101 electricity `shared` + water `shared` with Nov readings for A101, B201 metered electricity with a reading, one tenant without email (exists).

- [ ] Tests: owner preview page renders summary counts; staff cannot POST generate (403); generate redirects to batch page with per-row statuses; double POST → second batch shows all `already_billed`; invoice page shows line items and Current Invoice Total vs Statement Total Due; void requires reason; seeded data: preview for Nov 2026 shows Ben Demo with previous balance ₱1,800.00 and Ana Demo with a `Rice cooker` ₱200.00 line (the exact 5,950 acceptance figures are asserted in Task 3.7's test, not the seed).
- [ ] RED → GREEN → tsc/build → `migrate:fresh --seed` → commit `feat: billing screens and demo billing data`.
