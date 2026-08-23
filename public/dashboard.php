<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $_SESSION = [];
    session_destroy();
    redirect('/commserve/public/login.php');
}

$user = auth_user();
$db = Database::connection();
$accountService = new AccountService($db);

// Default to empty arrays to prevent TypeError on PHP 8.1+
$accounts = [];
$savings = [];
$current = [];
$recent = [];
$pending = [];
$totalBalance = 0.0;
$availableBalance = 0.0;

try {
    $accountsData = $accountService->getSavingsAndCurrent((int)$user['id']);
    $accounts = $accountsData['all'] ?? [];
    $savings = $accountsData['savings'] ?? [];
    $current = $accountsData['current'] ?? [];
    $totalBalance = $accountService->getTotalBalance((int)$user['id']);
    $availableBalance = $accountService->getAvailableBalance((int)$user['id']);
    $recent = $accountService->getRecentTransactions((int)$user['id'], 8);
    $pending = $accountService->getPendingTransactions((int)$user['id'], 5);
} catch (Throwable $e) {
    $accounts = $accountsData['all'] ?? $accounts;
    $savings = $accountsData['savings'] ?? $savings;
    $current = $accountsData['current'] ?? $current;
}

// Final safety - force arrays (fixes count() and array_map() TypeError)
if (!is_array($accounts)) $accounts = [];
if (!is_array($savings)) $savings = [];
if (!is_array($current)) $current = [];
if (!is_array($recent)) $recent = [];
if (!is_array($pending)) $pending = [];

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
  <div>
    <h2 class="fw-bold mb-1">Welcome back, <?= e(explode(' ', $user['name'] ?? 'Customer')[0]) ?> 👋</h2>
    <p class="text-muted mb-0">Here's your simulated banking overview. No real funds are processed.</p>
  </div>
  <div class="d-flex gap-2 mt-3 mt-md-0">
    <a href="/commserve/public/transfer.php" class="btn btn-primary"><i class="bi bi-send me-2"></i>Transfer</a>
    <a href="/commserve/public/statements.php" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-arrow-down me-2"></i>Statement</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card hero-card border-0 text-white h-100">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start">
          <div><div class="text-white-50 small">Total Balance</div><div class="display-6 fw-bold mt-1">₦<?= number_format((float)$totalBalance, 2) ?></div></div>
          <span class="badge bg-white text-primary">NGN</span>
        </div>
        <div class="mt-4 d-flex gap-3 small">
          <span><i class="bi bi-wallet2 me-1"></i><?= safe_count($accounts) ?> account(s)</span>
          <span><i class="bi bi-shield-check me-1"></i>Ledger-backed</span>
        </div>
        <div class="mt-3">
          <a href="/commserve/public/accounts.php" class="btn btn-light btn-sm">Manage accounts</a>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card hero-card-dark border-0 text-white h-100">
      <div class="card-body p-4">
        <div class="text-white-50 small">Available Balance</div>
        <div class="display-6 fw-bold mt-1">₦<?= number_format((float)$availableBalance, 2) ?></div>
        <div class="mt-3 small text-white-50">Funds available for transfers and withdrawals</div>
        <div class="mt-3">
          <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle me-1"></i>Active</span>
          <span class="badge bg-white bg-opacity-25 ms-2">KYC Pending</span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body p-4">
        <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart me-2"></i>Account Mix</h6>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <span><span class="badge bg-primary me-2"> </span>Savings</span>
          <span class="fw-semibold"><?= safe_count($savings) ?> · ₦<?= number_format(array_sum(array_map(fn($a)=>(float)($a['available_balance'] ?? 0),(array)$savings)),2) ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <span><span class="badge bg-dark me-2"> </span>Current</span>
          <span class="fw-semibold"><?= safe_count($current) ?> · ₦<?= number_format(array_sum(array_map(fn($a)=>(float)($a['available_balance'] ?? 0),(array)$current)),2) ?></span>
        </div>
        <hr>
        <div class="d-flex justify-content-between small text-muted">
          <span>Pending transfers</span><span class="badge text-bg-warning"><?= safe_count($pending) ?></span>
        </div>
        <div class="d-flex justify-content-between small text-muted mt-2">
          <span>Recent transactions</span><span class="badge text-bg-secondary"><?= safe_count($recent) ?></span>
        </div>
        <a href="/commserve/public/transaction-pin.php" class="btn btn-outline-primary btn-sm w-100 mt-3"><i class="bi bi-shield-lock me-1"></i>Manage PIN & Security</a>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="fw-bold mb-0">Your Accounts</h5>
      <a href="/commserve/public/accounts.php" class="btn btn-sm btn-outline-secondary">View all</a>
    </div>
    <div class="row g-3">
      <?php foreach ((array)$accounts as $a): ?>
      <div class="col-md-6">
        <div class="card account-card h-100">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="small text-muted text-uppercase fw-semibold"><?= e($a['type_name'] ?? '') ?></div>
                <div class="account-number fw-bold mt-1">****<?= e(substr($a['account_number']??'',-4)) ?> · <?= e($a['account_number']??'') ?></div>
              </div>
              <div class="bg-light rounded-3 p-2"><i class="bi bi-<?= strtolower($a['type_name']??'')==='savings'?'piggy-bank':'wallet2' ?> text-primary fs-5"></i></div>
            </div>
            <h4 class="fw-bold mt-3">₦<?= number_format((float)($a['available_balance']??0),2) ?></h4>
            <div class="small text-muted"><?= e($a['currency']??'') ?> · <?= e(ucfirst($a['status']??'')) ?></div>
            <div class="d-flex gap-2 mt-3">
              <a href="/commserve/public/account.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-primary">Details</a>
              <a href="/commserve/public/transactions.php?account=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary">History</a>
              <a href="/commserve/public/statements.php?account=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary">Statement</a>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($accounts)): ?>
        <div class="col-12"><div class="alert alert-info">No accounts found. Contact support.</div></div>
      <?php endif; ?>
    </div>

    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Recent Transactions</h6>
        <a href="/commserve/public/transactions.php" class="btn btn-sm btn-outline-primary">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>Date</th><th>Account</th><th>Type</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (empty($recent)): ?><tr><td colspan="6" class="text-center text-muted py-4">No transactions yet. Try a demo transfer.</td></tr>
          <?php else: foreach ((array)$recent as $r): ?>
            <tr>
              <td class="small"><?= e(date('M d, Y', strtotime($r['created_at'] ?? ''))) ?><br><span class="text-muted"><?= e(date('h:i A', strtotime($r['created_at'] ?? ''))) ?></span></td>
              <td class="small"><span class="badge text-bg-light border"><?= e($r['account_type'] ?? '') ?></span><br>****<?= e(substr($r['account_number']??'',-4)) ?></td>
              <td><span class="badge <?= ($r['entry_type']??'')==='credit'?'text-bg-success':'text-bg-danger' ?>"><?= e(ucfirst($r['entry_type']??'')) ?></span><br><small class="text-muted"><?= e($r['type']??'') ?></small></td>
              <td class="small" style="max-width:200px"><a href="/commserve/public/transaction.php?ref=<?= urlencode($r['reference']??'') ?>" class="text-decoration-none fw-semibold"><?= e($r['reference']??'') ?></a><br><?= e(substr($r['description']??'',0,40)) ?></td>
              <td class="fw-bold">₦<?= number_format((float)($r['amount']??0),2) ?></td>
              <td><span class="badge text-bg-success"><?= e(ucfirst($r['status']??'')) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white"><h6 class="fw-bold mb-0"><i class="bi bi-hourglass-split me-2 text-warning"></i>Pending Transactions</h6></div>
      <div class="card-body">
        <?php if (empty($pending)): ?>
          <div class="text-center py-4"><i class="bi bi-check-circle text-success fs-1"></i><p class="text-muted mt-2 mb-0">No pending transfers. All clear!</p></div>
        <?php else: foreach ((array)$pending as $p): ?>
          <div class="border rounded-3 p-3 mb-3">
            <div class="d-flex justify-content-between"><span class="fw-semibold small"><?= e($p['reference']??'') ?></span><span class="badge text-bg-warning"><?= e($p['status']??'') ?></span></div>
            <div class="small text-muted mt-1"><?= e($p['description']??'') ?> · ₦<?= number_format((float)($p['amount']??0),2) ?></div>
            <div class="small mt-2">From <?= e($p['from_account_number']??'...') ?> → To <?= e($p['to_account_number']??'...') ?></div>
            <a href="/commserve/public/transfer-confirm.php?ref=<?= urlencode($p['reference']??'') ?>" class="btn btn-sm btn-primary w-100 mt-2">Confirm with OTP</a>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white"><h6 class="fw-bold mb-0"><i class="bi bi-lightning me-2"></i>Quick Actions</h6></div>
      <div class="card-body d-grid gap-2">
        <a href="/commserve/public/transfer.php?type=own" class="btn btn-outline-primary text-start"><i class="bi bi-arrow-left-right me-2"></i>Own-account transfer</a>
        <a href="/commserve/public/transfer.php?type=beneficiary" class="btn btn-outline-primary text-start"><i class="bi bi-person-check me-2"></i>Send to beneficiary</a>
        <a href="/commserve/public/transfer.php?type=other" class="btn btn-outline-primary text-start"><i class="bi bi-bank me-2"></i>Other CommServe account</a>
        <a href="/commserve/public/beneficiaries.php" class="btn btn-outline-secondary text-start"><i class="bi bi-person-plus me-2"></i>Add beneficiary</a>
        <a href="/commserve/public/statements.php" class="btn btn-outline-secondary text-start"><i class="bi bi-file-earmark-arrow-down me-2"></i>Download statement</a>
      </div>
    </div>

    <div class="card border-0 bg-primary text-white">
      <div class="card-body p-4">
        <h6 class="fw-bold"><i class="bi bi-shield-lock me-2"></i>Security Tips</h6>
        <ul class="small mb-0 ps-3">
          <li>Never share your Transaction PIN or OTP</li>
          <li>Always verify account numbers before confirming</li>
          <li>Use idempotency keys for safe retries</li>
          <li>All actions are ledger-backed and audited</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>