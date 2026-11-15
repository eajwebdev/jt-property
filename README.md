# JT Property Management System

Web app for managing rental properties: tenants, billing, payments, balances and statements. Built as a single Laravel monolith with React (Inertia), TypeScript, Tailwind and shadcn/ui. Amounts are in pesos, timezone Asia/Manila.

## Requirements

- PHP 8.2+ and Composer
- Node 20+
- MySQL or MariaDB (XAMPP works)
- Google Chrome (used later to generate PDF statements)

## Setup (Windows / XAMPP)

1. Start Apache and MySQL in XAMPP.
2. Create two empty databases: `jt_property` and `jt_property_test`.
3. In the project folder run:

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

4. For development run `composer run dev`, then open the URL it prints. Use `npm run build` instead if you only want to view the app.

## Demo logins

Created by `php artisan migrate --seed`. Password is `password` unless you set `SEED_PASSWORD` in `.env` before seeding.

| Role | Email |
|---|---|
| Owner | owner@jt.test |
| Staff | staff@jt.test |
| Accountant | accountant@jt.test |

There is no public sign-up. The owner adds users under Settings > Users.

## Tests

```powershell
npm run build
php artisan test
```

Tests use the `jt_property_test` database. Production deployment notes will be added in a later phase.