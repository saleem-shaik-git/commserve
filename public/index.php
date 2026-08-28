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
.rate-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #eef1f6}
.rate-row:last-child{border-bottom:0}
.rate-row .pname{font-weight:600;font-size:.95rem}
.rate-row .prate{color:#198754;font-weight:700;font-size:.95rem;white-space:nowrap}
.section-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:#eaf2ff;color:#0d6efd;font-size:20px}
.step-no{width:40px;height:40px;border-radius:50%;background:#0d6efd;color:#fff;display:grid;place-items:center;font-weight:700}
.stat-box{text-align:center;padding:18px 10px}
.stat-box .si{font-size:26px;color:#0d6efd}
.stat-box .st{font-weight:600;margin-top:6px}
.stat-box .sd{font-size:.85rem;color:#6c757d}
.anchor-links a{color:#495057;text-decoration:none;font-size:.92rem}
.anchor-links a:hover{color:#0d6efd}
</style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg landing-nav sticky-top shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?=url('index.php')?>">
      <span class="brand-mark-sm">C</span> CommServe Bank
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#landNav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="landNav">
      <div class="navbar-nav ms-auto anchor-links gap-lg-3 my-2 my-lg-0">
        <a class="nav-link" href="#products"><?=e(t('Our products'))?></a>
        <a class="nav-link" href="#deposits"><?=e(t('Fixed deposits'))?></a>
        <a class="nav-link" href="#loans"><?=e(t('Loans'))?></a>
        <a class="nav-link" href="#digital"><?=e(t('Digital banking'))?></a>
        <a class="nav-link" href="#security"><?=e(t('Security'))?></a>
      </div>
      <div class="d-flex align-items-center gap-2 ms-lg-3 flex-wrap">
        <?= language_selector('form-select form-select-sm w-auto') ?>
        <a href="<?=url('login.php')?>" class="btn btn-outline-primary btn-sm"><?=e(t('Sign in'))?></a>
        <a href="<?=url('register.php')?>" class="btn btn-primary btn-sm"><?=e(t('Create account'))?></a>
      </div>
    </div>
  </div>
</nav>

<!-- HERO -->
<header class="hero py-5">
  <div class="container py-4">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <h1 class="fw-bold display-5 mb-3"><?=e(t('Banking that works as hard as you do'))?></h1>
        <p class="fs-5 text-white-50 mb-4"><?=e(t('Full-service digital banking: savings that earn real ledger-posted interest, fixed deposits, personal loans, cards, crypto and instant transfers — all in one secure app.'))?></p>
        <div class="d-flex gap-2 flex-wrap mb-4">
          <a href="<?=url('register.php')?>" class="btn btn-light btn-lg fw-semibold"><?=e(t('Open your account'))?> <i class="bi bi-arrow-right ms-1"></i></a>
          <a href="#products" class="btn btn-outline-light btn-lg"><?=e(t('Compare our accounts'))?></a>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <span class="hero-chip"><i class="bi bi-journal-bookmark me-1"></i><?=e(t('Ledger-backed balances'))?></span>
          <span class="hero-chip"><i class="bi bi-currency-dollar me-1"></i>USD</span>
          <span class="hero-chip"><i class="bi bi-headset me-1"></i><?=e(t('Live chat support'))?></span>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card bg-white border-0 shadow-lg rounded-4">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h5 class="fw-bold mb-0"><?=e(t('Rates at a glance'))?></h5>
              <a href="#products" class="small text-decoration-none"><?=e(t('See all'))?></a>
            </div>
            <?php
            $rateRows = [];
            foreach (array_slice($savings, 0, 3) as $p) $rateRows[] = [$p['name'], rtrim(rtrim((string)$p['interest_rate'], '0'), '.') . '% ' . t('p.a.')];
            foreach (array_slice($terms, 0, 1) as $p) $rateRows[] = [$p['name'], rtrim(rtrim((string)$p['interest_rate'], '0'), '.') . '% ' . t('p.a.')];
            foreach (array_slice($loanProducts, 0, 1) as $p) $rateRows[] = [$p['name'] . ' — ' . t('from'), rtrim(rtrim((string)$p['annual_rate'], '0'), '.') . '% ' . t('p.a.')];
            ?>
            <?php foreach ($rateRows as [$n, $r]): ?>
              <div class="rate-row"><span class="pname"><?=e($n)?></span><span class="prate"><?=e($r)?></span></div>
            <?php endforeach; ?>
            <?php if (!$rateRows): ?><div class="text-muted small py-2"><?=e(t('Product catalogue is being updated — sign up and check back soon.'))?></div><?php endif; ?>
            <div class="small text-muted mt-3 mb-3"><?=e(t('Interest is calculated daily and posted monthly to the ledger.'))?></div>
            <a href="<?=url('register.php')?>" class="btn btn-primary w-100"><?=e(t('Open your account'))?></a>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- STATS BAND -->
<section class="bg-white border-bottom">
  <div class="container">
    <div class="row g-0">
      <div class="col-6 col-lg-3 stat-box"><div class="si"><i class="bi bi-clock-history"></i></div><div class="st"><?=e(t('24/7 digital banking'))?></div><div class="sd"><?=e(t('Bank anywhere, anytime.'))?></div></div>
      <div class="col-6 col-lg-3 stat-box"><div class="si"><i class="bi bi-lightning-charge"></i></div><div class="st"><?=e(t('Instant account opening'))?></div><div class="sd"><?=e(t('Register in minutes.'))?></div></div>
      <div class="col-6 col-lg-3 stat-box"><div class="si"><i class="bi bi-piggy-bank"></i></div><div class="st"><?=e(t('Interest that compounds'))?></div><div class="sd"><?=e(t('Posted straight to your statement.'))?></div></div>
      <div class="col-6 col-lg-3 stat-box"><div class="si"><i class="bi bi-headset"></i></div><div class="st"><?=e(t('Real people, real time'))?></div><div class="sd"><?=e(t('Live chat with our team.'))?></div></div>
    </div>
  </div>
</section>

<main class="container py-5">

  <!-- PRODUCT WIDGETS -->
  <section class="mb-5" id="products">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
      <div>
        <h2 class="fw-bold mb-1"><?=e(t('Our products'))?></h2>
        <p class="text-muted mb-0"><?=e(t('Everything your money needs, in one bank.'))?></p>
      </div>
    </div>
    <div class="row g-3">
      <?php
      $maxSaveRate = $savings ? max(array_map(fn($p) => (float)$p['interest_rate'], $savings)) : null;
      $bestTerm = $terms ? $terms[0] : null;
      foreach ($terms as $tp) { if ((float)$tp['interest_rate'] > (float)$bestTerm['interest_rate']) $bestTerm = $tp; }
      $minLoanRate = $loanProducts ? min(array_map(fn($p) => (float)$p['annual_rate'], $loanProducts)) : null;
      $loanMin = $loanProducts ? min(array_map(fn($p) => (float)$p['min_amount'], $loanProducts)) : null;
      $loanMax = $loanProducts ? max(array_map(fn($p) => (float)$p['max_amount'], $loanProducts)) : null;
      $trim = fn($n) => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
      $widgets = [
        ['bi-piggy-bank', 'text-bg-success', t('Savings'), $maxSaveRate !== null ? t('Up to %s%% p.a.', null, [$trim($maxSaveRate)]) : null, count($savings) . ' ' . t('savings products to choose from'), url('register.php')'],
        ['bi-lock', 'text-bg-success', t('Fixed deposits'), $bestTerm ? t('Up to %s%% p.a.', null, [$trim((float)$bestTerm['interest_rate'])]) : null, $bestTerm ? t('Terms up to %s days', null, [(string)(int)$bestTerm['default_term_days']]) : null, '#deposits'],
        ['bi-bank', 'text-bg-primary', t('Loans'), $minLoanRate !== null ? t('From %s%% p.a.', null, [$trim($minLoanRate)]) : null, ($loanMin !== null && $loanMax !== null) ? t('Borrow %s – %s', null, [format_money($loanMin), format_money($loanMax)]) : null, '#loans'],
        ['bi-credit-card', 'text-bg-dark', t('Cards'), t('Virtual & debit cards'), t('Freeze instantly, set limits, pay online and at POS.'), '#digital'],
        ['bi-currency-bitcoin', 'text-bg-warning', t('Crypto wallet'), t('Live rates'), t('BTC, ETH, USDT, USDC, XRP at live market rates.'), '#digital'],
        ['bi-lightning-charge', 'text-bg-info', t('Bill payments'), t('One-off & recurring'), t('Electricity, internet, airtime and more.'), '#digital'],
      ];
      foreach ($widgets as [$wIcon, $wColor, $wTitle, $wStat, $wDesc, $wHref]): ?>
      <div class="col-md-6 col-xl-4">
        <a href="<?=$wHref?>" class="text-decoration-none">
          <div class="card product-card border-0 shadow-sm h-100"><div class="card-body p-4">
            <div class="d-flex align-items-center gap-3 mb-3">
              <span class="badge <?=$wColor?> rounded-circle d-grid place-items-center" style="width:46px;height:46px;font-size:20px"><i class="bi <?=$wIcon?>"></i></span>
              <div>
                <div class="fw-bold fs-5 text-dark"><?=$wTitle?></div>
                <?php if ($wStat): ?><div class="fw-semibold text-success small"><?=$wStat?></div><?php endif; ?>
              </div>
            </div>
            <p class="small text-muted mb-3"><?=$wDesc?></p>
            <span class="small fw-semibold text-primary"><?=e(t('Explore'))?> <i class="bi bi-arrow-right"></i></span>
          </div></div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </section>


  <!-- FIXED DEPOSITS -->
  <?php if ($terms): ?>
  <section class="mb-5" id="deposits">
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
          <ul class="list-unstyled small text-muted mb-3">
            <li><i class="bi bi-check2 text-success me-2"></i><?=e(t('Term'))?>: <b><?=e((int)$p['default_term_days'])?> <?=e(t('days'))?></b></li>
            <li><i class="bi bi-check2 text-success me-2"></i><?=e(t('Min. opening'))?>: <b><?=format_money((float)$p['min_opening_balance'])?></b></li>
            <li><i class="bi bi-check2 text-success me-2"></i><?=e(t('Principal + interest is paid to your account at maturity.'))?></li>
          </ul>
          <a href="<?=url('register.php')?>" class="btn btn-outline-primary btn-sm mt-auto"><?=e(t('Start a deposit'))?></a>
        </div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- LOANS -->
  <?php if ($loanProducts): ?>
  <section class="mb-5" id="loans">
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
  <section class="mb-5" id="digital">
    <h2 class="fw-bold mb-1"><?=e(t('Everything in one app'))?></h2>
    <p class="text-muted mb-3"><?=e(t('Digital banking built for daily life.'))?></p>
    <div class="row g-3">
      <?php
      $features = [
        ['bi-arrow-left-right', t('Instant transfers'), t('Own accounts, beneficiaries or any CommServe account number — protected by your transaction PIN.')],
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
  <section class="row g-4 mb-5" id="security">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100"><div class="card-body p-4">
        <h3 class="fw-bold mb-3"><i class="bi bi-shield-check text-primary me-2"></i><?=e(t('Bank-grade controls'))?></h3>
        <ul class="list-unstyled small mb-0">
          <li class="mb-2"><i class="bi bi-journal-bookmark-fill text-success me-2"></i><?=e(t('Double-entry ledger accounting — balances are computed, never overwritten.'))?></li>
          <li class="mb-2"><i class="bi bi-lock-fill text-success me-2"></i><?=e(t('Card numbers and PINs are stored only as hashes.'))?></li>
          <li class="mb-2"><i class="bi bi-clipboard-check-fill text-success me-2"></i><?=e(t('A full audit trail on every action.'))?></li>
          <li><i class="bi bi-bell-fill text-success me-2"></i><?=e(t('Instant notifications for every important account event.'))?></li>
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

<footer class="bg-dark text-white-50 pt-5 pb-4">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-4">
        <div class="d-flex align-items-center gap-2 text-white fw-bold fs-5 mb-2"><span class="brand-mark-sm">C</span> CommServe Bank</div>
        <p class="small"><?=e(t('Full-service digital banking: savings that earn real ledger-posted interest, fixed deposits, personal loans, cards, crypto and instant transfers — all in one secure app.'))?></p>
      </div>
      <div class="col-6 col-lg-2">
        <div class="text-white fw-semibold mb-2"><?=e(t('Products'))?></div>
        <ul class="list-unstyled small">
          <li class="mb-1"><a href="#products" class="text-white-50 text-decoration-none"><?=e(t('Savings'))?></a></li>
          <li class="mb-1"><a href="#deposits" class="text-white-50 text-decoration-none"><?=e(t('Fixed deposits'))?></a></li>
          <li class="mb-1"><a href="#loans" class="text-white-50 text-decoration-none"><?=e(t('Loans'))?></a></li>
        </ul>
      </div>
      <div class="col-6 col-lg-3">
        <div class="text-white fw-semibold mb-2"><?=e(t('Digital banking'))?></div>
        <ul class="list-unstyled small">
          <li class="mb-1"><a href="#digital" class="text-white-50 text-decoration-none"><?=e(t('Instant transfers'))?></a></li>
          <li class="mb-1"><a href="#digital" class="text-white-50 text-decoration-none"><?=e(t('Cards'))?></a></li>
          <li class="mb-1"><a href="#digital" class="text-white-50 text-decoration-none"><?=e(t('Crypto wallet'))?></a></li>
          <li class="mb-1"><a href="#digital" class="text-white-50 text-decoration-none"><?=e(t('Bill payments'))?></a></li>
        </ul>
      </div>
      <div class="col-6 col-lg-3">
        <div class="text-white fw-semibold mb-2"><?=e(t('Support'))?></div>
        <ul class="list-unstyled small">
          <li class="mb-1"><a href="#digital" class="text-white-50 text-decoration-none"><?=e(t('Live chat support'))?></a></li>
          <li class="mb-1"><a href="<?=url('login.php')?>" class="text-white-50 text-decoration-none"><?=e(t('Sign in'))?></a></li>
          <li class="mb-1"><a href="<?=url('register.php')?>" class="text-white-50 text-decoration-none"><?=e(t('Create account'))?></a></li>
        </ul>
      </div>
    </div>
    <hr class="border-secondary my-4">
    <div class="d-flex justify-content-between flex-wrap gap-2 small">
      <span>© <?=date('Y')?> <?=e(APP_NAME)?></span>
      <span><?=e(t('Internet Banking'))?></span>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
