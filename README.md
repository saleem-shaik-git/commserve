# CommServe Demo Bank

A simulated online banking platform built with PHP 8.3+, MySQL 8 and Bootstrap 5.

> **DEMO ONLY:** This application uses simulated money and does not connect to real banking rails or process real funds.

## Implemented phases

### Phase 1 — Core Foundation
- PHP MVC-style structure
- PDO/MySQL database layer
- Secure session authentication
- Customer registration/login/logout
- Role-based admin foundation
- Bootstrap responsive customer/admin layouts
- CSRF protection
- Password hashing
- Database schema and seed data

### Phase 2 / 2B — Banking Engine & Transaction Security
- Ledger-backed deposits, withdrawals and transfers
- Transaction PIN
- Transfer limits
- Idempotency
- OTP challenges
- Transaction events
- Reconciliation
- Safe migration runner/versioning
- Demo opening balances and funding

### Phase 3 — Banking Operations/Admin
- Customer and account operations
- Transaction search and inspection
- Reversals/refunds with maker-checker approval
- Audit logs
- Reconciliation dashboard
- Admin transfer limits
- Biller catalogue administration

### Phase 4 — Customer Internet Banking
- Dashboard
- Account management and account history
- Transfers
- Beneficiaries
- Transaction history/details
- Statements and statement downloads
- Transaction PIN/security controls

### Phase 5 — Payments & Scheduled Payments
- Simulated biller catalogue
- Electricity, internet, cable, airtime, data and water services
- Ledger-backed bill payments
- Transaction PIN + OTP confirmation
- Bill-payment idempotency
- Bill-payment history and notifications
- Beneficiary payments
- One-time scheduled payments
- Daily/weekly/monthly recurring payments
- Scheduled beneficiary transfers
- Scheduled bill payments
- Safe atomic scheduler claiming
- Admin biller catalogue and payment metrics

## Setup

1. Copy `.env.example` to `.env` and configure MySQL.
2. Create the database and import `database/schema.sql`.
3. Import `database/seed.sql` for demo accounts.
4. Point Apache/Nginx document root to `public/`.
5. Ensure PHP has PDO MySQL enabled.
6. Run the migration runner (required for billers, OTP, and scheduled payments):

```powershell
php database\migrate.php
```

The migration runner applies numbered migrations once and verifies checksums on later runs.

If you imported only `schema.sql` and then opened Bill Payments, the app now applies missing migrations on the next page load. You can still run `php database\migrate.php` from the project root.

## Phase 5 scheduled payments

The scheduler is a CLI worker and should be called by Windows Task Scheduler, cron, or another local scheduler:

```powershell
php scripts\run-scheduled-payments.php
```

For a Windows demo environment, run it periodically with Task Scheduler. The worker only processes simulated CommServe transactions and never contacts external payment networks.

## Demo accounts

Demo accounts are documented in `database/seed.sql`. Change seeded passwords before using outside a local demo environment.
