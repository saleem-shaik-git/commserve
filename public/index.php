<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/SavingsProductService.php';
require_once dirname(__DIR__) . '/app/Services/LoanService.php';
// Authenticated users skip the marketing page.
if (auth_user()) redirect(auth_user()['role'] === 'admin' ? url('admin/') : url('dashboard.php'));

// Live product catalogue (tolerates a not-yet-migrated database).
$savings = $terms = $loanProducts = [];
try {
    $db = Database::connection();
    $sp = new SavingsProductService($db);
    $savings = array_values(array_filter($sp->products(), fn($p) => !$p['is_term']));
    $terms = $sp->termProducts();
    $loanProducts = (new LoanService($db))->loanProducts();
} catch (Throwable $e) {
    $savings = $terms = $loanProducts = [];
}
?><!doctype html>
<html lang="<?=e(current_locale())?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e(t('CommServe Bank — Internet Banking for everyone'))?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?=url('assets/css/app.css')?>" rel="stylesheet">
<style>
.landing-nav{background:#fff;border-bottom:1px solid #e9ecef}
.hero{background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;border-radius:0 0 28px 28px;overflow:hidden}
.hero-chip{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:6px 14px;font-size:.85rem}
.product-card{transition:transform .15s ease,box-shadow .15s ease}
.product-card:hover{transform:translateY(-3px);box-shadow:0 .5rem 1.5rem rgba(15,23,42,.12)!important}
.rate-badge{font-size:1.05rem}
.section-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:#eaf2ff;color:#0d6efd;font-size:20px}
.step-no{width:40px;height:40px;border-radius:50%;background:#0d6efd;color:#fff;display:grid;place-items:center;font-weight:700}
.feature-icon{font-size:26px;color:#0d6efd}
</style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg landing-nav sticky-top shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?=url('index.php')?>">
      <span class="brand-mark-sm">C</span> CommServe Bank
    </a>
    <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
      <?= language_selector('form-select form-select-sm w-auto') ?>
      <a href="<?=url('login.php')?>" class="btn btn-outline-primary btn-sm"><?=e(t('Sign in'))?></a>
      <a href="<?=url('register.php')?>" class="btn btn-primary btn-sm"><?=e(t('Create account'))?></a>
    </div>
  </div>
</nav>

<!-- HERO -->
<header class="hero py-5">
  <div class="container py-4">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <h1 class="fw-bold display-5 mb-3"><?=e(t('Banking that works as hard as you do'))?></h1>
        <p class="fs-5 text-white-50 mb-4"><?=e(t('Full-service digital banking: savings that earn real ledger-posted interest, fixed deposits, personal loans, cards, crypto and instant transfers — secured by four-stage OTP verification and administrator approval on every transfer.'))?></p>
        <div class="d-flex gap-2 flex-wrap mb-4">
          <a href="<?=url('register.php')?>" class="btn btn-light btn-lg fw-semibold"><?=e(t('Open your account'))?> <i class="bi bi-arrow-right ms-1"></i></a>
          <a href="<?=url('login.php')?>" class="btn btn-outline-light btn-lg"><?=e(t('Sign in'))?></a>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <span class="hero-chip"><i class="bi bi-journal-bookmark me-1"></i><?=e(t('Ledger-backed balances'))?></span>
          <span class="hero-chip"><i class="bi bi-shield-lock me-1"></i><?=e(t('4-stage OTP + admin approval'))?></span>
          <span class="hero-chip"><i class="bi bi-currency-dollar me-1"></i>USD</span>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card bg-white border-0 shadow-lg rounded-4">
          <div class="card-body p-4">
            <div class="small text-muted mb-1"><?=e(t('Example — Premium Savings'))?></div>
            <div class="display-6 fw-bold">$100,000.00</div>
            <div class="text-success small mb-3">+ <?=e(t('6% p.a. — interest posted to your statement'))?></div>
            <div class="border rounded p-2 small mb-2"><i class="bi bi-graph-up-arrow text-success me-2"></i><?=e(t('Interest is calculated daily and posted monthly to the ledger.'))?></div>
            <div class="border rounded p-2 small"><i class="bi bi-shield-check text-primary me-2"></i><?=e(t('Every transfer is released only after admin approval.'))?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="container py-5">

  <!-- SAVINGS PRODUCTS -->
  <section class="mb-5">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
      <div>
        <h2 class="fw-bold mb-1"><?=e(t('Savings products'))?></h2>
        <p class="text-muted mb-0"><?=e(t('Pick the account that fits you — interest is accrued daily and posted straight to your ledger-backed statement.'))?></p>
      </div>
    </div>
    <div class="row g-3">
      <?php if (!$savings): ?>
        <div class="col-12"><div class="alert alert-light border"><?=e(t('Product catalogue is being updated — sign up and check back soon.'))?></div></div>
      <?php endif; ?>
      <?php foreach ($savings as $p): ?>
      <div class="col-md-6 col-xl-4">
        <div class="card product-card border-0 shadow-sm h-100"><div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <h5 class="fw-bold mb-0"><?=e($p['name'])?></h5>
            <span class="badge text-bg-success rate-badge"><?=e($p['interest_rate'])?>% <?=e(t('p.a.'))?></span>
          </div>
          <ul class="list-unstyled small text-muted mb-3">
            <li><i class="bi bi-check2 text-success me-2"></i><?=e(t('Min. opening'))?>: <b><?=format_money((float)$p['min_opening_balance'])?></b></li>
            <li><i class="bi bi-check2 text-success me-2"></i><?=e($p['calc_frequency']==='daily'?t('Daily interest'):t('Monthly interest'))?></li>
            <li><i class="bi bi-check2 text-success me-2"></i><?=e(t('Withdrawals'))?>: <?=e(tx_label($p['withdrawal_restriction']))?></li>
          </ul>
          <a href="<?=url('register.php')?>" class="btn btn-outline-primary btn-sm w-100"><?=e(t('Open account'))?></a>
        </div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- FIXED DEPOSITS -->
  <?php if ($terms): ?>
  <section class="mb-5">
    <h2 class="fw-bold mb-1"><?=e(t('Fixed deposits'))?></h2>
    <p class="text-muted mb-3"><?=e(t('Lock funds for a fixed term at a fixed rate — principal and interest are paid to your account at maturity. Early withdrawal available with a penalty.'))?></p>
    <div class="row g-3">
      <?php foreach ($terms as $p): ?>
      <div class="col-md-6">
        <div class="card product-card border-0 shadow-sm h-100"><div class="card-body p-4 d-flex flex-column">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="fw-bold mb-0"><?=e($p['name'])?></h5>
            <span class="badge text-bg-success rate-badge"><?=e($p['interest_rate'])?>% <?=e(t('p.a.'))?></span>
          </div>
          <div class="small text-muted mb-3"><?=e(t('Term'))?>: <b><?=e((int)$p['default_term_days'])?> <?=e(t('days'))?></b> · <?=e(t('Min. opening'))?>: <b><?=format_money((float)$p['min_opening_balance'])?></b></div>
          <div class="border rounded p-2 small mb-3"><?=e(t('Example'))?>: <?=format_money((float)$p['min_opening_balance'])?> × <?=e((int)$p['default_term_days'])?> <?=e(t('days'))?> → <b class="text-success">+<?=format_money(round((float)$p['min_opening_balance']*((float)$p['interest_rate']/100)*((int)$p['default_term_days']/365),2))?></b> <?=e(t('interest at maturity'))?></div>
          <a href="<?=url('register.php')?>" class="btn btn-outline-primary btn-sm mt-auto"><?=e(t('Start a deposit'))?></a>
        </div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- LOANS -->
  <?php if ($loanProducts): ?>
  <section class="mb-5">
    <h2 class="fw-bold mb-1"><?=e(t('Loans'))?></h2>
    <p class="text-muted mb-3"><?=e(t('Apply in minutes — every application is credit-scored from your real account history, and repayments post straight to the ledger.'))?></p>
    <div class="row g-3">
      <?php foreach ($loanProducts as $p): ?>
      <div class="col-md-4">
        <div class="card product-card border-0 shadow-sm h-100"><div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="fw-bold mb-0"><?=e($p['name'])?></h5>
            <span class="badge text-bg-primary rate-badge"><?=e($p['annual_rate'])?>% <?=e(t('p.a.'))?></span>
          </div>
          <ul class="list-unstyled small text-muted mb-3">
            <li><i class="bi bi-check2 text-success me-2"></i><?=format_money((float)$p['min_amount'])?> – <?=format_money((float)$p['max_amount'])?></li>
            <li><i class="bi bi-check2 text-success me-2"></i><?=e((int)$p['min_tenor_months'])?>–<?=e((int)$p['max_tenor_months'])?> <?=e(t('months'))?></li>
          </ul>
          <a href="<?=url('register.php')?>" class="btn btn-outline-primary btn-sm w-100"><?=e(t('Apply for a loan'))?></a>
        </div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- DIGITAL BANKING FEATURES -->
  <section class="mb-5">
    <h2 class="fw-bold mb-1"><?=e(t('Everything in one app'))?></h2>
    <p class="text-muted mb-3"><?=e(t('Digital banking built for daily life.'))?></p>
    <div class="row g-3">
      <?php
      $features = [
        ['bi-arrow-left-right', t('Instant transfers'), t('Own accounts, beneficiaries or any CommServe account number — protected by PIN, four OTP stages (COT → IMF → Tax Code → Final) emailed to you and released by an administrator.')],
        ['bi-credit-card', t('Cards'), t('Virtual and debit cards with freeze controls, PIN and per-card limits, online and POS payments.')],
        ['bi-currency-bitcoin', t('Crypto wallet'), t('Buy and sell BTC, ETH, USDT, USDC and XRP against your account balance at live market rates, with conversions and PIN-verified sends.')],
        ['bi-lightning-charge', t('Bill payments'), t('Electricity, internet, cable, airtime, data and water — one-time or recurring on your schedule.')],
        ['bi-chat-dots', t('Live chat support'), t('Talk to the bank in real time — our team answers right inside the app.')],
        ['bi-file-earmark-text', t('Statements'), t('Ledger-generated PDF and CSV statements with running balances, any period.')],
      ];
      foreach ($features as [$icon, $title, $desc]): ?>
      <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body p-4">
          <div class="section-icon mb-3"><i class="bi <?=$icon?>"></i></div>
          <h5 class="fw-bold"><?=e($title)?></h5>
          <p class="small text-muted mb-0"><?=e($desc)?></p>
        </div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- SECURITY + STEPS -->
  <section class="row g-4 mb-5">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100"><div class="card-body p-4">
        <h3 class="fw-bold mb-3"><i class="bi bi-shield-check text-primary me-2"></i><?=e(t('Bank-grade controls, simulated money'))?></h3>
        <ul class="list-unstyled small mb-0">
          <li class="mb-2"><i class="bi bi-lock-fill text-success me-2"></i><?=e(t('One-time passwords are emailed and expire in 10 minutes — nothing is displayed on screen.'))?></li>
          <li class="mb-2"><i class="bi bi-person-check-fill text-success me-2"></i><?=e(t('Every OTP stage and every transfer release is approved by a bank administrator.'))?></li>
          <li class="mb-2"><i class="bi bi-journal-bookmark-fill text-success me-2"></i><?=e(t('Double-entry ledger accounting — balances are computed, never overwritten.'))?></li>
          <li><i class="bi bi-clipboard-check-fill text-success me-2"></i><?=e(t('Full audit trail on every action, with maker-checker on admin operations.'))?></li>
        </ul>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100"><div class="card-body p-4">
        <h3 class="fw-bold mb-3"><?=e(t('Join in three steps'))?></h3>
        <?php $steps = [[t('Register'), t('Create your free account in minutes — no paperwork.')],[t('Verify'), t('Complete KYC to unlock transfers and loans.')],[t('Start banking'), t('Open savings products, place deposits and apply for loans.')]];
        foreach ($steps as $i => [$title, $desc]): ?>
        <div class="d-flex gap-3 mb-3">
          <div class="step-no"><?=$i+1?></div>
          <div><div class="fw-semibold"><?=$title?></div><div class="small text-muted"><?=$desc?></div></div>
        </div>
        <?php endforeach; ?>
        <a href="<?=url('register.php')?>" class="btn btn-primary w-100 mt-2"><?=e(t('Open your account'))?></a>
      </div></div>
    </div>
  </section>
</main>

<footer class="bg-white border-top py-4">
  <div class="container d-flex justify-content-between flex-wrap gap-2 small text-muted">
    <span>© <?=date('Y')?> <?=e(APP_NAME)?> · <?=e(t('All rates are simulated — no real financial institution is involved.'))?></span>
    <span><a href="<?=url('login.php')?>" class="text-decoration-none"><?=e(t('Sign in'))?></a> · <a href="<?=url('register.php')?>" class="text-decoration-none"><?=e(t('Create account'))?></a></span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
