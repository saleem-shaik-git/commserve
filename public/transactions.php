<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user();
$db = Database::connection();
$accountService = new AccountService($db);

$accounts = $accountService->getAccounts((int)$user['id']);
$totalBalance = $accountService->getTotalBalance((int)$user['id']);

$accountId = (int)($_GET['account'] ?? ($accounts[0]['id'] ?? 0));
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$validIds = array_map('intval', array_column($accounts, 'id'));
if (!in_array($accountId, $validIds, true)) $accountId = (int)($accounts[0]['id'] ?? 0);

$rows = [];
if ($accountId) {
    $where = 'le.account_id=? AND t.status="completed"';
    $params = [$accountId];
    if ($typeFilter !== '') { $where .= ' AND t.type=?'; $params[] = $typeFilter; }
    if ($statusFilter !== '') { $where .= ' AND t.status=?'; $params[] = $statusFilter; }
    if ($from !== '') { $where .= ' AND DATE(t.created_at) >= ?'; $params[] = $from; }
    if ($to !== '') { $where .= ' AND DATE(t.created_at) <= ?'; $params[] = $to; }
    if ($search !== '') { $where .= ' AND (t.reference LIKE ? OR t.description LIKE ?)'; $like = '%' . $search . '%'; $params[] = $like; $params[] = $like; }

    $sql = "SELECT t.reference, t.type, t.status, t.amount, t.currency, t.description, t.created_at, le.entry_type
            FROM ledger_entries le JOIN transactions t ON t.id=le.transaction_id
            WHERE $where ORDER BY t.id DESC LIMIT 200";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// Pending for this user
$pending = $accountService->getPendingTransactions((int)$user['id'], 20);

$pageTitle = t('Transactions');
$currentPage = 'transactions';
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
  <div>
    <h3 class="fw-bold mb-1"><?=e(t('Transactions'))?></h3>
    <p class="text-muted mb-0"><?=e(t('Ledger-backed history — filter by account, type, date, search.'))?></p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?=url('statements.php')?>?account=<?= $accountId ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-text me-1"></i><?=e(t('Statement'))?></a>
    <a href="<?=url('transfer.php')?>" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i><?=e(t('New Transfer'))?></a>
  </div>
</div>

<?php if (!empty($_GET['msg']) && $_GET['msg']==='cancelled'): ?><div class="alert alert-info"><?=e(t('Transfer cancelled.'))?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-9">
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body p-3">
        <form class="row g-2">
          <div class="col-md-3"><label class="form-label small"><?=e(t('Account'))?></label><select name="account" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $accountId===(int)$a['id']?'selected':'' ?>><?= e($a['type_name']) ?> · <?= e($a['account_number']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-2"><label class="form-label small"><?=e(t('Type'))?></label><select name="type" class="form-select form-select-sm"><option value=""><?=e(t('All'))?></option><option value="transfer" <?= $typeFilter==='transfer'?'selected':'' ?>><?=e(t('Transfer'))?></option><option value="deposit" <?= $typeFilter==='deposit'?'selected':'' ?>><?=e(t('Deposit'))?></option><option value="withdrawal" <?= $typeFilter==='withdrawal'?'selected':'' ?>><?=e(t('Withdrawal'))?></option></select></div>
          <div class="col-md-2"><label class="form-label small"><?=e(t('From'))?></label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-2"><label class="form-label small"><?=e(t('To'))?></label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small"><?=e(t('Search'))?></label><div class="input-group input-group-sm"><input name="q" value="<?= e($search) ?>" class="form-control" placeholder="<?=e(t('Ref or desc'))?>"><button class="btn btn-primary"><i class="bi bi-search"></i></button></div></div>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th><?=e(t('Date'))?></th><th><?=e(t('Reference'))?></th><th><?=e(t('Type'))?></th><th><?=e(t('Description'))?></th><th><?=e(t('Direction'))?></th><th><?=e(t('Amount'))?></th><th><?=e(t('Status'))?></th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5"><?=e(t('No transactions found for selected filters.'))?></td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td class="small"><?= e(format_date($r['created_at'])) ?><br><span class="text-muted"><?= e(date('H:i', strtotime($r['created_at']))) ?></span></td>
              <td><a href="<?=url('transaction.php')?>?ref=<?= urlencode($r['reference']) ?>" class="fw-semibold text-decoration-none small"><?= e($r['reference']) ?></a></td>
              <td class="small"><?= e(tx_label($r['type'])) ?></td>
              <td class="small" style="max-width:200px"><?= e($r['description']) ?></td>
              <td><span class="badge <?= $r['entry_type']==='credit'?'text-bg-success':'text-bg-danger' ?>"><?= e(tx_label($r['entry_type'])) ?></span></td>
              <td class="fw-bold <?= $r['entry_type']==='credit'?'text-success':'text-danger' ?>"><?= $r['entry_type']==='credit'?'+':'-' ?>$<?= number_format((float)$r['amount'],2) ?></td>
              <td><span class="badge text-bg-success"><?= e(tx_label($r['status'])) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-3">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-bold small text-muted text-uppercase"><?=e(t('Pending'))?></div>
      <div class="card-body">
        <?php if (!$pending): ?><div class="text-center text-muted small py-3"><?=e(t('No pending'))?></div>
        <?php else: foreach ($pending as $p): ?>
          <div class="border rounded p-2 mb-2 small">
            <div class="d-flex justify-content-between"><span class="fw-semibold"><?= e($p['reference']) ?></span><span class="badge text-bg-warning"><?= e(tx_label($p['status'])) ?></span></div>
            <div class="text-muted">$<?= number_format((float)$p['amount'],2) ?> · <?= e($p['description']??'') ?></div>
            <a href="<?=url('transfer-confirm.php')?>?ref=<?= urlencode($p['reference']) ?>" class="btn btn-sm btn-primary w-100 mt-2"><?=e(t('Confirm OTP'))?></a>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-bold"><?=e(t('Account Summary'))?></div>
      <div class="card-body">
        <?php foreach ($accounts as $a): ?>
          <div class="d-flex justify-content-between small mb-2 <?= $a['id']===$accountId?'fw-bold':'' ?>"><span><?= e($a['type_name']) ?> ****<?= e(substr($a['account_number'],-4)) ?></span><span>$<?= number_format((float)$a['available_balance'],2) ?></span></div>
        <?php endforeach; ?>
        <hr><div class="d-flex justify-content-between fw-bold"><span><?=e(t('Total'))?></span><span>$<?= number_format($totalBalance,2) ?></span></div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
