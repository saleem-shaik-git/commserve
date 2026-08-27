# CommServe Demo Bank — Full Application Study

> Fresh end-to-end review of every file in the repository (as of branch `arena/01a0410c-commserve`, commit `34bfdd8` / PR #9 merge).
> **DEMO ONLY** — simulated money, no real banking rails.

---

## 1. What the application is

CommServe is a **simulated internet-banking platform** built as a didactic demo of a real core-banking architecture:

| Layer | Technology |
|---|---|
| Language | PHP 8.3+ (strict types in services), no Composer / no framework |
| Database | MySQL 8 / MariaDB via PDO (prepared statements, `FOR UPDATE` row locks) |
| UI | Bootstrap 5.3 (CDN), Bootstrap Icons, Chart.js (admin), custom `app.css` |
| PDF | Hand-rolled `app/Lib/SimplePDF.php` (pure-PDF writer, Helvetica, A4, multipage) |
| Pattern | Flat page controllers in `public/` + service classes in `app/Services/` + shared helpers |

Everything is path-rooted at **`/commserve/public/`** (hardcoded in 46 files) — deployment expects the repo at `http://host/commserve` with docroot `public/`. `.htaccess` blocks directory listing and `.env`/`.git` access.

### Phase history (from git/migrations/PRs)

| Phase | Scope | Evidence |
|---|---|---|
| 1 | Foundation: MVC-ish structure, PDO, sessions, roles, CSRF, hashing | `schema.sql`, README |
| 2/2B | Banking engine: ledger, PIN, limits, idempotency, OTP, events, reconciliation, migration runner | migration `001` |
| 3 | Admin ops: customer/account ops, search, reversals/refunds w/ maker-checker, audit, limits, billers | migration `003` |
| 4 | Customer internet banking: dashboard, accounts, history, statements, PIN controls | migration `004` |
| 5 | Payments: biller catalogue, bill payments (PIN+OTP), scheduled payments (once/daily/weekly/monthly), beneficiary transfers | migrations `005`–`007_ensure…` |
| 6 | Cards & ATM: virtual/debit cards, card payments, ATM simulator | migration `007_phase6…` |
| 7 | Compliance & risk: KYC, risk rules, alerts, enforcement gate | migration `008` |
| 8 | Notifications: templates, 3 channels, preferences, queue | migrations `009`–`011` |
| 9 | Security hardening: security_events, login lockout, password policy, reset-token/session tables | migrations `012`–`013` |
| 10 | Reporting & analytics: reports/executive dashboards, CSV exports | migration `014` |
| 11 | USD default currency + i18n (en/es/fr), then full USD normalization | migrations `015`–`016`, PRs #8/#9 |
| Crypto | Multi-asset simulated crypto wallet (BTC/ETH/USDT/USDC/XRP) | migration `017`, PR #7 |

⚠️ The **README only documents Phases 1–5**; Phases 6–Crypto live in code/migrations but are undocumented there.

---

## 2. Runtime skeleton

```
app/bootstrap.php     .env parser (no Composer), env(), BASE_PATH, APP_NAME, DEFAULT_CURRENCY(USD), DEFAULT_LOCALE
app/database.php      Database::connection() — lazy PDO singleton; AUTO-APPLIES pending migrations when the
                      `billers` table is missing, plus a 007 safety-net SQL dump; adds beneficiaries.nickname
app/helpers.php       session boot (httponly/samesite=Lax/secure-from-env), e(), redirect(), csrf_token/field/
                      verify_csrf (hash_equals), auth_user(), require_auth(), require_role(), format_money
                      (always "$" regardless of currency arg), format_date, money_color, safe_count
app/i18n.php          SUPPORTED_LOCALES [en,es,fr]; t(); set_locale(); language_selector(); persists to users.locale
app/Database/MigrationRunner.php  numbered *.sql, sha256 checksums, skips 002, "already exists" tolerant,
                      phase-2 legacy baseline, checksum-change allowance for 005/006
public/partials/      header.php (topbar + sidebar + language selector), sidebar.php, footer.php
public/assets/css/app.css  hero cards, sidebar, timeline, OTP input, receipt styling
scripts/              run-scheduled-payments.php (cron worker → ScheduledPaymentService::runDue)
                      process-notifications.php (queue worker → NotificationService::processQueue)
database/             schema.sql (phase-1 base), seed.sql, migrate.php, reconcile.php, migrations/001–017
```

### Seed / demo data
- Roles: `customer`, `admin`. Account types: Savings, Current (both USD now).
- Users: `admin@commserve.test`, `john@commserve.test` (Savings `0100000001` + Current `0100000002`), `jane@commserve.test` (Savings `0100000003`). Password for all: `password` (bcrypt).
- Opening balances are written as `OPEN-<acct>` deposit transactions + ledger credits, so **the ledger is the source of truth** from first boot.
- Billers seeded: CommServe Electricity/Internet/Cable/Airtime/Data/Water. ATM terminals: COMM-ATM-001 (Lagos), COMM-ATM-002 (Ogun). Crypto assets: BTC 65k, ETH 3.2k, USDT/USDC 1.0, XRP 0.55.
- Registration auto-provisions a Savings account numbered `01` + zero-padded user id.

---

## 3. The money engine (most important part)

**Ledger-backed accounting.** `accounts.available_balance` is a *derived cache*; truth is:

```sql
SUM(CASE WHEN entry_type='credit' THEN amount ELSE -amount END)
OVER ledger_entries JOIN transactions WHERE status='completed'
```

Every money mover follows the same protocol:
1. `SELECT … FOR UPDATE` on affected accounts **sorted by id** (deadlock avoidance), plus `FOR UPDATE` on the transaction row.
2. Insert `transactions` row (status processing/pending) → insert paired `ledger_entries` (debit/credit) → mark completed → recalc cached balances — all in one DB transaction.
3. Idempotency via unique `transactions.idempotency_key` (or `bill_payments`/`card_payment_idempotency` keys); duplicate-key races are caught and the existing reference returned.
4. `transaction_events` records every status transition; balances are snapshotted to `account_balance_snapshots` on funding.

**Transfer lifecycle (2-step, `TransferService` + `NotifyingTransferService`):**
`initiate()` — validate amount (`^\d+(\.\d{1,4})?$`), own-account check, active-status check, currency match, transfer limits, PIN verify (bcrypt), create **pending** txn + `pending_transfers` mapping + OTP challenge (6-digit, **sha256-hashed**, 10-min expiry, 5 attempts) → redirect to `transfer-confirm.php` (demo OTP shown on screen / kept in `$_SESSION['demo_otps']`) → `confirm()` — re-locks everything, verifies OTP w/ `hash_equals`, re-checks balance, writes ledger pair, completes, notifies → receipt page. `cancel()` and `requestNewOtp()` supported.

**Compliance gate (Phase 7).** `BankingService::transfer()` calls `ComplianceRiskService::enforce()` *before* the ledger txn. Rules from `risk_rules`: HIGH_VALUE_TRANSFER (≥1M, high), VERY_HIGH_VALUE (≥5M, critical), VELOCITY_10/20_TX_24H, NEW_ACCOUNT_ACTIVITY (<7 days, ≥500k), KYC_REQUIRED (≥500k unverified). Scored 30/60/100 → **high blocks pending review, critical blocks outright**, alerts written to `risk_alerts`, notifications fired.

**Adjustments.** `TransactionService::reverse()/refund()` create compensating ledger entries (only from `completed`, only once). Admin path goes through **maker-checker**: `admin_action_requests` → another admin must approve (`approveAction()` refuses self-approval) → `adjustTransactionLocked()` executes and audits.

**Reconciliation.** `ReconciliationService::run()` compares cached vs. computed balance for every non-closed account into `reconciliation_runs` (CLI `database/reconcile.php` exits non-zero on mismatch).

---

## 4. Customer-facing features (public/)

| Page | What it does |
|---|---|
| `login.php` / `register.php` / `logout.php` / `index.php` | Auth w/ lockout (8 fails/15 min → `locked_until`), security events, auto savings account on signup |
| `dashboard.php` | Total/available balance hero cards, account mix, accounts grid, recent + pending txns, quick actions (also handles POST logout) |
| `accounts.php`, `account.php` | Account management + per-account detail w/ history & statement links |
| `transfer.php` → `transfer-confirm.php` → `transfer-receipt.php` | 3-tab transfer (own / beneficiary / other CommServe), OTP flow, dashed receipt card |
| `transactions.php`, `transaction.php` | Filterable history (account/type/status/search/date) + detail w/ events & OTP info |
| `statements.php`, `statement-download.php` | On-screen statements w/ running balance; PDF (SimplePDF) / CSV download; logged to `statement_requests` |
| `beneficiaries.php` | Add (name auto-verified against account owner), enable/disable/delete |
| `bill-payments.php` | Biller catalogue (6 categories), PIN + OTP confirmation, history |
| `scheduled-payments.php` | Create once/daily/weekly/monthly bill or beneficiary payments; pause/resume; runs via CLI worker |
| `cards.php`, `card-payments.php`, `atm.php` | Issue virtual/debit demo cards (PAN/CVV sha256-hashed, shown once), freeze/unfreeze, set PIN & limits; POS/online card payments w/ idempotency; ATM withdrawal sim |
| `crypto.php`, `crypto-send/receive/convert.php` | Multi-asset wallet (auto-provisioned `cs<sym>_<uid>_<rand>` addresses), PIN-verified send, demo receive, cross-asset convert at static USD rates — separate `crypto_*` tables, intentionally NOT linked to the fiat ledger |
| `transaction-pin.php`, `change-password.php`, `security-activity.php`, `notification-preferences.php`, `notifications.php` | Security self-service |
| `kyc.php` | Simulated KYC submission (documents: National ID/Passport/Driver Licence) |
| `language.php`, `account-insights.php` | Locale switcher (persisted), 30-day activity table |

**Card engine details:** per-card daily + per-tx limits checked against same-day `card_transactions` sums; expiry checked; frozen card/blocked account rejected; `card_payment_completed` / `atm_withdrawal_completed` notifications fired.

**Scheduled payment engine:** atomic claim (`UPDATE … WHERE status='active' AND next_run_at<=NOW()` guarded), executes bill or beneficiary transfer in a locked transaction, advances `next_run_at` (or completes one-shots), on failure → `paused` + `last_error`. No OTP (worker context).

---

## 5. Admin back office (public/admin/)

`require_role('admin')` on all pages; own dark navbar (admin pages don't use the customer partials).

- **index.php** — ops dashboard: customers, accounts, total balance, txns, pending, approvals queue, bill-pay volume, scheduled, open risk alerts, pending KYC, last reconciliation.
- **customers.php / accounts.php** — search + status changes (suspend/lock; freeze/close), audited.
- **transactions.php / transaction.php** — global search (ref/email/account/description + status), detail w/ ledger, events, reversal/refund requests.
- **approvals.php** — maker-checker queue (approve w/ reason → executes adjustment).
- **audit.php** — audit log + transaction event stream.
- **reconciliation.php** — run + view mismatch details.
- **limits.php** — per-currency `admin_transfer_limits` editor.
- **billers.php** — biller catalogue CRUD-lite + payment metrics.
- **simulate.php** — ledger-backed demo funding / opening balance tool (DemoFundingService).
- **compliance.php** — KYC review (verified/rejected/requires_review) + risk-alert workflow (reviewing/cleared/blocked).
- **reports.php / executive.php** — Chart.js daily volume/completed-failed, txn-type mix, customer segments, top customers, CSV export (logged to `report_exports`).

---

## 6. Notifications subsystem

`NotificationService::notify()` renders `notification_templates` (event × channel: in_app/email/sms) with `{{placeholder}}` substitution, honours per-user `notification_preferences` (default on for in_app/email, sms off). Queue rows (`queued` → `sent`/`failed`) processed by `scripts/process-notifications.php`; email via PHP `mail()`, SMS deliberately unconfigured ("demo mode"); 5 attempts with 5-min backoff. Events covered: transfer completed/failed, bill/card/atm completed, card issued, risk alert, KYC status.

---

## 7. Security posture (as built)

- CSRF token on every POST (`hash_equals`), session cookie httponly/SameSite=Lax (secure flag from env, default false for local HTTP).
- `password_hash`/`verify` for passwords (policy on change: ≥10 chars, mixed case + digit) and transaction PINs (4–6 digits).
- OTPs sha256-hashed, single-use, expiring, attempt-capped; demo OTPs intentionally displayed in-UI.
- Login lockout via `login_attempts` (8 fails / 15 min per email **or IP**), `security_events` trail, `recordLoginSuccess/Failure`.
- Ownership enforced on every customer query (`user_id` scoping, `Access denied` throws).
- All SQL parameterized; `PDO::ATTR_EMULATE_PREPARES=false`.
- Prepared-but-unused Phase 9 tables: `user_sessions`, `password_reset_tokens`, `security_rate_limits` (only referenced by `revokeSessions`), plus legacy `sessions`, `kyc_documents`, old `notifications` shape (repaired by migration 010).

---

## 8. Observations & latent issues found during this study

> **Status update (2026-08-27):** all actionable items below were remediated on branch `arena/01a0410c-commserve` — see §10 for the change log. Items are kept here for history.

1. **NGN remnants after the USD migration (PRs #8/#9)** — DB is USD, but:
   - `BankingService::transfer()` still calls `compliance->enforce($uid,$amount,'NGN')`; `ComplianceRiskService` defaults to `'NGN'` and stamps `metadata.currency='NGN'` on alerts (metadata only; thresholds are currency-blind).
   - `SecurityService::assertTransferAllowed()` default `'NGN'` — safe in practice because all callers pass `$account['currency']`, but the fallback row lookup is currency-scoped.
   - `admin/limits.php` defaults to currency `NGN`, and the seeded `admin_transfer_limits` row is `NGN` → the admin limits page edits a **stale NGN row** while live limit checks run against USD (falling back to hardcoded 1M/5M defaults if no USD row exists).
   - `scheduled-payments.php` form still defaults the currency field to `NGN` → creating a schedule without editing it fails the service's currency-mismatch check ("Currency mismatch") against a USD account.
   - `transaction.php` falls back to `'NGN'` display; `kyc.php` defaults country to `NG`.
2. **README is two phases + crypto out of date** (stops at Phase 5, no mention of cards/ATM/compliance/notifications/reporting/crypto/USD/i18n).
3. **Hardcoded `/commserve/public` base path** in 46 files and `helpers.php` redirects — moving the app to another URL breaks everything; no `BASE_URL` constant.
4. **Migration 010 uses `ADD COLUMN IF NOT EXISTS` / `CREATE INDEX IF NOT EXISTS`** — MariaDB syntax, not valid on stock MySQL 8 (would abort that migration there; runner only tolerates "already exists" errors). Fine on XAMPP/MariaDB.
5. `format_money()` ignores its `$currency` argument (always `$`) — consistent with USD-only but misleading API.
6. `dashboard.php` doubles as the logout POST handler (form posts to it); `sidebar.php` marks Cards as "Soon" although Cards/ATM are fully implemented; several newer pages (cards, atm, kyc, notifications, security) use standalone layouts, not the shared partials — minor UX inconsistency.
7. `getOpeningBalance()` (AccountService & StatementService) returns **0 when no `from` date** — statements generated without a start date compute opening = 0 (closing still correct via full ledger or cached balance).
8. Crypto subsystem is deliberately sandboxed from the fiat ledger (no buy/sell on/off ramp); `crypto_transactions` are separate from `transactions`.
9. `.env` properly gitignored and `.htaccess`-denied; seeded bcrypt hash implies password `password` — README warns to change before non-local use.

---

## 9. How to run (per README + code)

1. `cp .env.example .env` → set MySQL creds (`DB_NAME=commserve`), `APP_URL`.
2. Create DB, import `database/schema.sql`, then `database/seed.sql`.
3. Docroot → `public/` at path `/commserve`.
4. `php database/migrate.php` (or rely on auto-migrate on first page load).
5. Cron: `php scripts/run-scheduled-payments.php` and `php scripts/process-notifications.php`.
6. Optional health check: `php database/reconcile.php` (exit 0 = passed).

**Login:** `john@commserve.test` / `jane@commserve.test` (customers), `admin@commserve.test` (admin) — password `password`. Each user must set a transaction PIN before transferring (top-right → Transaction PIN).

---

## 10. Remediation applied (2026-08-27, branch `arena/01a0410c-commserve`)

| # | Issue | Fix applied |
|---|---|---|
| 1a | `BankingService` compliance gate hardcoded `'NGN'` | Now passes `DEFAULT_CURRENCY` |
| 1b | `ComplianceRiskService` NGN defaults + NGN alert metadata | Defaults → `USD`; alert metadata now records the real assessed currency |
| 1c | `SecurityService::assertTransferAllowed` NGN default | Default → `USD` |
| 1d | `AdminOperationsService::transferLimits` NGN default | Default → `USD` |
| 1e | Admin Limits page edits stale NGN row | Page default → `DEFAULT_CURRENCY`; new migration **018** deletes non-USD `admin_transfer_limits` rows and seeds a USD row |
| 1f | Scheduled-payments form defaulted currency to NGN (failed validation) | Defaults to `DEFAULT_CURRENCY` (USD) |
| 1g | `transaction.php` NGN fallback | Falls back to `DEFAULT_CURRENCY` |
| 1h | `kyc.php` country default `NG` | Intentionally kept — Nigeria is the demo's home market (country code, not currency) |
| 2 | README stopped at Phase 5 | Full rewrite: documents Phases 1–11 + Crypto, workers, BASE_PATH, demo accounts, security notes |
| 3 | Hardcoded `/commserve/public` in 46 files | New `base_path()`/`url()` helpers (auto-detected, `BASE_PATH` env override); all 46 files refactored (`url('...')` in PHP, `<?=url('...')?>` in HTML) |
| 4 | Migration 010 MariaDB-only syntax | Rewritten with portable `information_schema`-guarded `PREPARE`/`EXECUTE` statements (same pattern as migration 006); MigrationRunner checksum-allowlist extended so already-applied DBs re-record the repaired file |
| 5 | `format_money()` ignored currency | New `currency_symbol()` map (USD/EUR/GBP/NGN/JPY, ISO-code fallback); `format_money` uses it |
| 6a | Logout POSTed to `dashboard.php` | `logout.php` is now the single logout endpoint (POST + CSRF verified, session cookie cleared); dashboard's duplicate handler removed |
| 6b | "Cards — Soon" although implemented | Sidebar links to Cards & ATM with active-state; added Notifications and Security nav entries with active states |
| 6c | 9 standalone-layout pages | `account-insights`, `atm`, `card-payments`, `cards`, `change-password`, `kyc`, `notification-preferences`, `notifications`, `security-activity` now use the shared header/sidebar/footer partials |
| 7 | Opening balance 0 confusion | Documented in code: 0.0 is correct when no start date is given (statement begins at inception) |

**Verification performed:** PHP-tag/brace/paren balance check across all 100 PHP files (no syntax-level breakage); audit of every `<?=url(` insertion (all in HTML attribute context); partial include balance per page; query-string links and redirects manually reviewed. Runtime smoke test against MySQL could not be executed in this sandbox (no PHP/MySQL available) — run `php database/migrate.php` after pulling.
