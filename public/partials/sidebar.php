<?php
$current = $currentPage ?? '';
function nav_active(string $key, string $current): string { return $current === $key ? 'active' : ''; }
?>
<aside class="col-lg-2 sidebar p-3 bg-white border-end">
  <div class="d-lg-none mb-3"><div class="card hero-card border-0"><div class="card-body p-3 text-white"><div class="small text-white-50"><?=e(t('Total Balance'))?></div><div class="fw-bold fs-5">$<?= number_format($totalBalance ?? 0, 2) ?></div></div></div></div>
  <!-- Desktop: vertical sidebar · Mobile: breadcrumb-style chips -->
  <nav class="nav crumb-nav gap-1 flex-row flex-wrap align-items-center flex-lg-column align-items-lg-stretch" aria-label="<?=e(t('Menu'))?>">
    <a class="nav-link <?= nav_active('dashboard', $current) ?>" href="<?=url('dashboard.php')?>"><i class="bi bi-grid me-2"></i><?=e(t('Dashboard'))?></a>
    <a class="nav-link <?= nav_active('accounts', $current) ?>" href="<?=url('accounts.php')?>"><i class="bi bi-wallet2 me-2"></i><?=e(t('Accounts'))?></a>
    <a class="nav-link <?= nav_active('crypto', $current) ?>" href="<?=url('crypto.php')?>"><i class="bi bi-currency-bitcoin me-2"></i><?=e(t('Crypto'))?></a>
    <a class="nav-link <?= nav_active('transfer', $current) ?>" href="<?=url('transfer.php')?>"><i class="bi bi-send me-2"></i><?=e(t('Transfers'))?></a>
    <a class="nav-link <?= nav_active('transactions', $current) ?>" href="<?=url('transactions.php')?>"><i class="bi bi-receipt me-2"></i><?=e(t('Transactions'))?></a>
    <a class="nav-link <?= nav_active('statements', $current) ?>" href="<?=url('statements.php')?>"><i class="bi bi-file-earmark-text me-2"></i><?=e(t('Statements'))?></a>
    <a class="nav-link <?= nav_active('beneficiaries', $current) ?>" href="<?=url('beneficiaries.php')?>"><i class="bi bi-person-lines-fill me-2"></i><?=e(t('Beneficiaries'))?></a>
    <a class="nav-link <?= nav_active('payments', $current) ?>" href="<?=url('bill-payments.php')?>"><i class="bi bi-lightning-charge me-2"></i><?=e(t('Bill Payments'))?></a>
    <a class="nav-link <?= nav_active('scheduled', $current) ?>" href="<?=url('scheduled-payments.php')?>"><i class="bi bi-calendar-check me-2"></i><?=e(t('Scheduled Payments'))?></a>
    <a class="nav-link <?= nav_active('cards', $current) ?>" href="<?=url('cards.php')?>"><i class="bi bi-credit-card me-2"></i><?=e(t('Cards'))?></a>
    <a class="nav-link <?= nav_active('pin', $current) ?>" href="<?=url('transaction-pin.php')?>"><i class="bi bi-shield-lock me-2"></i><?=e(t('Transaction PIN'))?></a>
    <span class="crumb-divider mt-3 small text-muted text-uppercase fw-semibold d-none d-lg-block"><?=e(t('Support'))?></span>
    <a class="nav-link <?= nav_active('chat', $current) ?>" href="<?=url('support-chat.php')?>"><i class="bi bi-chat-dots me-2"></i><?=e(t('Live Chat'))?><?php if(!empty($chat_unread)):?> <span class="badge text-bg-danger ms-1"><?=(int)$chat_unread?></span><?php endif; ?></a>
    <a class="nav-link <?= nav_active('notifications', $current) ?>" href="<?=url('notifications.php')?>"><i class="bi bi-bell me-2"></i><?=e(t('Notifications'))?></a>
    <a class="nav-link <?= nav_active('security', $current) ?>" href="<?=url('security-activity.php')?>"><i class="bi bi-shield-check me-2"></i><?=e(t('Security'))?></a>
  </nav>
  <div class="card border-0 bg-light mt-4 d-none d-lg-block"><div class="card-body p-3"><div class="small fw-semibold"><i class="bi bi-info-circle me-1"></i> <?=e(t('Security'))?></div><div class="small text-muted mt-1"><?=e(t('Every transfer OTP stage is verified by you and approved by an administrator.'))?></div><a href="<?=url('statements.php')?>" class="btn btn-sm btn-outline-primary mt-2 w-100"><?=e(t('Get Statement'))?></a></div></div>
</aside>
<main class="col-lg-10 p-3 p-lg-4">
<?php if (!empty($flash_success)): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= e($flash_success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if (!empty($flash_error)): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= e($flash_error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
