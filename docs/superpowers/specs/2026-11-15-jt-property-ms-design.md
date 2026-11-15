# JT Property Management System — Design (short)

Authoritative spec: `JT_Property_Management_MASTER_PROMPT.md`. This doc records only the decisions and deviations.

## Intent
Philippine landlord tool: per-tenant rent and due dates, recurring add-ons, utilities, opening balances,
one-click monthly bills, payments with allocation, branded A4 PDF statements. Correct money first, looks second.

## Decisions (user)
- **Laravel 12** instead of 13 — host PHP is 8.2.12 (XAMPP); Laravel 13 needs ^8.3. User chose 12 on 2026-11-15.

## Decisions (agent calls — correct me)
- DB: XAMPP MariaDB 10.4.32, new database `jt_property`. Existing databases untouched.
- Brand assets were not supplied: placeholder SVG wordmark + favicon using the spec palette
  (navy `#064578`, teal `#007A69`, mint `#24C5A4`, canvas `#F5F9FC` / `#101D2D`). Swap files in `public/branding/` later.
- PDF reference image not supplied: statement layout follows §9 text (header, amount-due box, summary, line table, pay stub).
- Money: integer centavos everywhere (`BIGINT` columns named `*_cents`), formatted `₱1,234.56` only at the edge. No floats.
- Roles: simple `role` enum on users (owner, staff, accountant) + policies; property assignment via pivot for staff.
  No permissions package.
- PDF: `spatie/laravel-pdf` + Browsershot with local Chrome; fallback documented (dompdf).
- AR subledger: `ledger_entries` (charge/opening/payment/credit/reversal) + `payment_allocations`; invoices post only current charges.

## Approaches considered
1. **Monolith per spec, phased (chosen)** — Laravel 12 + React starter kit, services per spec §12.
2. Full double-entry GL — rejected; spec asks for an AR subledger only.
3. Separate SPA + API — forbidden by spec.

## Non-goals
BIR-compliant official receipts, multi-org SaaS, payment-gateway QR codes.

## Left to builder
Table-level detail, UI composition, per-phase task breakdown (see plan).
