# Phase 2 — Properties & Tenants Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Properties, rooms/units, beds, tenants, billing accounts and rental agreements (whole-room or per-bed) with independent rent + due day, capacity/overlap protection, rent history, transfers, move-outs and deposits.

**Architecture:** Eloquent models + PHP enums + policies; business rules in `app/Services/TenancyService.php` (single service for occupy/transfer/move-out/rent change), called by controllers and seeders. Audit via append-only `activity_logs`. Inertia pages under `resources/js/pages/properties/*`, `tenants/*`.

**Tech Stack:** as master plan (Laravel 12, Inertia 2, React 19, shadcn/ui, MariaDB, PHPUnit-style tests).

**Spec:** `JT_Property_Management_MASTER_PROMPT.md` §2, §3 (agreement fields), §12, §13 + `docs/superpowers/plans/2026-11-15-jt-property-ms.md` (Global Constraints apply).

## Global Constraints (in addition to master plan)

- Money in integer centavos `*_cents` BIGINT; `App\Support\Money::toCents()` parses form input server-side; never trust browser totals.
- Roles: Owner = everything; Staff = view + edit tenants/contact info only in properties assigned via `property_user`; Accountant = read-only all properties. Rents/agreements/rent changes/transfers/move-outs/deposits = **Owner only**.
- Every controller action authorizes (policy or `can:` middleware). Staff requesting another property's record → 403 (not 404 leak is fine either way, but must not show data).
- Never delete a tenant/agreement with history: archive (`archived_at`) / end (`end_date` + status `ended`).
- Due day 1–31 stored as-is (clamping is Phase 3's resolver). `cycle_start_day` 1–28 default 1. `issue_lead_days` 0–31 default 7. `prorate` default false.
- Seed data: fake, clearly labeled ("Demo") people and addresses.
- Dates are business dates in `Asia/Manila`; store `DATE` columns for move-in/out/effective dates.

## Data model (decided)

| Table | Columns (besides id/timestamps) |
|---|---|
| `properties` | `name`, `code` (string 10, unique, used in invoice numbers e.g. `RIV`), `type` enum PropertyType (`house, apartment, boarding_house, dormitory, rooms, bedspaces, other`), `address` text, `description` text null, `amenities` text null, `archived_at` null |
| `property_user` | `property_id`, `user_id`, unique pair |
| `units` | `property_id`, `code` (e.g. `A101`, unique per property), `building` null, `floor` null, `capacity` unsigned tinyint ≥1, `rent_mode` enum RentMode (`whole, per_bed`), `status` enum UnitStatus (`available, maintenance, unavailable`), `description` null, `amenities` null |
| `beds` | `unit_id`, `label` (`Bed 1`…), unique (`unit_id`,`label`) — per_bed units always have exactly `capacity` beds |
| `tenants` | `property_id` (current/primary property, for scoping), `full_name`, `email` null, `mobile` null, `emergency_contact_name` null, `emergency_contact_phone` null, `preferred_delivery` enum (`email, print`) default `email`, `email_opt_in` bool default true, `remarks` text null, `archived_at` null |
| `billing_accounts` | `property_id`, `payer_tenant_id` → tenants, `account_no` unique (`JT-` + 6-digit zero-padded id, set after insert), `status` enum (`active, closed`) |
| `rental_agreements` | `billing_account_id`, `unit_id`, `bed_id` null (null ⇒ whole-unit agreement), `due_day` tinyint 1–31, `cycle_start_day` tinyint 1–28 default 1, `issue_lead_days` tinyint default 7, `prorate` bool default false, `start_date` date, `end_date` date null, `status` enum AgreementStatus (`active, ended`), `override_reason` text null |
| `rent_rates` | `rental_agreement_id`, `rent_cents` BIGINT unsigned, `effective_from` date, `reason` string null, `created_by` user null; unique (`rental_agreement_id`,`effective_from`). Rent lives ONLY here (first row created with the agreement). |
| `occupancies` | `tenant_id`, `unit_id`, `bed_id` null, `rental_agreement_id` null, `is_payer` bool, `move_in_date` date, `move_out_date` date null |
| `security_deposits` | `billing_account_id`, `rental_agreement_id` null, `amount_cents`, `received_on` date, `status` enum (`held, applied, refunded`) default held, `notes` null |
| `activity_logs` | `user_id` null, `action` string (e.g. `agreement.created`, `rent.changed`), `subject_type`/`subject_id` (morph), `properties` json (old/new/reason), `ip_address` null, `created_at` only. Model throws `LogicException` on update/delete. |

Derived (not stored): unit occupied count = active occupancies on date; unit display status = `maintenance|unavailable` if set, else `full` if occupied ≥ capacity, else `vacant` if 0, else `available`.

## Review Focus

1. Fifth tenant into a capacity-4 room (any mode) → validation error, nothing written (Task 2.4).
2. Per-bed agreement on a `whole` unit, or whole agreement on a unit with any active per-bed agreement, overlapping dates → rejected; non-overlapping (after move-out date) → allowed (Task 2.4).
3. Staff assigned to Property A hitting Property B's unit/tenant URLs → 403 (Tasks 2.2, 2.3).
4. Rent change effective in the future must not change today's `rentOn()` (Task 2.5).
5. Reducing a per-bed unit's capacity below occupied beds → rejected (Task 2.2).

---

### Task 2.1: Schema, enums, models, activity log

**Files:** migrations for every table above (one migration file per table, timestamps ordered); `app/Enums/{PropertyType,RentMode,UnitStatus,AgreementStatus,DeliveryMethod,DepositStatus,AccountStatus}.php`; models `Property, Unit, Bed, Tenant, BillingAccount, RentalAgreement, RentRate, Occupancy, SecurityDeposit, ActivityLog` with relations + casts; factories for each; `app/Support/Audit.php`.

**Produces:**
- `Audit::log(string $action, Model $subject, array $properties = []): ActivityLog` — fills `user_id` from `auth()->id()`, `ip_address` from `request()->ip()` when in HTTP.
- `RentalAgreement::rentOn(CarbonInterface|string $date): ?int` — rent_cents of latest `RentRate` with `effective_from <= date`, null if none.
- `RentalAgreement::isWholeUnit(): bool` (`bed_id === null`).
- `Unit::occupiedCountOn($date): int`, `Unit::displayStatus($date = today): string`.
- `BillingAccount` sets `account_no = 'JT-'.str_pad(id,6,'0',STR_PAD_LEFT)` in `created` event.
- Relations: Property hasMany units/tenants/billingAccounts, belongsToMany users (staff); Unit hasMany beds/agreements/occupancies; BillingAccount belongsTo payer (Tenant) hasMany agreements/deposits; RentalAgreement hasMany rentRates/occupancies.
- `User::properties()` belongsToMany; `User::canAccessProperty(int|Property $p): bool` (owner/accountant true; staff only if assigned).

- [ ] Tests `tests/Feature/Domain/ModelsTest.php`: `account_no` is `JT-000001` for first account; `rentOn('2026-01-15')` returns 250000 with rates [2026-01-01: 250000, 2026-03-01: 270000] and `rentOn('2026-03-01')` 270000 and `rentOn('2025-12-31')` null; ActivityLog update throws `LogicException`, delete throws; `canAccessProperty` owner true / assigned staff true / unassigned staff false / accountant true; `displayStatus` returns `vacant`, `available`, `full`, `maintenance` in the four set-ups.
- [ ] FAIL → implement → PASS → full suite → commit `feat: property/tenant schema and models`.

### Task 2.2: Properties, units, beds — policies + screens

**Files:** `app/Policies/{PropertyPolicy,UnitPolicy}.php`; `app/Http/Controllers/{PropertyController,UnitController}.php`; FormRequests `StorePropertyRequest`, `UpdatePropertyRequest`, `StoreUnitRequest`, `UpdateUnitRequest`; routes (resource, auth); pages `resources/js/pages/properties/{index,show,form}.tsx`; sidebar link "Properties".

**Behaviour:** index lists properties the user can access with rooms count, capacity, occupied, vacant beds. Show = units table (code, building/floor, mode, capacity, occupied, display status badge, current occupants). Owner can create/edit property, add/edit units, assign staff to property (multi-select of staff users on property form). Creating a `per_bed` unit creates beds `Bed 1..capacity`; editing capacity up adds beds, down removes trailing beds only if those beds have no active/future occupancy else 422 `capacity`. Changing `rent_mode` with active agreements → 422.

- [ ] Tests `tests/Feature/PropertyTest.php`: owner creates property (redirect, row); staff POST → 403; staff index shows only assigned properties (assert Inertia prop count); staff GET unassigned property → 403; accountant can view, cannot create (403); per_bed unit capacity 4 creates 4 beds; capacity 4→2 with Bed 3 occupied → error `capacity`; duplicate unit code in same property → error `code`; same code in another property → ok.
- [ ] FAIL → implement → PASS → `npx tsc --noEmit` + build → commit `feat: properties, units and beds`.

### Task 2.3: Tenants + billing accounts — screens

**Files:** `app/Policies/TenantPolicy.php`; `TenantController` (index, create, store, show, edit, update, archive via `PATCH tenants/{tenant}/archive`); requests; pages `resources/js/pages/tenants/{index,show,form}.tsx`; sidebar "Tenants".

**Behaviour:** index: search (name/email/mobile), filters property / status (active, no_email, vacated, archived), columns: name, property, unit/bed, current rent (`Money` format, from active agreement `rentOn(today)`), due day, email ("No email" badge), status; paginated 25. Show: contact card, agreements with rate history, occupancy history, deposits. Staff may create/edit tenants only in assigned properties. Archive refuses (422) when tenant has an active agreement or occupancy; tenants are never hard-deleted (no destroy route).

- [ ] Tests `tests/Feature/TenantTest.php`: staff creates tenant in assigned property ok, unassigned → 403; staff cannot view tenant of other property (403); search finds by partial name; filter `no_email` returns only tenants with null email; archive with active agreement → error; archive without → `archived_at` set and Audit row `tenant.archived`; no DELETE route exists (`route:list` has no tenants.destroy — assert `Route::has('tenants.destroy') === false`).
- [ ] FAIL → implement → PASS → tsc + build → commit `feat: tenants and billing accounts`.

### Task 2.4: Agreements + capacity/overlap (TenancyService)

**Files:** `app/Services/TenancyService.php`; `RentalAgreementController@store` (owner only) + `StoreAgreementRequest`; agreement dialog on tenant show page ("Start rental"): unit, bed (per_bed units: free beds only), rent (pesos input), due day, cycle start day, issue lead days, prorate, start date, residents (whole-unit: other tenants listed as non-paying occupants), deposit amount optional.

**Produces:**
- `TenancyService::startAgreement(Tenant $payer, Unit $unit, ?Bed $bed, int $rentCents, int $dueDay, CarbonInterface $startDate, array $options = []): RentalAgreement` — options: `cycle_start_day`, `issue_lead_days`, `prorate`, `end_date`, `resident_tenant_ids` (whole only), `deposit_cents`, `override_reason` (owner only, bypasses overlap — never capacity). In one DB transaction with `lockForUpdate()` on the unit row: finds or creates the payer's active BillingAccount for the unit's property; creates agreement + first RentRate (`effective_from = start_date`) + payer Occupancy (`is_payer=true`) + resident Occupancies (`is_payer=false`) + optional SecurityDeposit; `Audit::log('agreement.created', …)`.
- Throws `Illuminate\Validation\ValidationException` with keys: `bed` (bed required for per_bed unit / forbidden for whole unit / bed not in unit), `unit` (overlap), `capacity` (would exceed), `unit_status` (maintenance/unavailable).
- Overlap rule: agreements overlap if `a.start <= b.end_or_∞ && b.start <= a.end_or_∞` and status active. Whole-unit agreement conflicts with ANY active overlapping agreement on the unit; per-bed conflicts with an overlapping agreement on the same bed or an overlapping whole-unit agreement.
- Capacity rule: count of occupancies on the unit overlapping the new period + new occupants > capacity → `capacity`.

- [ ] Tests `tests/Feature/TenancyServiceTest.php` (spec acceptance): Room A101 per_bed capacity 4 — Ana ₱2,500 due 5th, Ben ₱2,700 due 12th, Cara ₱2,400 due 18th, Dan ₱3,000 due 28th all succeed with 4 separate billing accounts and distinct `rentOn()`/`due_day`; a 5th → `capacity`; whole-unit agreement for B201 capacity 4 with payer + 3 residents creates 1 billing account, 1 agreement, 4 occupancies (1 payer); second whole agreement overlapping → `unit`; starting after first ends → ok; per_bed on whole unit → `bed`; overlap with `override_reason` by owner → ok and logged, but capacity still enforced; unit in maintenance → `unit_status`; HTTP: staff POST agreement → 403; rent input "2,500.00" stored as 250000.
- [ ] FAIL → implement → PASS → tsc + build → commit `feat: rental agreements with capacity and overlap rules`.

### Task 2.5: Rent changes, transfers, move-out, deposits

**Produces (all on TenancyService, all transactional, all audited, owner-only HTTP routes):**
- `changeRent(RentalAgreement $a, int $rentCents, CarbonInterface $effectiveFrom, string $reason): RentRate` — `effective_from` must be ≥ agreement start; duplicate date → validation error `effective_from`. Never edits existing rate rows.
- `moveOut(RentalAgreement $a, CarbonInterface $date, string $reason = null): void` — sets agreement `end_date`, status `ended` if date ≤ today else stays active until then (status computed: treat `end_date < today` as ended in queries via scope `activeOn($date)`); closes occupancies of that agreement (`move_out_date`); closes billing account if no other active agreement.
- `transfer(RentalAgreement $a, Unit $toUnit, ?Bed $toBed, CarbonInterface $date, ?int $newRentCents = null): RentalAgreement` — ends old agreement the day before `date`, starts a new agreement for the same billing account on the new unit from `date` (same due day/settings, rent = new or current), moves resident occupancies (whole) along; same capacity/overlap rules as `startAgreement`.
- Deposits: `recordDeposit(BillingAccount, int $cents, date, ?RentalAgreement, ?string $notes)`; status changes (`applied/refunded`) are Phase 4 (payments). Deposit never touches rent.
- Pages: tenant show gets actions "Change rent", "Transfer", "Move out", "Record deposit" (owner only; hidden for others AND enforced server-side), each a dialog with plain-language helper text and confirmation.

- [ ] Tests `tests/Feature/TenancyChangesTest.php`: rent 2,500 → 2,800 effective 2026-03-01: `rentOn('2026-02-28')` 250000, `rentOn('2026-03-01')` 280000, and the original RentRate row unchanged; change with effective before start → error; move out 2026-04-15 → occupancies closed, bed free for an agreement starting 2026-04-16 but not 2026-04-15; transfer A101 Bed 1 → A102 Bed 2 on 2026-05-01: old `end_date` 2026-04-30, new agreement same billing_account_id, rent carried; transfer into full unit → `capacity`; staff POST change-rent → 403; every action writes an ActivityLog row with reason.
- [ ] FAIL → implement → PASS → tsc + build → commit `feat: rent changes, transfers, move-outs, deposits`.

### Task 2.6: Demo seed data + nav

**Files:** `database/seeders/DemoSeeder.php` (called from DatabaseSeeder only when `app()->environment('local','testing')` or `--class=DemoSeeder`), using TenancyService (no raw inserts for agreements).

**Seed (all names prefixed/suffixed "Demo", fake PH addresses):** Property 1 "Riverside Demo Residences" (`RIV`, boarding_house): A101 per_bed cap 4 with Ana Demo ₱2,500/5th, Ben Demo ₱2,700/12th, Cara Demo ₱2,400/18th (no email), Dan Demo ₱3,000/28th; A102 per_bed cap 2 one occupant; A103 maintenance. Property 2 "Hillside Demo Apartments" (`HIL`, apartment): B201 whole cap 4 — "Santos Family Demo" payer ₱9,000 due 10th + 3 residents; B202 vacant whole cap 2. Staff user assigned to Riverside only. Start dates 2026-09-01. (Opening debt, rice cooker, utilities, payments are seeded in Phases 3–4 by extending this seeder.)

- [ ] Test `tests/Feature/DemoSeederTest.php`: after `$this->seed(DatabaseSeeder::class)`: 2 properties; A101 has 4 active agreements with due days {5,12,18,28} and 4 distinct rents; Cara has null email; B201 has 1 agreement and 4 occupancies; staff can access Riverside only.
- [ ] `php artisan migrate:fresh --seed` on dev DB → OK. Full suite, tsc, build → commit `feat: demo seed data for properties and tenants`.
