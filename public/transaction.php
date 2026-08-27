<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_once dirname(__DIR__) . '/app/Services/TransactionService.php';
require_once dirname(__DIR__) . '/app/Services/TransferService.php';
require_auth();
$user = auth_user();
$db = Database::connection();

$ref = trim($_GET['ref'] ?? $_POST['reference'] ?? '');
if ($ref === '') redirect(url('transactions.php'));

$accountService = new AccountService($db);
$txService = new TransactionService($db);
$transferService = new TransferService($db);

$error = null;
$tx = null;
$details = null;

try {
    // Try to get as transfer first (pending or completed with mapping)
    try {
        $details = $transferService->getDetails($ref, (int)$user['id']);
        $tx = $details;
    } catch (Throwable $e) {
        // Fallback to generic transaction lookup
        $stmt = $db->prepare('SELECT DISTINCT t.* FROM transactions t JOIN ledger_entries le ON le.transaction_id=t.id JOIN accounts a ON a.id=le.account_id WHERE t.reference=? AND a.user_id=? LIMIT 1');
        $stmt->execute([$ref, (int)$user['id']]);
        $tx = $stmt->fetch();
        if (!$tx) throw new RuntimeException('Transaction not found or not yours.');
        // Fetch ledger
        $stmt = $db->prepare('SELECT le.*, a.account_number, at.name AS type_name FROM ledger_entries le JOIN accounts a ON a.id=le.account_id JOIN account_types at ON at.id=a.account_type_id WHERE le.transaction_id=? ORDER BY le.id');
        $stmt->execute([(int)$tx['id']]);
        $tx['ledger'] = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT * FROM transaction_events WHERE transaction_id=? ORDER BY id ASC');
        $stmt->execute([(int)$tx['id']]);
        $tx['events'] = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Transaction';
$currentPage = 'transactions';
$totalBalance = $accountService->getTotalBalance((int)$user['id']);
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-10">
    <nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?=url('transactions.php')?>">Transactions</a></li><li class="breadcrumb-item active"><?= e($ref) ?></li></ol></nav>

    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><a href="<?=url('transactions.php')?>" class="btn btn-outline-secondary">Back</a><?php require __DIR__ . '/partials/footer.php'; exit; endif; ?>

    <div class="row g-4">
      <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start">
              <div><h5 class="fw-bold mb-1"><?= e(strtoupper($tx['type'] ?? 'Transaction')) ?></h5><div class="small text-muted font-monospace"><?= e($tx['reference']) ?></div></div>
              <span class="badge fs-6 <?= ($tx['status']==='completed'?'text-bg-success':($tx['status']==='pending'?'text-bg-warning':'text-bg-secondary')) ?>"><?= e(strtoupper($tx['status'])) ?></span>
            </div>
            <div class="display-6 fw-bold mt-4">$<?= number_format((float)($tx['amount'] ?? 0),2) ?></div>
            <div class="text-muted small"><?= e($tx['currency'] ?? DEFAULT_CURRENCY) ?> · <?= e($tx['description'] ?? '') ?></div>
            <hr>
            <dl class="row small mb-0">
              <dt class="col-5 text-muted">Created</dt><dd class="col-7"><?= e($tx['created_at']) ?></dd>
              <dt class="col-5 text-muted">Completed</dt><dd class="col-7"><?= e($tx['completed_at'] ?? $tx['created_at']) ?></dd>
              <dt class="col-5 text-muted">Type</dt><dd class="col-7"><?= e($tx['type']) ?></dd>
              <?php if (!empty($tx['from_account_number'])): ?><dt class="col-5 text-muted">From</dt><dd class="col-7"><?= e($tx['from_account_number']) ?> · <?= e($tx['from_type']??'') ?></dd><?php endif; ?>
              <?php if (!empty($tx['to_account_number'])): ?><dt class="col-5 text-muted">To</dt><dd class="col-7"><?= e($tx['to_account_number']) ?> · <?= e($tx['to_type']??'') ?></dd><?php endif; ?>
            </dl>
            <div class="d-grid gap-2 mt-4">
              <?php if (($tx['status'] ?? '') === 'pending'): ?>
                <a href="<?=url('transfer-confirm.php')?>?ref=<?= urlencode($ref) ?>" class="btn btn-primary"><i class="bi bi-shield-lock me-2"></i>Confirm with OTP</a>
              <?php endif; ?>
              <a href="<?=url('transfer-receipt.php')?>?ref=<?= urlencode($ref) ?>" class="btn btn-outline-primary"><i class="bi bi-receipt me-2"></i>View Receipt</a>
              <a href="<?=url('transactions.php')?>" class="btn btn-outline-secondary">Back to Transactions</a>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white fw-bold"><i class="bi bi-journal-text me-2"></i>Ledger Entries</div>
          <div class="card-body p-0">
            <div class="table-responsive"><table class="table mb-0"><thead class="table-light"><tr><th>Account</th><th>Type</th><th>Entry</th><th>Amount</th></tr></thead><tbody>
              <?php
                $ledger = $tx['ledger'] ?? [];
                if (empty($ledger) && !empty($tx['from_account_id'])) {
                    $ledger = [
                        ['account_number'=>$tx['from_account_number']??'', 'type_name'=>$tx['from_type']??'', 'entry_type'=>'debit', 'amount'=>$tx['amount']],
                        ['account_number'=>$tx['to_account_number']??'', 'type_name'=>$tx['to_type']??'', 'entry_type'=>'credit', 'amount'=>$tx['amount']],
                    ];
                }
                foreach ($ledger as $le):
              ?>
                <tr><td><?= e($le['account_number'] ?? '') ?><br><small class="text-muted"><?= e($le['type_name'] ?? '') ?></small></td><td><?= e($le['account_number'] ? '' : '') ?></td><td><span class="badge <?= ($le['entry_type']==='credit'?'text-bg-success':'text-bg-danger') ?>"><?= e($le['entry_type']) ?></span></td><td class="fw-bold">$<?= number_format((float)$le['amount'],2) ?></td></tr>
              <?php endforeach; ?>
              <?php if (empty($ledger)): ?><tr><td colspan="4" class="text-center text-muted py-3">No ledger entries yet — transaction is pending OTP confirmation.</td></tr><?php endif; ?>
            </tbody></table></div>
          </div>
        </div>

        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white fw-bold"><i class="bi bi-clock-history me-2"></i>Event Timeline</div>
          <div class="card-body">
            <?php $events = $tx['events'] ?? []; if (!$events): ?><div class="text-muted small">No events yet.</div>
            <?php else: foreach ($events as $ev): ?>
              <div class="timeline-item"><div class="timeline-dot"></div><div class="d-flex justify-content-between"><strong><?= e($ev['event_type']) ?></strong><span class="small text-muted"><?= e($ev['created_at']) ?></span></div><div class="small text-muted"><?= e(($ev['old_status']??'').' → '.($ev['new_status']??'')) ?> <?php if (!empty($ev['metadata'])): ?><br><code class="small"><?= e(is_string($ev['metadata'])?$ev['metadata']:json_encode($ev['metadata'])) ?></code><?php endif; ?></div></div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
