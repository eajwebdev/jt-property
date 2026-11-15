# MASTER BUILD PROMPT — JT Property Management System

> Copy this entire document into your coding agent (Claude Code, Cursor, Codex, etc.). Attach the `assets/branding` folder and `reference/monthly-bill-visual-reference.png` from the companion starter ZIP. Implement the real working project, not just mock screens.

## 0. Your role, objective, and non-negotiable rules

You are a senior Laravel + React architect, full-stack developer, UI/UX designer, database engineer, billing-domain analyst, and QA engineer. Build **JT Property Management System**: an easy-to-use, secure, reliable rental house, boarding house, and room management system for a Philippine property owner/operator. The single biggest goal is to accurately manage different tenant rent amounts and due dates, add recurring appliance and utility fees, monitor previous outstanding balances, issue professional monthly PDF billing statements in bulk, and collect and reconcile payments.

**MANDATORY:** The frontend is INSIDE Laravel. Build ONE Laravel monolith/repository and deploy ONE application. Use **Laravel 13**, **PHP 8.2+**, **React + TypeScript**, **Inertia.js**, **Vite**, **Tailwind CSS**, **shadcn/ui**, **MySQL**, and Laravel's session-based authentication. Put React pages under `resources/js/pages` (or the official Laravel React starter kit's matching structure). Use Laravel `routes/web.php`, controllers, policies, services, jobs, database migrations, and Eloquent. DO NOT create separate `frontend/` and `backend/` applications, an independently deployed React SPA, or an unnecessary REST API for the browser. Use Inertia requests for screen interactions and Laravel server-side services for ALL financial calculations and approvals.

Use the official compatible Laravel React starter kit rather than creating auth from scratch. Use the versions actually compatible at setup time. Prefer testable, readable code. Use PHP enums, form requests, authorization policies, typed TypeScript interfaces, named routes, and database constraints. All money in Philippine pesos (PHP/₱), stored as `DECIMAL(15,2)` or safe integer centavos; NEVER floats for money. Use `Asia/Manila` timezone for business dates. Treat billing/payment records as financial records with immutable posted entries, audit trails, and explicit reversals rather than silently overwriting history.

**Environment check:** detect installed PHP, Composer, Node, npm, MySQL, and Chrome/Chromium before starting. Laravel 13 requires PHP 8.2 or higher; if the owner's XAMPP is PHP 8.2, report incompatibility and give upgrade instructions instead of installing unsupported Laravel 13 or changing the requested stack silently. Do not destroy any existing MySQL data. Supply an `.env.example` and safe setup instructions for Windows/XAMPP and a production Linux VPS.

## 1. Branding, comfortable light/dark UI, and design requirements

Use the supplied JT logo assets as the visual source of truth, not a generic AI/SaaS dashboard. Palette approximating the logo (adjust after examining assets): navy `#064578`, deep teal `#007A69`, mint accent `#24C5A4`, light canvas `#F5F9FC`, dark canvas `#101D2D`. Supporting slate neutral text and borders; accessible contrast on all surfaces. Navy for identity and title bars, teal for positive primary actions, muted warm amber for due-soon warnings, clear red for overdue balances, light gray borders. Never use saturated gradients behind long paragraphs or overly bright dark-mode cards.

Implement comfortable **Light / Dark / Follow system** themes persisted per user. Typography should be readable, not tiny; generous spacing, accessible labels, keyboard-friendly inputs, mobile-first responsive layout, soft borders, meaningful empty states and loading states. Design for nontechnical landlords: user-friendly navigation, plain-language helper text, straightforward dialogs, large "Record Payment" and "Generate Monthly Bills" controls, excellent tables with filters and export. Do NOT create visually attractive but non-working placeholders.

Brand assets usage:
- Horizontal logo: login/header, PDF billing header, printable reports.
- Square app icon: dashboard/mobile/home-screen icon.
- Badge icon: print/branding alternate.
- `.ico` favicon: browser tab.
- Store files under Laravel `public/branding/`; use optimized PNG/WebP appropriately.

## 2. Users, permissions, and property setup

Implement owner/admin and manager/staff roles; optionally a read-only accountant role. Owner can configure properties, rates, billing, import opening balances, approve corrections, send statements, refund, and view audit log. Staff can manage tenants and record payments only within assigned permissions. Add permission-based protection to every Laravel controller/action, not just React buttons. Default to a single organization managing multiple properties, not an unnecessary multi-tenant SaaS infrastructure; however, scope all records by property and make future multi-organization expansion possible.

Support multiple properties with type: entire rental house, apartment, boarding house, dormitory, rooms, bedspaces, or other. Each property can have buildings/floors, room/unit numbers, capacity, shared beds, individual room rates, description, amenities, status (vacant/available/full/maintenance/unavailable), and optional shared utility meters. Maintain accurate occupied bed count, room capacity, and vacancy. Prevent exceeding capacity; prevent active overlapping occupancy of the same assigned bed/whole unit, with explicit authorized override only for legitimate records.

### Essential whole-room and shared-room behavior

A room may accommodate 4 people. The owner must be able to choose:
1. **Whole-room rental:** one agreement and billable account pays an owner-entered amount for the ENTIRE room; other occupants are listed as residents but are not separately invoiced unless specifically configured.
2. **Per-person / per-bed rental:** up to 4 active residents each has a separate tenant agreement, rent, due date, optional add-ons, billing account and payment history. Example: Ana ₱2,500/mo due the 5th, Ben ₱2,700/mo due the 12th, Cara ₱2,400/mo due the 18th, Dan ₱3,000/mo due the 28th, all in Room A101. DO NOT require equal room rent or equal due dates.
3. Optionally allow agreed shared-household payer groups, while ensuring each charge belongs to exactly one accountable billing account. Never bill both a group payer and its members for the same room rent.

Each tenant may have full name, optional email, mobile, emergency contact, lease start/end, move-in/move-out, tenant status, room/bed, rent amount, deposit, billing method, exact due-day or date, preferred delivery method, custom remarks, optional attachments, and consent/communication preferences. Handle room transfer and rent increases with effective dates and history, never rewriting invoices from old periods. Separately record deposits as refundable liabilities, not rent revenue or payment of an invoice unless expressly and properly applied.

## 3. Tenant-specific billing schedules (critical)

Each billable agreement controls its own:
- monthly rent (`rent_amount`), whether room-based or person-based;
- billing cycle anchor day and period definitions (e.g. month calendar vs 15th–14th);
- **due day/date** (e.g. the 5th, 12th, 18th, 28th), plus optional per-invoice manual override;
- invoice issue lead days / exact issue date and next eligible bill date;
- start/end dates, whether to prorate partial periods, and due grace/late-fee policy;
- special rates/discounts only where explicitly configured.

A due day of 29/30/31 on a shorter month must follow a documented configurable policy (default: clamp to last day of month). Respect leap years, month boundaries, mid-month move-ins and move-outs, rent changes, advance payments, and paused/ended agreements. Support optional proration, default **off** unless owner selects a policy; show calculation before saving. Due date is not necessarily the same as statement issue date or billing period end.

Provide a **Due Dates Calendar** and tenant list with Due Today, Due in 7 Days, Overdue, Paid, Partial, No Email, and Vacated filters. Make each due date and balance easily visible on desktop and mobile. Daily reminders should be configurable and must not send to tenants without the appropriate communication setup.

## 4. Custom charges: monthly appliances, one-time items, utilities

The owner can define charge categories (rent, rice cooker, refrigerator, aircon, electric fan, internet, parking, water, electricity, repair, cleaning, miscellaneous). Categories are customizable and line item labels must remain descriptive on the PDF.

**Recurring charges:** per tenant/account, with an owner-entered label, amount, frequency (monthly as default), effective start/end date, active switch, optional tax treatment if configured, and audit history. Example: Tenant Ana has "Rice cooker" recurring at ₱200/month effective November 1: EVERY eligible monthly invoice automatically includes a distinct line `Rice cooker — ₱200.00`, until ended. Changing or stopping the charge later must not alter past finalized invoices. Recurring fees must post **once per cycle**, not once per click. Support effective dates and optional one-time charges with period selection. Allow one-time credits, waivers, and notes with permission and audit trail.

**Water/electricity:** each property/room/tenant can be configured as inclusive in rent, fixed monthly amount, individually metered, submetered, or a split of a shared master bill. Track meter number, previous/current reading, readings date, rate per kWh/cubic meter, optional base charge, amount, evidence/receipt, and optional common area allocations. Support room-level meters shared by N residents with owner-configured allocation (equal active occupant share, fixed percentages, or manual amounts), ensuring sum of allocations equals the source bill after centavo rounding. When the room has only 3 residents for part of the period, use the explicit configured occupancy/proration rule, not silent guessing. Never charge an unentered/missing utility bill; preview must show "Needs utility input" and allow owner to hold or explicitly exclude that utility before finalization. Keep reading history immutable once billed; adjustments need credit/debit notes.

## 5. Opening balances / unpaid old bills from before launch (critical)

Provide an **Opening Balance Migration** wizard for every tenant/account: amount owed, "as of" date, description "Unpaid balance brought forward before JT System", optional breakdown per old month, original supporting notes, CSV import, review and approval. A positive opening receivable becomes a dated **opening accounts-receivable ledger entry**, distinct from newly generated monthly charges, and is eligible for payment allocation/aging. Allow partial payments of opening debt. Prevent importing it twice and do not mix it with security deposits or tenant advances.

If old invoices are also imported separately, the wizard must detect/avoid counting the same debt again. Corrections are documented by an authorized reversal/adjustment, with log and reason. The opening outstanding balance appears as `Previous Balance / Balance Brought Forward` on statements; it is NEVER re-posted as a fresh monthly rent charge.

## 6. ONE-CLICK GENERATE ALL MONTHLY BILLS (main feature)

Prominent dashboard action: **Generate Monthly Bills**. Workflow:
1. Owner selects billing target month/year (or "all eligible upcoming bills"), property or all properties, and optionally selected accounts. Each tenant's individual billing cycle, issue date, and due date remain intact.
2. Perform a non-posting PREVIEW/dry-run: per tenant/room, covered period, due date, base rent, recurring add-ons, utilities, adjustments, prior unpaid balance, existing credits/allocations, estimated current charges and statement total due. Flag missing utility readings, inactive leases, overlaps, missing tenant email, and existing bill.
3. Show a summary (eligible / already billed / needs review / skipped, total new charges, current prior outstanding, estimated total statement balance). Require clear owner confirmation for financial posting.
4. On **Confirm Generate** safely create only missing drafts/finalized invoices (according to configured workflow), immutable invoice line snapshots, references and due dates. Use MySQL transactions, row-level locks, unique constraints, deterministic per-agreement/cycle idempotency keys, and after-commit queued jobs; double-clicking or replaying the request cannot create duplicate rent or appliance fees.
5. After successful generation show status per tenant; allow View, PDF Download, Print, Email, and optional Bulk Finalize/Send according to permissions. Failed records can be retried individually without duplicating successful bills.
6. Auto-send email if enabled on the property and recipient has a usable email; otherwise show "Not sent — no email". Separate "Generate" from "Send" when configured to require owner approval. Automatic sending defaults OFF until SMTP/email templates are tested.
7. **Download All PDFs** for selected period: generate properly named PDFs in a ZIP; optionally combined multi-page PDF with page breaks, each tenant's statement clearly isolated. Print all.

Example: 40 tenants may have 40 different rents, payment due days, extra charges and balances, yet one action should create 40 correct bills (or mark exceptions) without copying manually.

Also include scheduled auto-generation as an optional owner-enabled mode using Laravel Scheduler and queues, with the exact same preview-approved rules, deduplication, logging, and configurable lead days. Provide a visible operational status panel for queue worker, cron, failed jobs and SMTP settings.

## 7. Billing mathematics and accounts-receivable correctness

Use a small **accounts-receivable subledger** with posted transactions and proper payment allocations; do not claim to implement a complete general ledger unless separately requested. Maintain record states for Draft, Finalized, Void (with reversals) and derived collection states Unpaid, Partially Paid, Paid, Overdue. Preserve bills and PDFs as originally issued; corrected finalized documents require traceable adjustments or a controlled void/reissue with unique references.

Clear formula in every statement:

- `Current Charges = Monthly Rent + Recurring Add-ons + Utilities + Approved One-time Charges + New Penalties - Current Discounts/Adjustments`
- `Previous Balance = total unpaid posted debt from previous periods, including opening balances, as of the statement cutoff`
- `Payments/Credits Applied = posted payments/credits reducing total receivable`
- `Total Amount Due = Previous Balance + Current Charges - eligible payments or credits not already reflected in Previous Balance/Current Charges`

**Do not subtract a payment twice.** Design a transaction-aware statement query with explicit cutoff timestamps. A PDF's `Previous Balance` must be the net amount already outstanding at the beginning of the current statement cycle, and payments received after that point show as separate `Payments Received / Credits Applied` on the statement. Include an item-level allocation ledger linking payments to original debts. The new invoice **posts only current new charges**; previous unpaid amounts are shown for information on the combined monthly statement and **are not posted again**. Distinguish `Current Invoice Total` and `Statement Total Amount Due`.

Example acceptance case:
- Opening unpaid debt: ₱1,800.00
- Current rent: ₱3,500.00
- Recurring rice cooker: ₱200.00
- Current electricity: ₱300.00
- Current water: ₱150.00
- Current charges = ₱4,150.00
- Statement total due BEFORE any further payment = **₱5,950.00**
- If a further ₱500.00 payment is received AFTER statement start and allocated, remaining amount due = **₱5,450.00**. Show the timing correctly, do not alter the original amount billed and do not subtract the same payment in the prior balance and again below.

Also handle negative balances (tenant credit), excess/advance payments, opening credit, per-invoice allocation, payment reversal, late fees if enabled, partial collection, tenant move-out, refunds and write-offs. Advance/unapplied payments should be separately recorded as credit/liability until allocated, and cannot silently inflate rental revenue. Do not show a bill as Paid unless posted allocations cover its balance; do not call a bill a receipt before payment.

## 8. Payments, collections and auditability

Record payments via Cash, bank transfer, GCash, Maya, check, or configurable methods, with amount, time, payer, collector, property, transaction reference, attachment/proof, notes, and approval status. Staff can log pending payments without pretending a screenshot is bank confirmation; manager can approve. Allocate payment to oldest unpaid charges by default but allow authorized manual allocation to specific invoice/opening balance. Support partial and excess payments, bulk recording when appropriate, reversal with reason, download/print **payment acknowledgment** and configurable receipt number. Distinguish this from a tax-compliant invoice/official receipt; do not falsely claim BIR compliance without a separately implemented compliant module.

Keep append-only activity/audit logs for every important financial action: who, when, old/new states, reasons, source, IP where appropriate, and related record. Do not allow deletion of payments or finalized invoices that would destroy history.

## 9. Professional PLDT-STYLE MONTHLY PDF BILLING STATEMENT

Create a polished, one-page-first, A4 portrait bill inspired by the **information hierarchy and practical print layout** of a professional telecom utility monthly statement, using JT's own blue/teal identity. The provided image is a VISUAL REFERENCE, not an authoritative bill, and contains fictional details. Do not copy PLDT names, logos, proprietary marks, or require exact trade dress. Target the same professional caliber, strong printed readability, consistent alignment, spacing, structured accounting terms, and clear total amount due.

Top area: JT horizontal logo; `MONTHLY BILLING STATEMENT`; invoice/statement reference; issue date; billing period; payment due date. Identity block: tenant/payer, account number, rental address, property/building, room/bed, optional email/contact. Distinct right/center `AMOUNT DUE ₱x.xx` emphasis with `DUE DATE DD MMM YYYY`.

Financial summary must clearly display:
- Previous Balance / Balance Brought Forward
- Payments Received / Credits Applied since prior statement cutoff
- Adjustments / Discounts / Late Fees (when applicable)
- Current Charges
- Total Amount Due / Outstanding Balance
- Amount Past Due separately if different from current balance

Detailed table with Description, Period / Usage / Meter Readings if relevant, Unit Rate, Qty (if applicable), Amount. Line item examples: `Monthly Rental – Room A101`, `Rice cooker (recurring)`, `Electricity – 42 kWh × ₱x`, `Water – Shared`, `Internet`, `Other Charges`. Include current-period subtotal. Show optional opening balance breakdown and a list of selected recent payments when space permits. Below: payment instructions (configured bank/GCash/Maya names, account details, reference number), notes/reminders, landlord contact. Footer: optional detachable **payment reference stub** containing account number, reference, name, total due and due date. Generate a QR/barcode only if its content is valid and usable; never display a pretend payment QR.

Use real currency formatting `₱1,234.56`, clear minus signs for credits, zero totals when paid, no clipped tables, logical repeating headers on additional pages, `Page X of Y` where appropriate. The same printable HTML must be user-previewable. Use a server-rendered Laravel Blade PDF view (the React application may display its HTML preview) so PDF output remains reliable and uniform. Prefer `spatie/laravel-pdf` with Browsershot/Chromium or another maintained driver meeting available production requirements; provide a documented fall-back for hosts without Chromium. Test actual PDF rendering with sample long addresses, many line items, long names, multi-page statements, and page break behavior. Protect private generated PDFs with authenticated authorization; never expose a guessable public URL. Keep an immutable copy of the issued statement.

Implement Download Current Bill, Bulk ZIP, Combined PDF, Print, Email PDF, and regeneration of historical copies without silently changing historic amounts. Also support account statements for a date range and proper payment acknowledgment PDFs, separate from monthly bills.

## 10. Email delivery, due notices, automation

Use Laravel Mail with configured SMTP provider, queue workers, and after-commit dispatch. Send tailored subject such as `JT Property — November 2026 Billing Statement — Room A101` with amount due, due date, billing period, payment steps, and attached private PDF. Never include another tenant's personal or billing data in an email. If tenant email is missing/invalid or communication is disallowed, mark skipped with reason instead of failing the whole batch. Maintain email delivery log: pending, queued, sent/accepted by provider, failed/retried (do not claim inbox delivery unless provider actually confirms). Avoid duplicate emails via delivery idempotency and configurable resend button. Expose Test SMTP feature and templates. Optional reminders X days before due, on due date, and X days after; no late fee added unless configured. Never send emails from draft preview. Support one-off resend, owner approval flow, throttling, and retry/backoff.

## 11. User-facing screens and navigation

Build a modern, responsive navigation with the following real working pages:
1. Overview Dashboard — KPI cards for total properties/rooms, occupied/vacant beds, amount billed this month, amount collected, still due, overdue, coming due, and utilities waiting for entry. Big actions: Add Tenant, Record Payment, Generate Monthly Bills, Download Bills. Recent activity and clickable due reminders.
2. Properties — property directory, floors, rooms, occupancy, capacity and vacant/full/maintenance status, room rent/occupant mode, utilities.
3. Tenants — searchable table with property/room, unique rent, next due date, balance, email, status; quick tenant details drawer; lease/rate history, attachments, bill/payment history.
4. Calendar — monthly due date and move-in/move-out calendar with all rental billing accounts.
5. Charges & Utilities — categories, recurring rice cooker and other appliances, fixed/one-time charges, meter readings, shared splits, pending utility validation.
6. Billing — period selector, one-click generate preview and confirmation, billing batch review, invoice table, status, PDF/print/email actions.
7. Collections — unpaid, partial, overdue and credited accounts; payment modal; allocation; follow-ups.
8. Opening Balances — migration wizard, reconciliation, corrections and CSV import.
9. Reports — outstanding balances/aging (current/1–30/31–60/61–90/90+), monthly collections and rent roll, bills vs payments, income per property, occupancy/vacancy, arrears, utilities and appliance revenue, deposits liability, unpaid opening balances, export to CSV/XLSX and PDF. Distinguish cash receipts from accrual billings.
10. Settings — branding, company details, bank/GCash/Maya pay instructions, billing policies, fees, SMTP, roles, dark/light mode, email templates, date formatting, timezone, audit log and backups.

Always include loading/empty/error states, confirmations for posting/voiding, pagination, contextual help, bulk actions, keyboard-friendly forms, and toast notifications. Build real table filtering and sorting. Use Filipino peso currency throughout and local phone/address formats. No fake metrics on production dashboards.

## 12. Suggested database/domain architecture

Do not force this exact list if a better normalized schema is justified, but implement the underlying relationships and migrations. Suggested tables:
`users`, `roles`/`permissions`, `properties`, `buildings` (optional), `units`, `beds`, `tenants`, `billing_accounts`, `rental_agreements`, `occupancies`, `rent_rate_histories`, `billing_schedules`, `charge_categories`, `recurring_charges`, `one_time_charges`, `meters`, `meter_readings`, `utility_bills`, `utility_allocations`, `opening_balance_entries`, `billing_batches`, `invoices`, `invoice_line_items`, `payments`, `payment_allocations`, `credits_and_adjustments`, `security_deposits`, `statement_snapshots`, `email_deliveries`, `attachments`, `activity_logs`, `app_settings`, `jobs`, `failed_jobs`.

Use a clean service architecture: `BillingCycleResolver`, `BillingPreviewService`, `InvoiceGenerationService`, `RecurringChargeService`, `UtilityAllocationService`, `OpeningBalanceService`, `ReceivableLedgerService`, `PaymentAllocationService`, `StatementComposer`, `StatementPdfService`, `BatchExportService`, `BillingEmailService`, `AgingReportService`. Ensure all derived figures come from server-side services and never trust browser-submitted monetary totals. Keep most domain services usable from HTTP controller, Artisan command, queues, and PHPUnit/Pest tests.

Define required unique keys, foreign keys, checks, and indexes (e.g. one invoice per billable account + agreement + billing cycle + invoice type; stable invoice number per property; unique payment reference where configured). Document valid lifecycle states and reversal/void rules. Do not permit deleting a tenant with financial history: archive instead.

## 13. Data safety, tests, sample data and quality gates

Security: validation, password reset, rate limits, CSRF, secure sessions, policies, property scoping, no open registration without owner's approval, private storage for PDFs/IDs, safe uploads (type/size), configured backups, secrets in `.env`, no production debug mode. Use accessible modern UI, not just visual mockups. All sensitive fields and documents must have access controls.

Generate factories/seeders for demo: 2 properties, one 4-bed shared room (4 tenants with DIFFERENT rates/due days), one whole-room account with family occupants, a tenant with an opening ₱1,800 debt, a rice cooker recurring ₱200, metered/shared water and electricity, one tenant without email, paid/partial/overdue cases. Seed only fake, clearly labeled people and addresses.

**Automated tests must include all of these acceptance criteria:**
- Four tenants in capacity-4 room, 4 separate rent rates and due dates, no over-occupancy.
- Entire room contracted to one payer does not invoice its additional residents.
- Rice cooker monthly ₱200 appears every active cycle, exactly once, stops on expiry, does not retroactively change past invoices.
- Electricity/water fixed, metered, shared and inclusive variants; allocations add to original bill; missing readings flagged.
- Opening balance ₱1,800 appears in Previous Balance but is not re-posted as rent every month.
- 3,500 + 200 + 300 + 150 = 4,150 in current charges; +1,800 prior debt = 5,950 statement amount due; a posted subsequent ₱500 leaves 5,450; no duplicate subtraction.
- Different February due-day behavior, including leap years and a requested 31st day.
- Manual click or queue retry twice creates **no duplicate** invoice, charges, receipts or emails.
- Partial payments, advance credits, cancellations, refunds, and manual allocations reconcile.
- Missing email skips sending safely, while PDFs still generate.
- Finalized PDF historical totals remain unchanged after future rent increase.
- Batch generation of 100+ tenants with no cross-account leakage; all failures logged with safe retry.
- Unauthorized staff cannot reverse payments, download other property files, or edit billing settings.
- Exported PDFs actually render A4 well and match the preview, even with long descriptions, multiple pages and accented/Filipino characters.
- Reports reconcile invoices, receivables and cash receipts to their source records.

## 14. Implementation order — code must be built, not merely described

**Phase 1: Foundation** — inspect environment; install the compatible Laravel React starter kit within a single Laravel root; MySQL connection; auth, roles, branding, light/dark mode, base layout, user permissions and seeded sample data. Run it locally. Do NOT keep developing on a broken foundation.

**Phase 2: Property & tenant management** — property/room/bed modules; whole-room vs per-person leasing; agreements, rates, due-date schedules, capacity validations, occupancy history, tenancy movement.

**Phase 3: Financial engine** — recurring add-ons, utilities, opening balances, monetary ledger, billing cycle/date resolver, preview, transaction-safe batch posting, complete balance calculation and bill status model; automated tests BEFORE sending emails.

**Phase 4: Collections & statements** — payment allocation, partial/advance collection, customer account statement, full branded A4 monthly bill in PDF, downloadable bulk ZIP and combined PDF; payment acknowledgment documents.

**Phase 5: Automation & reporting** — queued email, scheduled bill generation opt-in, reminders, operational queue visibility, property summaries, rent roll, aging, reconciliation, CSV exports, searchable audit log.

**Phase 6: Hardening & hand-off** — run migrations/factories/tests/build; test billing against edge cases and generated PDFs, responsive and dark mode checks, security review, backup/restore verification, Windows setup notes and production deployment notes. Create a production-readiness checklist in the README with indicators for DB, mail, queue worker, scheduler, file permissions, PDF engine, HTTPS, backups and error tracking.

## 15. Definition of done and explicit deliverables

Deliver:
- FULL runnable Laravel + React + Inertia source code **in a single Laravel repository**, not mock screens or separate apps.
- Real MySQL migrations, seeders, meaningful test suite, protected real routes/pages, and working billing calculations.
- Branded responsive light and dark UI based on provided assets.
- Accurate tenant-specific schedules, whole-room and shared-room modes, recurring appliance charges, utilities, opening debt migration, safe one-click billing, payments, professional PDF statements, bulk export, and optional auto-email.
- Setup `.env.example`, README, install scripts/commands, scheduler/queue worker instructions, PDF runtime dependencies, backup notes, and feature checklist.
- A demo walkthrough showing: create property -> Room A101/4 occupants -> individual rents and due days -> ₱200 rice cooker monthly -> import ₱1,800 old debt -> enter water/electricity -> preview all bills -> one-click generate -> download all PDFs -> email only accounts with valid addresses -> record partial payment -> see updated aging and statement.
- A concise completion report identifying which features really work, tests actually passed, and any incomplete/deferred work. Never falsely mark a feature complete or claim test success without running it.

**Agent execution expectations:** Begin by inspecting the repository and installed environment. If blank, scaffold the project. Make actual file changes, run the app and tests, fix errors, and proceed phase-by-phase without stopping after a plan. If some credentials or runtime prerequisites are genuinely unavailable, complete everything possible locally with config placeholders; explain the exact blocker and give the required setup steps. Do not use hard-coded production passwords, personal data, fake sent-email claims, or made-up payment confirmations. Prioritize the correctness of rent, balance, payment, and due-date calculations over cosmetic extras. Continue until the core workflows are tested and usable.

---

## Helpful official references (verify installation details at implementation time)
- Laravel React starter kit: https://laravel.com/starter-kits
- Laravel 13 requirements: https://laravel.com/framework/docs/13.x/deployment
- Laravel 13 queues: https://laravel.com/framework/docs/13.x/queues
- Laravel 13 scheduling: https://laravel.com/framework/docs/13.x/scheduling
- Laravel 13 mail: https://laravel.com/framework/docs/13.x/mail
- Spatie Laravel PDF drivers: https://github.com/spatie/laravel-pdf
