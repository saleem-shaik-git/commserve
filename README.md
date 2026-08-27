# CommServe Bank

An online banking platform built with PHP 8.3+, MySQL 8 / MariaDB and Bootstrap 5.

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
- Opening balances and account funding

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
- Biller catalogue (electricity, internet, cable, airtime, data, water)
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

### Phase 6 — Cards
- Virtual/debit cards (Visa/Mastercard-style networks)
- Card numbers/CVV stored hashed; full details shown once at issuance
- Card freeze/unfreeze, card PIN, per-card daily and per-transaction limits
- Online/POS card payments with idempotency

### Phase 7 — Compliance & Risk
- KYC submission and admin review
- Risk rules: high-value, very-high-value, velocity (10/20 per 24h), new-account activity, KYC-gated
- Risk scoring with high/critical enforcement gates
- Risk alert workflow (reviewing/cleared/blocked) and compliance event log

### Phase 8 — Notifications
- Template-driven notifications (in-app, email, sms)
- Per-user channel preferences
- Queued delivery with retries (`scripts/process-notifications.php`)
- Email via PHP `mail()`; SMS requires an external provider to be configured

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
- Full interface translation (navigation, pages, forms, statuses, admin console) and localized dates
- Versioned data normalization migrations (015–018)

### Crypto — Multi-Asset Wallets & Live-Rate Trading
- Per-user wallets for BTC, ETH, USDT, USDC, XRP
- **Live USD rates** from a public market feed (CoinGecko simple price, refreshed at most once a minute, cached in `crypto_assets`); automatic fallback to the bank reference rate when the feed is unreachable
- **Buy crypto with account balance and sell back to the account** — fiat legs post through the ledger (`crypto_purchase` / `crypto_sale` transactions); crypto legs through the crypto ledger
- Receive, PIN-verified send, cross-asset conversion at live rates
- Separate `crypto_*` ledger (sandboxed from the fiat ledger by design, joined only by buy/sell)

### Phase 12 / 13 — 4-Stage OTP Transfers with Admin Approval of Every Stage
- Transfers require **four sequential OTP stages**: Identity → Amount → Beneficiary → Final authorization
- Each stage issues a fresh 6-digit OTP (hashed, 10-minute expiry, 5 attempts per stage); a stage stepper tracks progress on the confirmation page
- **Every submitted OTP stage (1–4) is queued for administrator approval** (`Admin → Approvals`, action type `otp_stage_approval`):
  - Approving stages 1–3 marks the stage approved and issues the OTP for the next stage (the customer is notified and sees the new OTP on the confirmation page)
  - Rejecting a stage fails the transfer with a reason and notifies the customer
  - Approving stage 4 is the final sign-off: it releases the transfer — the ledger entries post atomically with account locking and re-validated balances
- Customers are notified at each decision point (`otp_stage_approved`, `otp_stage_rejected`, `transfer_completed`, `transfer_rejected`)
- Ledger entries only post on admin approval — funds never move on OTP verification alone

### Phase 14 — Live Chat & Crypto Trading
- Live chat between customers and administrators (`support-chat.php` <-> `admin/support-chat.php`)
- Threads with subjects, unread badges on both sides, open/close/reopen, 5-second polling (no server daemon needed)
- Buy/sell crypto against the fiat account balance at live USD rates (see Crypto section above)

### Phase 16 — Retail Banking Products
- **Configurable savings products** (Regular/Premium/Target/Student/Business Savings): rate, minimum opening & daily balance, interest frequency, withdrawal restrictions (none/limited/restricted/locked) and status - managed in **Admin → Banking Products**
- **Interest engine**: daily balances derived from the ledger, interest accrued per product and **posted as real ledger entries** (`interest_credit` transactions + `interest_postings` audit); daily/monthly frequencies; idempotent per period (`scripts/run-daily-banking.php` or the admin engine button)
- **Fixed/term deposits**: placement debits the account, simple interest at the product rate, maturity processing, early withdrawal with configurable interest penalty, payout posts to the ledger
- **Lending**: application → KYC gate → credit-scored decision (auto-approve ≥700, manual review 600–699, reject <600) → admin approval → **disbursement** (ledger credit) → amortized monthly **repayment schedule** → ledger-backed repayments (interest first, then principal; prepayment supported) → late/default processing (3+ late installments = defaulted)
- **Credit scoring (300–850)** from account age, activity, balances, loan/repayment history, failed payments, risk alerts and KYC
- **Customer lifecycle**: Registered → KYC Pending → KYC Approved → Active → Dormant (computed) plus admin overrides (Restricted/Closed); visible on Admin → Customers
- **Admin → Product Analytics**: deposits by product, interest expense, fixed-deposit book, loan portfolio, outstanding, repayment rate, default rate, adoption, lifecycle mix and a profitability simulation

## Setup

1. Copy `.env.example` to `.env` and configure MySQL.
2. Create the database and import `database/schema.sql`.
3. Import `database/seed.sql` for the seeded accounts.
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
php scripts/run-daily-banking.php        # interest posting, deposit maturities, loan arrears
```

Optional integrity check (exits non-zero on mismatch):

```bash
php database/reconcile.php
```

## Security notes

- All state-changing forms are CSRF-protected; passwords and transaction PINs
  are bcrypt-hashed; OTPs are SHA-256-hashed and expire.
- OTPs are displayed on the confirmation page because no SMS/voice gateway is
  configured; the display copy is stored alongside the hash (migration 020).
- Change seeded passwords before using outside a local environment.

## Seeded accounts

Seeded accounts are documented in `database/seed.sql`:

| Login | Role | Password |
|---|---|---|
| `admin@commserve.test` | admin | `password` |
| `john@commserve.test` | customer (Savings `0100000001`, Current `0100000002`) | `password` |
| `jane@commserve.test` | customer (Savings `0100000003`) | `password` |

Each user must set a transaction PIN (top-right menu → Transaction PIN) before
transferring, paying bills or sending crypto.
