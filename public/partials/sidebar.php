<?php
$current = $currentPage ?? '';
function nav_active(string $key, string $current): string { return $current === $key ? 'active' : ''; }
?>
<aside class="col-lg-2 sidebar p-3 bg-white border-end">
  <div class="d-lg-none mb-3"><div class="card hero-card border-0"><div class="card-body p-3 text-white"><div class="small text-white-50">Total Balance</div><div class="fw-bold fs-5">$<?= number_format($totalBalance ?? 0, 2) ?></div></div></div></div>
  <nav class="nav flex-column gap-1">
    <a class="nav-link <?= nav_active('dashboard', $current) ?>" href="/commserve/public/dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a>
    <a class="nav-link <?= nav_active('accounts', $current) ?>" href="/commserve/public/accounts.php"><i class="bi bi-wallet2 me-2"></i>Accounts</a>
    <a class="nav-link <?= nav_active('crypto', $current) ?>" href="/commserve/public/crypto.php"><i class="bi bi-currency-bitcoin me-2"></i>Crypto</a>
    <a class="nav-link <?= nav_active('transfer', $current) ?>" href="/commserve/public/transfer.php"><i class="bi bi-send me-2"></i>Transfers</a>
    <a class="nav-link <?= nav_active('transactions', $current) ?>" href="/commserve/public/transactions.php"><i class="bi bi-receipt me-2"></i>Transactions</a>
    <a class="nav-link <?= nav_active('statements', $current) ?>" href="/commserve/public/statements.php"><i class="bi bi-file-earmark-text me-2"></i>Statements</a>
    <a class="nav-link <?= nav_active('beneficiaries', $current) ?>" href="/commserve/public/beneficiaries.php"><i class="bi bi-person-lines-fill me-2"></i>Beneficiaries</a>
    <a class="nav-link <?= nav_active('payments', $current) ?>" href="/commserve/public/bill-payments.php"><i class="bi bi-lightning-charge me-2"></i>Bill Payments</a>
    <a class="nav-link" href="/commserve/public/scheduled-payments.php"><i class="bi bi-calendar-check me-2"></i>Scheduled Payments</a>
    <a class="nav-link <?= nav_active('pin', $current) ?>" href="/commserve/public/transaction-pin.php"><i class="bi bi-shield-lock me-2"></i>Transaction PIN</a>
    <div class="mt-3 small text-muted text-uppercase fw-semibold">Support</div>
    <a class="nav-link" href="#"><i class="bi bi-credit-card me-2"></i>Cards <span class="badge text-bg-secondary ms-1">Soon</span></a>
    <a class="nav-link" href="#"><i class="bi bi-headset me-2"></i>Help</a>
  </nav>
  <div class="card border-0 bg-light mt-4"><div class="card-body p-3"><div class="small fw-semibold"><i class="bi bi-info-circle me-1"></i> Demo Mode</div><div class="small text-muted mt-1">All transactions are simulated. No real money moves.</div><a href="/commserve/public/statements.php" class="btn btn-sm btn-outline-primary mt-2 w-100">Get Statement</a></div></div>
</aside>
<main class="col-lg-10 p-3 p-lg-4">
<?php if (!empty($flash_success)): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= e($flash_success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= e($flash_error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
