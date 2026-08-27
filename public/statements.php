<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_once dirname(__DIR__) . '/app/Services/StatementService.php';
require_auth();
$user = auth_user();
$db = Database::connection();
$accountService = new AccountService($db);
$statementService = new StatementService($db);

$accounts = $accountService->getAccounts((int)$user['id']);
$totalBalance = $accountService->getTotalBalance((int)$user['id']);

$selectedAccountId = (int)($_GET['account'] ?? $_POST['account_id'] ?? ($accounts[0]['id'] ?? 0));
$from = trim($_GET['from'] ?? $_POST['from_date'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? $_POST['to_date'] ?? date('Y-m-d'));
$format = strtolower(trim($_GET['format'] ?? $_POST['format'] ?? 'pdf'));
$month = trim($_GET['month'] ?? '');

$error = null;
$success = null;

// Handle monthly quick select
if ($month !== '') {
    try {
        [$from, $to] = $statementService->monthlyRange($month);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// If format is requested and account selected, generate download via redirect to download handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['format'])) {
    if ($selectedAccountId && in_array($format, ['pdf','csv'], true)) {
        // Validate account belongs to user
        try {
            $account = $statementService->getAccount((int)$user['id'], $selectedAccountId);
            // If POST with generate button, redirect to download URL to avoid re-POST issues, but also handle direct GET
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $url = '/commserve/public/statement-download.php?account=' . $selectedAccountId . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&format=' . urlencode($format);
                redirect($url);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Statements';
$currentPage = 'statements';
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="fw-bold mb-1">Statements</h3>
    <p class="text-muted mb-0">Generate PDF or CSV statements — date-range, monthly, full history. Ledger-backed.</p>
  </div>
  <span class="badge text-bg-primary"><i class="bi bi-file-earmark-text me-1"></i>PDF & CSV</span>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white"><h5 class="fw-bold mb-0"><i class="bi bi-file-earmark-arrow-down me-2"></i>Generate Statement</h5></div>
      <div class="card-body p-4">
        <form method="post" class="row g-3">
          <?= csrf_field() ?>
          <div class="col-md-6">
            <label class="form-label">Account</label>
            <select name="account_id" class="form-select" required>
              <option value="">Select account</option>
              <?php foreach ($accounts as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $selectedAccountId===$a['id']?'selected':'' ?>><?= e($a['type_name']) ?> · <?= e($a['account_number']) ?> · $<?= number_format((float)$a['available_balance'],2) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Format</label>
            <select name="format" class="form-select" required>
              <option value="pdf" <?= $format==='pdf'?'selected':'' ?>>PDF</option>
              <option value="csv" <?= $format==='csv'?'selected':'' ?>>CSV</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Quick Month</label>
            <input type="month" class="form-control" id="monthPicker" value="<?= e(substr($from,0,7)) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">From Date</label>
            <input type="date" name="from_date" class="form-control" value="<?= e($from) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">To Date</label>
            <input type="date" name="to_date" class="form-control" value="<?= e($to) ?>" required>
          </div>
          <div class="col-12">
            <div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-2"></i><strong>PDF</strong> includes opening/closing balances, running balance, and is printable. <strong>CSV</strong> is spreadsheet-friendly with same data. Both are generated from ledger (source of truth).</div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary w-100 py-2"><i class="bi bi-download me-2"></i>Generate & Download Statement</button>
          </div>
        </form>

        <hr class="my-4">

        <h6 class="fw-bold">Date-range shortcuts</h6>
        <div class="d-flex flex-wrap gap-2 mt-2">
          <a href="?account=<?= $selectedAccountId ?>&from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>&format=pdf" class="btn btn-sm btn-outline-secondary">Today PDF</a>
          <a href="?account=<?= $selectedAccountId ?>&from=<?= date('Y-m-d', strtotime('-7 days')) ?>&to=<?= date('Y-m-d') ?>&format=pdf" class="btn btn-sm btn-outline-secondary">Last 7 days</a>
          <a href="?account=<?= $selectedAccountId ?>&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>&format=pdf" class="btn btn-sm btn-outline-secondary">This month</a>
          <a href="?account=<?= $selectedAccountId ?>&from=<?= date('Y-m-01', strtotime('-1 month')) ?>&to=<?= date('Y-m-t', strtotime('-1 month')) ?>&format=pdf" class="btn btn-sm btn-outline-secondary">Last month</a>
          <a href="?account=<?= $selectedAccountId ?>&from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>&format=pdf" class="btn btn-sm btn-outline-secondary">This year</a>
        </div>

        <h6 class="fw-bold mt-4">Monthly statements</h6>
        <div class="row g-2 mt-1">
          <?php for ($i=0;$i<6;$i++): $m = date('Y-m', strtotime("-$i month")); $label = date('M Y', strtotime($m.'-01')); ?>
            <div class="col-6 col-md-4">
              <div class="card border">
                <div class="card-body p-3">
                  <div class="fw-semibold"><?= e($label) ?></div>
                  <div class="small text-muted"><?= e($m) ?></div>
                  <div class="d-flex gap-1 mt-2">
                    <a href="/commserve/public/statement-download.php?account=<?= $selectedAccountId ?>&month=<?= $m ?>&format=pdf" class="btn btn-sm btn-outline-primary">PDF</a>
                    <a href="/commserve/public/statement-download.php?account=<?= $selectedAccountId ?>&month=<?= $m ?>&format=csv" class="btn btn-sm btn-outline-secondary">CSV</a>
                  </div>
                </div>
              </div>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-bold"><i class="bi bi-info-circle me-2"></i>What’s in a statement?</div>
      <div class="card-body small">
        <ul class="mb-0">
          <li>Account number, type, currency, customer</li>
          <li>Period, opening & closing balances (ledger-calculated)</li>
          <li>All completed transactions in date range, running balance</li>
          <li>Reference, type, description, debit/credit, status</li>
          <li>Generated timestamp, DEMO watermark</li>
        </ul>
        <hr>
        <div class="text-muted"><strong>PDF:</strong> Uses pure-PHP generator (no external libs), A4, Helvetica, printable, valid without signature.</div>
        <div class="text-muted mt-2"><strong>CSV:</strong> Excel/Sheets compatible, includes same fields.</div>
      </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
      <div class="card-header bg-white fw-bold">Your Accounts</div>
      <div class="card-body">
        <?php foreach ($accounts as $a): ?>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div><div class="fw-semibold small"><?= e($a['type_name']) ?> · <?= e($a['account_number']) ?></div><div class="small text-muted">$<?= number_format((float)$a['available_balance'],2) ?></div></div>
            <a href="/commserve/public/statements.php?account=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary">Select</a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('monthPicker')?.addEventListener('change', function(){
  const m = this.value;
  if(!m) return;
  const from = m + '-01';
  const d = new Date(m + '-01');
  const last = new Date(d.getFullYear(), d.getMonth()+1, 0).toISOString().slice(0,10);
  document.querySelector('input[name="from_date"]').value = from;
  document.querySelector('input[name="to_date"]').value = last;
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
