<?php
// Shared header for customer pages
if (!isset($user)) $user = auth_user();
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle ?? 'CommServe') ?> - <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?=url('assets/css/app.css')?>" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?=url('dashboard.php')?>">
      <span class="brand-mark-sm">C</span> CommServe <span class="badge text-bg-warning ms-1">DEMO</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="topNav">
      <div class="ms-auto d-flex align-items-center gap-3 flex-wrap">
        <span class="text-muted small d-none d-md-inline">Simulated environment — no real funds</span>
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-translate text-muted"></i>
          <?= language_selector('form-select form-select-sm w-auto') ?>
        </div>
        <div class="dropdown">
          <a class="btn btn-light btn-sm dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle me-1"></i> <?= e($user['name'] ?? $user['email'] ?? 'User') ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?=url('transaction-pin.php')?>"><i class="bi bi-shield-lock me-2"></i>Transaction PIN</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="<?= url('logout.php') ?>" class="px-3">
                <?= csrf_field() ?>
                <button class="btn btn-outline-danger btn-sm w-100">Logout</button>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</nav>
<div class="container-fluid">
<div class="row">
