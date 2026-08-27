<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/TransferService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user();
$db = Database::connection();
$transferService = new TransferService($db);
$accountService = new AccountService($db);

$ref = trim($_GET['ref'] ?? '');
if ($ref === '') redirect(url('transactions.php'));

try {
    $details = $transferService->getDetails($ref, (int)$user['id']);
} catch (Throwable $e) {
    $details = null;
    $error = $e->getMessage();
}

$pageTitle = 'Transfer Receipt';
$currentPage = 'transfer';
$totalBalance = $accountService->getTotalBalance((int)$user['id']);
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h3 class="fw-bold mb-0"><i class="bi bi-receipt me-2"></i>Transfer Receipt</h3>
      <a href="<?=url('transactions.php')?>" class="btn btn-outline-secondary btn-sm">Back to transactions</a>
    </div>

    <?php if (empty($details)): ?>
      <div class="alert alert-danger">Receipt not found: <?= e($error ?? 'Unknown') ?></div>
    <?php else: ?>
      <div class="card receipt-card shadow-sm">
        <div class="card-body p-4 p-lg-5">
          <div class="text-center mb-4">
            <div class="brand-mark mx-auto mb-3" style="width:48px;height:48px">C</div>
            <h4 class="fw-bold">CommServe Demo Bank</h4>
            <div class="small text-muted">Transfer Receipt — Simulation Only</div>
            <div class="mt-3"><span class="badge <?= $details['status']==='completed'?'text-bg-success':'text-bg-warning' ?> fs-6"><?= e(strtoupper($details['status'])) ?></span></div>
          </div>

          <div class="text-center mb-4">
            <div class="display-5 fw-bold">$<?= number_format((float)$details['amount'],2) ?></div>
            <div class="text-muted"><?= e($details['currency']) ?> · <?= e(ucfirst($details['type'])) ?></div>
          </div>

          <div class="row g-4 mb-4">
            <div class="col-md-6">
              <div class="small text-muted text-uppercase fw-semibold">From</div>
              <div class="fw-bold mt-1"><?= e(trim(($details['from_first']??'').' '.($details['from_last']??'')) ?: 'Your Account') ?></div>
              <div class="small"><?= e($details['from_type']??'') ?> · <?= e($details['from_account_number']??'') ?></div>
              <div class="small text-muted">Account ID: <?= e((string)($details['from_account_id']??'')) ?></div>
            </div>
            <div class="col-md-6 text-md-end">
              <div class="small text-muted text-uppercase fw-semibold">To</div>
              <div class="fw-bold mt-1"><?= e(trim(($details['to_first']??'').' '.($details['to_last']??'')) ?: 'Recipient') ?></div>
              <div class="small"><?= e($details['to_type']??'') ?> · <?= e($details['to_account_number']??'') ?></div>
              <div class="small text-muted">Account ID: <?= e((string)($details['to_account_id']??'')) ?></div>
            </div>
          </div>

          <div class="receipt-divider"></div>

          <dl class="row small mb-0">
            <dt class="col-5 text-muted">Reference</dt><dd class="col-7 fw-semibold font-monospace"><?= e($details['reference']) ?></dd>
            <dt class="col-5 text-muted">Transaction ID</dt><dd class="col-7"><?= e((string)$details['id']) ?></dd>
            <dt class="col-5 text-muted">Description</dt><dd class="col-7"><?= e($details['description']) ?></dd>
            <dt class="col-5 text-muted">Initiated</dt><dd class="col-7"><?= e($details['created_at']) ?></dd>
            <dt class="col-5 text-muted">Completed</dt><dd class="col-7"><?= e($details['completed_at'] ?? $details['created_at']) ?></dd>
            <dt class="col-5 text-muted">Status</dt><dd class="col-7"><span class="badge text-bg-success"><?= e($details['status']) ?></span></dd>
            <dt class="col-5 text-muted">Initiated By</dt><dd class="col-7"><?= e($user['name']) ?> (<?= e($user['email']) ?>)</dd>
          </dl>

          <div class="receipt-divider"></div>

          <div class="d-flex justify-content-between small text-muted">
            <span>Ledger-backed • OTP verified • PIN protected</span>
            <span><?= e(date('Y-m-d H:i:s')) ?></span>
          </div>

          <div class="alert alert-light border mt-4 small mb-0">
            <i class="bi bi-info-circle me-2"></i>This receipt is computer generated and valid without signature. No real funds were moved — simulation only.
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 mt-4">
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer me-2"></i>Print Receipt</button>
        <a href="<?=url('transaction.php')?>?ref=<?= urlencode($details['reference']) ?>" class="btn btn-outline-secondary"><i class="bi bi-eye me-2"></i>View Transaction</a>
        <a href="<?=url('transfer.php')?>" class="btn btn-outline-secondary"><i class="bi bi-send me-2"></i>New Transfer</a>
      </div>

      <?php if (!empty($details['events'])): ?>
        <div class="card border-0 shadow-sm mt-4">
          <div class="card-header bg-white fw-bold small text-muted text-uppercase">Audit Trail</div>
          <div class="card-body">
            <?php foreach ($details['events'] as $ev): ?>
              <div class="d-flex justify-content-between small border-bottom py-2">
                <span><strong><?= e($ev['event_type']) ?></strong> — <?= e(($ev['old_status']??'').' → '.($ev['new_status']??'')) ?></span>
                <span class="text-muted"><?= e($ev['created_at']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
