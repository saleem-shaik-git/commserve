<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user();
$db = Database::connection();
$svc = new AccountService($db);
$accounts = $svc->getAccounts((int)$user['id']);
$total = $svc->getTotalBalance((int)$user['id']);
$pageTitle = 'Accounts';
$currentPage = 'accounts';
$totalBalance = $total;
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="fw-bold mb-1">Account Management</h3>
    <p class="text-muted mb-0">Manage your Savings and Current accounts — balances, details, statements.</p>
  </div>
  <a href="/commserve/public/statements.php" class="btn btn-outline-primary"><i class="bi bi-file-earmark-text me-2"></i>Statements</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="stat-card"><div class="mini-stat">Total Accounts</div><h3 class="fw-bold mt-2"><?= count($accounts) ?></h3><div class="small text-muted">Active: <?= count(array_filter($accounts, fn($a)=>$a['status']==='active')) ?></div></div></div>
  <div class="col-md-3"><div class="stat-card"><div class="mini-stat">Total Balance</div><h3 class="fw-bold mt-2">₦<?= number_format($total,2) ?></h3><div class="small text-muted">NGN</div></div></div>
  <div class="col-md-3"><div class="stat-card"><div class="mini-stat">Savings</div><h3 class="fw-bold mt-2"><?= count(array_filter($accounts, fn($a)=>strtolower($a['type_name'])==='savings')) ?></h3><div class="small text-muted">Accounts</div></div></div>
  <div class="col-md-3"><div class="stat-card"><div class="mini-stat">Current</div><h3 class="fw-bold mt-2"><?= count(array_filter($accounts, fn($a)=>strtolower($a['type_name'])==='current')) ?></h3><div class="small text-muted">Accounts</div></div></div>
</div>

<div class="row g-4">
  <?php foreach ($accounts as $a): ?>
  <div class="col-lg-6">
    <div class="card account-card h-100 border-0">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between">
          <div>
            <span class="badge <?= strtolower($a['type_name'])==='savings'?'text-bg-primary':'text-bg-dark' ?>"><?= e(strtoupper($a['type_name'])) ?></span>
            <span class="badge text-bg-light border ms-1"><?= e($a['status']) ?></span>
          </div>
          <div class="dropdown">
            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="/commserve/public/account.php?id=<?= $a['id'] ?>">View details</a></li>
              <li><a class="dropdown-item" href="/commserve/public/transactions.php?account=<?= $a['id'] ?>">Transaction history</a></li>
              <li><a class="dropdown-item" href="/commserve/public/statements.php?account=<?= $a['id'] ?>">Download statement</a></li>
            </ul>
          </div>
        </div>
        <h2 class="fw-bold mt-3">₦<?= number_format((float)$a['available_balance'],2) ?></h2>
        <div class="account-number text-muted">Account: <?= e($a['account_number']) ?> · <?= e($a['currency']) ?></div>
        <div class="mt-3 d-flex gap-2 flex-wrap">
          <a href="/commserve/public/account.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>Details</a>
          <a href="/commserve/public/transfer.php?from=<?= $a['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-send me-1"></i>Transfer</a>
          <a href="/commserve/public/statements.php?account=<?= $a['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-arrow-down me-1"></i>Statement</a>
        </div>
        <hr class="my-3">
        <div class="row small">
          <div class="col-6"><span class="text-muted">Available</span><br><strong>₦<?= number_format((float)$a['available_balance'],2) ?></strong></div>
          <div class="col-6"><span class="text-muted">Ledger Balance</span><br><strong>₦<?= number_format((float)$a['available_balance'],2) ?></strong></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mt-5">
  <div class="card-body p-4">
    <h5 class="fw-bold"><i class="bi bi-info-circle me-2"></i>About your accounts</h5>
    <div class="row mt-3">
      <div class="col-md-6"><h6 class="fw-semibold">Savings Account</h6><p class="small text-muted">Ideal for personal savings, earns simulated interest, low fees, daily limits apply.</p></div>
      <div class="col-md-6"><h6 class="fw-semibold">Current Account</h6><p class="small text-muted">For daily transactions, business use, higher limits, cheque support (simulated).</p></div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
