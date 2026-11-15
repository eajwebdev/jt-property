# JT Property Management System — Agent Build Checklist

Mark items [x] only after working code and tests confirm them. Use the master prompt as the authoritative functional specification.

## Foundation
- [x] Exactly one Laravel repository and Laravel-managed React/Inertia UI
- [x] PHP 8.3+ compatible with Laravel 13; MySQL is connected — *owner chose Laravel 12 on PHP 8.2.12 (2026-11-15); MariaDB 10.4 `jt_property` connected*
- [ ] Owner/staff permissions; mobile-friendly sidebar; light/dark theme — *roles, owner-only user mgmt, deactivation and per-user Light/Dark/System done + tested; mobile layout not yet checked in a browser (Phase 6)*
- [x] JT logos, favicon and brand color palette installed
- [x] Seed data and README setup commands verified — *`migrate:fresh --seed` OK; property/tenant demo data comes in Phase 2–4*

## Property and tenant setup
- [x] Multiple properties, rooms, bed capacity and occupancy histories
- [x] Whole-room single-payer lease and individual per-bed leases work
- [x] Each tenant has independent rent and due date
- [x] Room capacity and date overlap validation
- [x] Lease move-in/out, transfers, deposits and rate histories — *verified 2026-11-16: 184 tests incl. A101 4-tenant acceptance, peak capacity, inclusive overlap, transfer rollback*

## Billing correctness
- [ ] Per-tenant issue and due dates incl. 29th/30th/31st and February
- [ ] ₱200 rice cooker recurring add-on bills exactly once per cycle
- [ ] Other one-time/recurring categories; effective start/end dates
- [ ] Metered/fixed/shared/inclusive water and electric billing
- [ ] Missing utility charges flagged before finalizing
- [ ] Opening unpaid balance migration without double-posting
- [ ] Preview all eligible bills for selected month
- [ ] One-click generation is atomic, retryable and duplicate-proof
- [ ] Current invoice charges separate from previous outstanding debt
- [ ] Bill math, adjustment, payment and credit reconciliation tests pass

## Payment and monthly statement
- [ ] Cash/bank/GCash/Maya and optional proof of payment
- [ ] Partial, complete, advance, refund and reversal workflows
- [ ] Payment allocations and aging report reconcile
- [ ] Branded A4 PDF layout: prior balance, current charges, payments, amount due
- [ ] PDF generated from real server data and visual regression checked
- [ ] Individual PDF, ZIP-all, combined PDF and printing work
- [ ] Receipt/payment acknowledgment separate from an unpaid bill

## Operations and release
- [ ] Optional auto-email only with usable tenant emails
- [ ] SMTP test, queued mail, retries, sent/error log
- [ ] Optional scheduler enabled with no duplicate billing
- [ ] Reports: aging, rent roll, collections, occupancy, utilities, deposits
- [ ] No PDF/attachment personal-data exposure across properties
- [ ] Validated form inputs and protected owner-only financial actions
- [ ] `composer test`, front-end build, migration check run successfully
- [ ] Responsive + dark mode + long PDF / large batch edge cases tested
- [ ] Queue worker, scheduler, SSL, backups, SMTP, PDF engine operational
- [ ] Demonstrated end-to-end workflow and honest outstanding issues list
