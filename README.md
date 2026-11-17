# JT Property Management System

Web app for managing rental properties: tenants, billing, payments, balances and statements. Built as a single Laravel monolith with React (Inertia), TypeScript, Tailwind and shadcn/ui. Amounts are in pesos, timezone Asia/Manila.

## Requirements

- PHP 8.2+ and Composer
- Node 20+
- MySQL or MariaDB (XAMPP works)
- Google Chrome (required to generate PDF statements)

## Setup (Windows / XAMPP)

1. Start Apache and MySQL in XAMPP.
2. Create two empty databases: `jt_property` and `jt_property_test`. Set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env` for your local MySQL account.
3. In the project folder run:

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

4. For development run `composer run dev`, then open the URL it prints. Use `npm run build` for a built frontend.
5. Run a queue worker and scheduler in separate terminals for background billing, email and due reminders:

```powershell
php artisan queue:work --tries=3 --sleep=3
php artisan schedule:work
```

The billing screen shows worker, scheduler, PDF and mail status to the owner. Monthly bills can be generated manually from **Billing → Generate Monthly Bills**. The preview shows account selections, charges, prior balances and validation flags before posting. Completed batches support ZIP, combined PDF, print and email actions. Scheduled generation and email are off by default; an owner may turn them on in billing settings. Due reminders use the same email opt in and per property switch.

Collections provides payment posting, approval, reversals, aging, account statements and payment acknowledgment PDFs. Reports includes aging with CSV export and monthly billed versus collected totals. The calendar shows scheduled due dates and recorded moves. The audit log is owner only.

## Demo logins

Created by `php artisan migrate --seed`. On this local installation, both demo accounts use password `password`. Running `php artisan db:seed` locally restores their passwords to the configured `SEED_PASSWORD`. Production seeding creates only the owner account and leaves an existing owner's password unchanged. The MySQL `DB_USERNAME`/`DB_PASSWORD` credentials are only for the database; they are not application logins.

| Role | Email |
|---|---|
| Owner | owner@jt |
| Staff | staff@jt |

There is no public sign-up. The owner adds users under Settings > Users. Users are deactivated, never deleted, so payment and audit history stays intact (a deactivated user is signed out and cannot sign in until reactivated).

Until SMTP is configured, password-reset emails are written to `storage/logs/laravel.log` (`MAIL_MAILER=log`). Billing emails and reminders are marked skipped when SMTP is not configured; they are never presented as delivered. Set `MAIL_MAILER=smtp`, host, port, username, password, encryption and a verified sender address before enabling automatic email. Use **Test SMTP** on the billing screen.

## Tests

```powershell
npm run build
php artisan config:clear
php artisan test
```

Tests use the `jt_property_test` database through `phpunit.xml`. Clear cached configuration before tests; a cached database connection can override the test setting. Never point tests at the live database.

## Production readiness checklist

- [ ] Use a dedicated production database account, set `SEED_PASSWORD`, then run `php artisan migrate --force` and `php artisan db:seed --force` to create `owner@jt`; verify database backups and a restore on a separate database.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, a secure `APP_KEY`, HTTPS, secure cookies, and a private writable `storage` directory.
- [ ] Configure a real SMTP provider and verified sender; send a test email. Keep automatic sending disabled until this succeeds.
- [ ] Run a supervised queue worker and scheduler continuously. Check failed jobs and the billing operations panel.
- [ ] Install Chrome for the PDF engine, confirm its configured path, and download a sample A4 bill, combined PDF, account statement and acknowledgment.
- [ ] Set a backup schedule for the database and private files, document retention, and test restoration. Protect the backups with access controls.
- [ ] Configure error reporting and review application, queue and mail logs.
- [ ] Replace demo credentials and remove fake demo records before real tenant data is entered.

For a database backup with XAMPP, use `mysqldump` with a privileged account and store the output outside the web root; restore into an empty **separate** database using `mysql`. Back up `storage/app/private` or the configured private disk with the same retention policy. Test that a restored copy can load accounts and download a historical statement before relying on the backup procedure.

The local demo database was dumped and restored to a separate temporary database on 16 November 2026; the restored users, properties, tenants and migration counts matched. This verifies the basic database restore path, not a production backup schedule or private file restore.
