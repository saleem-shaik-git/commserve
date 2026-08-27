# CommServe Demo Bank

A simulated online banking platform built with PHP 8.3+, MySQL 8 / MariaDB and Bootstrap 5.

> **DEMO ONLY:** This application uses simulated money and does not connect to real banking rails, card networks, blockchains or payment providers. No real funds are processed.

## Implemented phases

### Phase 1 — Core Foundation
- PHP MVC-style structure (page controllers + service layer, no framework)
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
- OTP challenges (hashed, expiring, attempt-capped)
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
- Statements and statement downloads (PDF/CSV, no external dependencies)
- Transaction PIN/security controls

### Phase 5 — Payments & Scheduled Payments
- Simulated biller catalogue (electricity, internet, cable, airtime, data, water)
- Ledger-backed bill payments
- Transaction PIN + OTP confirmation
- Bill-payment idempotency
- Bill-payment history and notifications
- Beneficiary payments
- One-time scheduled payments
- Daily/weekly/monthly recurring payments
- Scheduled beneficiary transfers and bill payments
- Safe atomic scheduler claiming
- Admin biller catalogue and payment metrics

### Phase 6 — Cards & ATM
- Simulated virtual/debit cards (demo Visa/Mastercard networks)
- Card numbers/CVV stored hashed; full details shown once at issuance
- Card freeze/unfreeze, card PIN, per-card daily and per-transaction limits
- Online/POS card payments with idempotency
- ATM withdrawal simulator with terminals

### Phase 7 — Compliance & Risk
- Simulated KYC submission and admin review
- Risk rules: high-value, very-high-value, velocity (10/20 per 24h), new-account activity, KYC-gated
- Risk scoring with high/critical enforcement gates
- Risk alert workflow (reviewing/cleared/blocked) and compliance event log

### Phase 8 — Notifications
- Template-driven notifications (in-app, email, sms)
- Per-user channel preferences
- Queued delivery with retries (`scripts/process-notifications.php`)
- Email via PHP `mail()`; SMS intentionally unconfigured in demo mode

### Phase 9 — Security Hardening
- Login lockout (8 failed attempts / 15 minutes per email or IP)
- Password policy enforcement and password-change events
- Security activity trail (`security_events`)
- Session tracking/revocation tables

### Phase 10 — Reporting & Analytics
- Reports & Analytics and Executive Intelligence dashboards (Chart.js)
- Daily volumes, transaction mix, customer segments, top customers
- CSV export with export audit logging

### Phase 11 — USD Currency & Internationalization
- Default currency USD across schema and UI
- English / Spanish / French interface with per-user persisted locale
- Versioned data normalization migrations (015–018)

### Crypto — Simulated Multi-Asset Wallets
- Per-user wallets for BTC, ETH, USDT, USDC, XRP at static demo rates
- Simulated receive, PIN-verified send, cross-asset conversion
- Separate `crypto_*` ledger (sandboxed from the fiat ledger by design)

### Phase 12 — 4-Stage OTP Transfers with Admin Approval
- Transfers require **four sequential OTP stages**: Identity → Amount → Beneficiary → Final authorization
- Each stage issues a fresh 6-digit OTP (hashed, 10-minute expiry, 5 attempts per stage); a stage stepper tracks progress on the confirmation page
- After stage 4 the transaction becomes `awaiting_approval` and an approval request is raised automatically
- Admins release or reject transfers from **Admin → Approvals** (release posts the ledger entries atomically with account locking and re-validated balances; rejection fails the transaction with a reason)
- Customers are notified at each decision point (`transfer_pending_approval`, `transfer_completed`, `transfer_rejected`)
- Ledger entries only post on admin release — funds never move on OTP verification alone

## Setup

1. Copy `.env.example` to `.env` and configure MySQL.
2. Create the database and import `database/schema.sql`.
3. Import `database/seed.sql` for demo accounts.
4. Point Apache/Nginx document root to `public/`. The app is served under
   `/commserve` by default (base path auto-detected; override with `BASE_PATH`
   in `.env` when the document root already is `public/`).
5. Ensure PHP has PDO MySQL enabled.
6. Run the migration runner (required for billers, OTP, cards, compliance,
   notifications, reporting and crypto tables):

```bash
php database/migrate.php      # Windows: php database\migrate.php
```

The migration runner applies numbered migrations once and verifies checksums on
later runs. If you imported only `schema.sql` and then opened Bill Payments, the
app also applies missing migrations automatically on the next page load.

## Background workers

The scheduler and notification queue are CLI workers; call them from Windows
Task Scheduler, cron, or another local scheduler:

```bash
php scripts/run-scheduled-payments.php   # due scheduled transfers/bill payments
php scripts/process-notifications.php    # queued notification delivery
```

Optional integrity check (exits non-zero on mismatch):

```bash
php database/reconcile.php
```

The workers only process simulated CommServe transactions and never contact
external payment networks.

## Security notes

- All state-changing forms are CSRF-protected; passwords and transaction PINs
  are bcrypt-hashed; OTPs are SHA-256-hashed and expire.
- Demo OTPs are displayed on screen because there is no real SMS/email gateway
  in demo mode.
- Change seeded passwords before using outside a local demo environment.

## Demo accounts

Demo accounts are documented in `database/seed.sql`:

| Login | Role | Password |
|---|---|---|
| `admin@commserve.test` | admin | `password` |
| `john@commserve.test` | customer (Savings `0100000001`, Current `0100000002`) | `password` |
| `jane@commserve.test` | customer (Savings `0100000003`) | `password` |

Each user must set a transaction PIN (top-right menu → Transaction PIN) before
transferring, paying bills or sending crypto.
