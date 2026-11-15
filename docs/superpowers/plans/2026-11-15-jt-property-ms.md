# JT Property Management System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One Laravel 12 + React/Inertia app that bills Philippine rental tenants correctly (per-tenant rent/due dates, add-ons, utilities, opening balances, one-click bills, payments, A4 PDF statements).

**Architecture:** Laravel monolith from the official React starter kit (v1.0.1). Domain logic in `app/Services/*` (spec §12 names), called from controllers, Artisan commands, jobs and Pest tests. AR subledger = `ledger_entries` + `payment_allocations`; invoices post only current charges.

**Tech Stack:** PHP 8.2.12, Laravel 12, Inertia 2, React 19 + TS, Tailwind 4, shadcn/ui, MariaDB 10.4 (XAMPP), Pest, spatie/laravel-pdf + Browsershot (Chrome 157), laravel/boost.

**Spec:** `JT_Property_Management_MASTER_PROMPT.md` (authoritative) + `docs/superpowers/specs/2026-11-15-jt-property-ms-design.md` (deviations).

**Plan shape:** Phase 1 is fully broken into tasks. Phases 2–6 list tasks with interfaces and acceptance tests; each gets expanded with writing-plans when reached (one plan per phase, so each phase ships working software).

## Global Constraints

- Single Laravel repo; React pages in `resources/js/pages`; no `frontend/`/`backend/` split, no browser REST API.
- Money: integer centavos (`BIGINT`, `*_cents`); display `₱1,234.56`; never floats.
- Timezone `Asia/Manila` (`config/app.php` `timezone`).
- Posted ledger entries and finalized invoices are immutable: no update/delete; corrections = reversal entry + `activity_logs` row with reason.
- Every controller action authorizes via policy/gate; staff scoped to assigned properties.
- No open registration (owner creates users).
- Due day 29/30/31 in short months: clamp to last day of month (configurable later).
- Proration default off.
- Never send email from preview; auto-send default off.
- Demo seed data: fake, clearly labeled people and addresses only.

## Review Focus

1. Double-click / replayed "Confirm Generate" → exactly one invoice per agreement+cycle (unique `idempotency_key`, test in Phase 3 Task 3.6).
2. Payment received after statement cutoff → shown once under "Payments Received", not also netted in Previous Balance (Phase 4 Task 4.3 test `payment after cutoff is not double subtracted`).
3. Staff hitting another property's invoice/PDF URL directly → 403 (Phase 4 Task 4.5 test).
4. Rent increase after an invoice was finalized → old invoice/PDF totals unchanged (Phase 3 Task 3.6 test).
5. Agreement ending mid-period / tenant archived → no new bill, existing balance still shown (Phase 3 Task 3.5 test).

---

## Phase 1 — Foundation

### Task 1.1: Scaffold app, DB, Boost

**Files:** whole starter kit into repo root; `.env`, `.env.example`, `config/app.php`.

- [ ] **Step 1:** `composer create-project laravel/react-starter-kit:1.0.1 _scaffold` in repo root, move contents (incl. dotfiles) to root, delete `_scaffold`.
- [ ] **Step 2:** `composer update` (stays on `laravel/framework ^12`); `npm install`.
- [ ] **Step 3:** `.env` + `.env.example`: `DB_CONNECTION=mysql`, `DB_DATABASE=jt_property`, `APP_TIMEZONE`/`config('app.timezone')='Asia/Manila'`, `APP_NAME="JT Property"`. Create DB: `C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE IF NOT EXISTS jt_property CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"`.
- [ ] **Step 4:** `php artisan migrate` → succeeds. `php artisan test` → all starter tests pass. `npm run build` → succeeds.
- [ ] **Step 5:** `composer require laravel/boost --dev`; `php artisan boost:install` (Claude Code). Verify `.mcp.json` exists.
- [ ] **Step 6:** `git init`; commit "chore: scaffold Laravel 12 React starter kit".

### Task 1.2: Money helper

**Files:** Create `app/Support/Money.php`; Test `tests/Unit/MoneyTest.php`.

**Interfaces — Produces:** `Money::format(int $cents): string` → `"₱1,234.56"`, negatives `"-₱1,234.56"`; `Money::toCents(string|int $pesos): int` (parses `"1,234.5"` → `123450`; rejects >2 decimals with `InvalidArgumentException`). TS mirror `resources/js/lib/money.ts` `formatCents(cents: number): string`.

- [ ] Test: `format(123456)==='₱1,234.56'`, `format(-50)==='-₱0.50'`, `format(0)==='₱0.00'`, `toCents('1,800')===180000`, `toCents('0.1')===10`, `toCents('1.234')` throws.
- [ ] Run → FAIL; implement with string math (`bcmul` not required: split on `.`); run → PASS; commit.

### Task 1.3: Roles, no open registration

**Files:** migration `add_role_to_users`; `app/Enums/Role.php` (`Owner`, `Staff`, `Accountant`); `app/Models/User.php`; `routes/auth.php`; `app/Http/Middleware/EnsureRole.php` alias `role`; Test `tests/Feature/RoleAccessTest.php`.

**Produces:** `User::$role` cast to `Role`; `$user->isOwner(): bool`; middleware `role:owner`; `Gate::define('owner', ...)`.

- [ ] Tests: `/register` GET returns 404; registered starter-kit registration tests deleted; owner can GET `/settings/users` (Task 1.5 route) 200, staff gets 403; default role for factory = `staff`, `User::factory()->owner()` state.
- [ ] Remove register routes/page; add role column (`string`, default `staff`); FAIL→implement→PASS; commit.

### Task 1.4: Brand + design tokens + per-user theme

**Files:** `public/branding/jt-logo-horizontal.svg`, `jt-icon.svg`, `favicon.ico`/`favicon.svg` (placeholder, spec palette); `resources/css/app.css` tokens; `resources/js/components/app-logo*.tsx`; migration `add_appearance_to_users` (`light|dark|system`, default `system`); `HandleInertiaRequests` shares it; appearance settings page persists to DB.

- [ ] Test `tests/Feature/AppearanceTest.php`: PATCH `/settings/appearance` `{appearance:'dark'}` → user row `dark`; invalid value → 422; shared prop `auth.user.appearance` present.
- [ ] Tokens (from design-system skill) in `:root` / `.dark`: primary = teal `#007A69`, brand navy `#064578`, accent mint `#24C5A4`, background `#F5F9FC` / `#101D2D`, warning amber, destructive red.
- [ ] FAIL→implement→PASS; `npm run build`; commit.

### Task 1.5: Base layout, owner user management, seed, README

**Files:** sidebar nav `resources/js/components/app-sidebar.tsx`; `app/Http/Controllers/Settings/UserController.php` + `resources/js/pages/settings/users.tsx` (owner lists/creates users with role, sets password); `database/seeders/DatabaseSeeder.php` (owner `owner@jt.test`, staff `staff@jt.test`, password from `SEED_PASSWORD` env, default `password` only in local); `README.md` setup.

- [ ] Tests `tests/Feature/UserManagementTest.php`: owner creates staff user (201/redirect, row exists, role staff); staff POST → 403; duplicate email → 422.
- [ ] `php artisan migrate:fresh --seed` → succeeds; login as owner works (feature test `seeded owner can log in`).
- [ ] FAIL→implement→PASS; `php artisan test`; `npm run build`; commit. Tick checklist Foundation items verified.

---

## Phase 2 — Properties & tenants (expand before executing)

Tables: `properties` (type enum, address, status), `units` (property_id, code, floor, capacity, `rent_mode` enum `whole|per_bed`, status), `beds` (unit_id, label), `property_user` (staff assignment), `tenants` (contact fields, `archived_at`), `billing_accounts` (tenant_id payer, property_id, `account_no` unique), `rental_agreements` (billing_account_id, unit_id, bed_id nullable, `rent_cents`, `due_day` 1–31, `cycle_anchor_day`, `issue_lead_days`, start/end, `prorate` bool, status), `rent_rate_histories` (agreement_id, `rent_cents`, effective_from), `occupancies` (tenant_id, unit_id, bed_id, agreement_id, move_in, move_out), `security_deposits`.

- 2.1 Properties/units/beds CRUD + policies (owner manages, staff views assigned).
- 2.2 Tenants + billing accounts; archive instead of delete when financial history exists.
- 2.3 Agreements: whole-room vs per-bed; `OccupancyService::assertCanOccupy()` (capacity + no overlapping active bed/whole-unit). Test: 4 tenants in capacity-4 room with ₱2,500/5th, ₱2,700/12th, ₱2,400/18th, ₱3,000/28th; 5th rejected; whole-room agreement blocks per-bed agreements in same period.
- 2.4 Rate changes (effective date history), transfers, move-out, deposits as liability.
- 2.5 Screens: Properties, Tenants (table + drawer), seed demo properties.

## Phase 3 — Financial engine (TDD; expand before executing)

Tables: `charge_categories`, `recurring_charges`, `one_time_charges`, `meters`, `meter_readings`, `utility_bills`, `utility_allocations`, `billing_batches`, `invoices` (unique `idempotency_key` = `agreement:{id}:cycle:{Y-m-d}:rent`, `number` unique per property, status draft|finalized|void), `invoice_line_items` (snapshot label/qty/rate/amount), `ledger_entries` (billing_account_id, type charge|opening|payment|credit|refund|reversal|writeoff, `amount_cents` signed: debt +, credit −, posted_at, source morph, `reverses_id`), `activity_logs`.

- 3.1 `BillingCycleResolver::cycleFor(RentalAgreement, CarbonImmutable $month): ?BillingCycle` (periodStart, periodEnd, issueDate, dueDate); `dueDate(int $day, int $y, int $m)` clamps. Tests: due 31 → Feb 28 2026 / Feb 29 2028; due 30 → Apr 30.
- 3.2 `RecurringChargeService::linesFor(agreement, cycle)`. Test: rice cooker ₱200 from Nov 1 appears once per cycle, absent after end, changing amount doesn't alter finalized invoices.
- 3.3 `UtilityAllocationService` (inclusive/fixed/metered/shared equal|percent|manual; allocations sum to bill exactly, remainder centavos to first). Missing reading → preview status `needs_utility_input`.
- 3.4 `OpeningBalanceService::import(account, cents, asOf, notes)` → one `opening` ledger entry; unique per account; CSV import; reversal with reason.
- 3.5 `BillingPreviewService::preview(month, ?propertyId, ?accountIds): Collection<PreviewRow>` (no writes).
- 3.6 `InvoiceGenerationService::generate(...)`: transaction + `lockForUpdate` + unique key; posts one `charge` ledger entry per invoice for current charges only. Tests: run twice → 1 invoice; 100+ agreements, no cross-account lines; failure in one row logged, others commit; rent increase later leaves finalized totals.
- 3.7 `ReceivableLedgerService::balance(account, ?asOf): int`. Acceptance: 1,800 opening + (3,500+200+300+150=4,150) = 5,950.
- 3.8 Screens: Charges & Utilities, Opening Balances wizard, Billing (preview → confirm → results).

## Phase 4 — Collections & statements (TDD; expand before executing)

- 4.1 `payments` + `PaymentAllocationService::record(...)`: oldest-first default, manual allocation, excess → unapplied credit; pending/approved status; reversal (owner only) with reason.
- 4.2 Refunds, write-offs, advance credits reconcile.
- 4.3 `StatementComposer::compose(Invoice): Statement` with cutoff = cycle start. Test: ₱500 posted after cutoff → total due 5,450, Previous Balance stays 1,800.
- 4.4 `StatementPdfService` (Blade `resources/views/pdf/statement.blade.php`, spatie/laravel-pdf, A4) + HTML preview; immutable `statement_snapshots` (stored PDF path in private disk + totals). Tests: long names/addresses, many lines, Filipino characters (ñ, á) render; page X of Y.
- 4.5 `BatchExportService`: ZIP + combined PDF; authorized download routes. Test: staff of property B gets 403 on property A PDF.
- 4.6 Payment acknowledgment PDF; account statement for date range. Screens: Collections + payment modal.

## Phase 5 — Automation & reporting (expand before executing)

- 5.1 `BillingEmailService` + `email_deliveries` (unique per invoice+kind); skip no-email with reason; queued after-commit; Test SMTP action.
- 5.2 Scheduled generation (opt-in setting) using same service; reminders before/on/after due.
- 5.3 Ops status panel (queue, failed jobs, last scheduler run, SMTP).
- 5.4 `AgingReportService` (current/1–30/31–60/61–90/90+), rent roll, collections, occupancy, utilities/appliance revenue, deposits; CSV export; reconcile test.
- 5.5 Dashboard KPIs (real queries), Calendar, audit log page, Settings (company, pay instructions, policies).

## Phase 6 — Hardening & hand-off

- 6.1 Full `php artisan test`, `npm run build`, `migrate:fresh --seed`, real PDF output check.
- 6.2 Security review (policies, private disk, upload validation, rate limits); dark mode/responsive pass.
- 6.3 README: Windows/XAMPP + Linux VPS, queue worker, scheduler, PDF engine + dompdf fallback, backups, production-readiness checklist.
- 6.4 Demo walkthrough script (spec §15) as a feature test.
