<?php
// Shared admin header: brand bar with language selector + logout, followed by
// a breadcrumb-style quick navigation (single scrollable line on mobile).
if (!isset($user)) $user = auth_user();
$adminCurrent = $adminCurrent ?? '';
$adminNav = [
    'dashboard'     => [t('Dashboard'), 'admin/'],
    'customers'     => [t('Customers'), 'admin/customers.php'],
    'accounts'      => [t('Accounts'), 'admin/accounts.php'],
    'transactions'  => [t('Transactions'), 'admin/transactions.php'],
    'approvals'     => [t('Approvals'), 'admin/approvals.php'],
    'limits'        => [t('Transfer Limits'), 'admin/limits.php'],
    'billers'       => [t('Billers & Services'), 'admin/billers.php'],
    'compliance'    => [t('Compliance & Risk'), 'admin/compliance.php'],
    'reconciliation'=> [t('Reconciliation'), 'admin/reconciliation.php'],
    'audit'         => [t('Audit Logs'), 'admin/audit.php'],
    'reports'       => [t('Reports & Analytics'), 'admin/reports.php'],
    'executive'     => [t('Executive Intelligence'), 'admin/executive.php'],
    'funding'       => [t('Account Funding'), 'admin/funding.php'],
];
// Unread customer messages badge (best effort).
$chatUnreadAdmin = 0;
try { require_once dirname(__DIR__, 3) . '/app/Services/ChatService.php'; $chatUnreadAdmin = (new ChatService(Database::connection()))->unreadForAdmin(); } catch (Throwable $e) { $chatUnreadAdmin = 0; }
$adminNav['chat'] = [t('Live Chat') . ($chatUnreadAdmin > 0 ? ' (' . $chatUnreadAdmin . ')' : ''), 'admin/support-chat.php'];
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle ?? t('Admin')) ?> · <?= e(t('Admin')) ?> - <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?= url('admin/') ?>">
      <span class="brand-mark-sm">C</span> CommServe Bank <span class="text-white-50">· <?= e(t('Admin')) ?></span>
    </a>
    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end ms-auto">
      <span class="text-white-50 small d-none d-md-inline"><?= e(t('Signed in as')) ?> <?= e($user['name'] ?? $user['email'] ?? t('Admin')) ?></span>
      <?= language_selector('form-select form-select-sm w-auto') ?>
      <form method="post" action="<?= url('logout.php') ?>" class="d-inline">
        <?= csrf_field() ?>
        <button class="btn btn-outline-light btn-sm" title="<?= e(t('Logout')) ?>"><i class="bi bi-box-arrow-right me-1"></i><?= e(t('Logout')) ?></button>
      </form>
    </div>
  </div>
</nav>
<nav class="admin-breadcrumb bg-white border-bottom" aria-label="<?= e(t('Menu')) ?>">
  <div class="container-fluid py-2">
    <ol class="breadcrumb mb-0 small">
      <?php foreach ($adminNav as $key => [$label, $href]): ?>
        <?php if ($key === $adminCurrent): ?>
          <li class="breadcrumb-item active fw-semibold" aria-current="page"><?= e($label) ?></li>
        <?php else: ?>
          <li class="breadcrumb-item"><a class="text-decoration-none" href="<?= url($href) ?>"><?= e($label) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ol>
  </div>
</nav>
