<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_once dirname(__DIR__) . '/app/Services/StatementService.php';
require_auth();
$user = auth_user();
$db = Database::connection();
$accountService = new AccountService($db);
$statementService = new StatementService($db);

$accountId = (int)($_GET['id'] ?? 0);
if (!$accountId) redirect('/commserve/public/accounts.php');

try {
    $account = $accountService->getAccount((int)$user['id'], $accountId);
} catch (Throwable $e) {
    http_response_code(404);
    $account = null;
    $error = $e->getMessage();
}

if ($account) {
    $transactions = $accountService->getAccountTransactions($accountId, 50);
    $totalBalance = $accountService->getTotalBalance((int)$user['id']);
    // For statement preview - last 30 days
    $from30 = date('Y-m-d', strtotime('-30 days'));
    $toNow = date('Y-m-d');
    $recentRange = $statementService->getTransactionsRange($accountId, $from30, $toNow, 100);
    $opening30 = $statementService->getOpeningBalance($accountId, $from30);
}

$pageTitle = 'Account Details';
$currentPage = 'accounts';
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';

if (!$account):
?>
<div class="alert alert-danger">Account not found or access denied.</div>
<a href="/commserve/public/accounts.php" class="btn btn-outline-secondary">Back to accounts</a>
<?php require __DIR__ . '/partials/footer.php'; exit; endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1"><li class="breadcrumb-item"><a href="/commserve/public/accounts.php">Accounts</a></li><li class="breadcrumb-item active"><?= e($account['account_number']) ?></li></ol></nav>
    <h3 class="fw-bold mb-1"><?= e($account['type_name']) ?> Account · ****<?= e(substr($account['account_number'],-4)) ?></h3>
    <p class="text-muted mb-0"><?= e($account['account_number']) ?> · <?= e($account['currency']) ?> · <?= e(ucfirst($account['status'])) ?></p>
  </div>
  <div class="d-flex gap-2">
    <a href="/commserve/public/transfer.php?from=<?= $account['id'] ?>" class="btn btn-primary"><i class="bi bi-send me-2"></i>Transfer</a>
    <a href="/commserve/public/statements.php?account=<?= $account['id'] ?>" class="btn btn-outline-primary"><i class="bi bi-file-earmark-text me-2"></i>Statement</a>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <div class="text-center">
          <div class="bg-primary bg-opacity-10 rounded-circle d-inline-grid place-items-center" style="width:64px;height:64px;display:grid;place-items:center"><i class="bi bi-wallet2 text-primary fs-3"></i></div>
          <h2 class="fw-bold mt-3">₦<?= number_format((float)$account['available_balance'],2) ?></h2>
          <div class="text-muted small">Available Balance</div>
          <span class="badge text-bg-success mt-2"><i class="bi bi-check-circle me-1"></i>Ledger Verified</span>
        </div>
        <hr class="my-4">
        <dl class="row small mb-0">
          <dt class="col-5 text-muted">Account Number</dt><dd class="col-7 fw-semibold"><?= e($account['account_number']) ?></dd>
          <dt class="col-5 text-muted">Type</dt><dd class="col-7"><?= e($account['type_name']) ?></dd>
          <dt class="col-5 text-muted">Currency</dt><dd class="col-7"><?= e($account['currency']) ?></dd>
          <dt class="col-5 text-muted">Status</dt><dd class="col-7"><span class="badge text-bg-success"><?= e($account['status']) ?></span></dd>
          <dt class="col-5 text-muted">Customer</dt><dd class="col-7"><?= e($account['first_name'].' '.$account['last_name']) ?></dd>
          <dt class="col-5 text-muted">Email</dt><dd class="col-7"><?= e($account['email']) ?></dd>
        </dl>
        <div class="d-grid gap-2 mt-4">
          <a href="/commserve/public/transactions.php?account=<?= $account['id'] ?>" class="btn btn-outline-primary"><i class="bi bi-clock-history me-2"></i>Full Transaction History</a>
          <a href="/commserve/public/statements.php?account=<?= $account['id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-arrow-down me-2"></i>Download Statement</a>
        </div>
      </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white fw-bold"><i class="bi bi-graph-up me-2"></i>30-Day Summary</div>
      <div class="card-body">
        <?php
          $credits = array_sum(array_map(fn($t)=> $t['entry_type']==='credit'?(float)$t['amount']:0, $recentRange));
          $debits = array_sum(array_map(fn($t)=> $t['entry_type']==='debit'?(float)$t['amount']:0, $recentRange));
        ?>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Opening (<?= $from30 ?>)</span><strong>₦<?= number_format($opening30,2) ?></strong></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-success">Total Credits</span><strong class="text-success">+₦<?= number_format($credits,2) ?></strong></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-danger">Total Debits</span><strong class="text-danger">-₦<?= number_format($debits,2) ?></strong></div>
        <hr>
        <div class="d-flex justify-content-between"><span class="fw-bold">Net Flow</span><strong>₦<?= number_format($credits-$debits,2) ?></strong></div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0"><i class="bi bi-receipt me-2"></i>Recent Activity</h6>
        <a href="/commserve/public/transactions.php?account=<?= $account['id'] ?>" class="btn btn-sm btn-outline-primary">View all</a>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>Date</th><th>Reference</th><th>Description</th><th>Type</th><th>Amount</th></tr></thead>
          <tbody>
          <?php if (!$transactions): ?><tr><td colspan="5" class="text-center text-muted py-4">No transactions</td></tr>
          <?php else: foreach ($transactions as $t): ?>
            <tr>
              <td class="small"><?= e(date('M d, Y', strtotime($t['created_at']))) ?><br><span class="text-muted"><?= e(date('H:i', strtotime($t['created_at']))) ?></span></td>
              <td><a href="/commserve/public/transaction.php?ref=<?= urlencode($t['reference']) ?>" class="fw-semibold text-decoration-none small"><?= e($t['reference']) ?></a></td>
              <td class="small" style="max-width:220px"><?= e($t['description']) ?></td>
              <td><span class="badge <?= $t['entry_type']==='credit'?'text-bg-success':'text-bg-danger' ?>"><?= e($t['entry_type']) ?></span><br><small class="text-muted"><?= e($t['type']) ?></small></td>
              <td class="fw-bold <?= $t['entry_type']==='credit'?'text-success':'text-danger' ?>"><?= $t['entry_type']==='credit'?'+':'-' ?>₦<?= number_format((float)$t['amount'],2) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white fw-bold"><i class="bi bi-file-earmark-text me-2"></i>Quick Statement</div>
      <div class="card-body">
        <p class="text-muted small">Generate PDF or CSV for this account. Choose date range or monthly.</p>
        <form method="get" action="/commserve/public/statements.php" class="row g-2">
          <input type="hidden" name="account" value="<?= $account['id'] ?>">
          <div class="col-md-4"><label class="form-label small">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from30) ?>"></div>
          <div class="col-md-4"><label class="form-label small">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($toNow) ?>"></div>
          <div class="col-md-4"><label class="form-label small">Format</label><select name="format" class="form-select form-select-sm"><option value="pdf">PDF</option><option value="csv">CSV</option></select></div>
          <div class="col-12"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-download me-2"></i>Generate Statement</button></div>
        </form>
        <div class="mt-3 d-flex gap-2">
          <a href="/commserve/public/statements.php?account=<?= $account['id'] ?>&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>&format=pdf" class="btn btn-sm btn-outline-secondary">This Month PDF</a>
          <a href="/commserve/public/statements.php?account=<?= $account['id'] ?>&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>&format=csv" class="btn btn-sm btn-outline-secondary">This Month CSV</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
