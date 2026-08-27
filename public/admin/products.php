<?php
require_once dirname(__DIR__, 2) . '/app/helpers.php';
require_role('admin');
require_once dirname(__DIR__, 2) . '/app/Services/SavingsProductService.php';
require_once dirname(__DIR__, 2) . '/app/Services/InterestEngineService.php';
require_once dirname(__DIR__, 2) . '/app/Services/FixedDepositService.php';
require_once dirname(__DIR__, 2) . '/app/Services/LoanService.php';
$db = Database::connection();
$svc = new SavingsProductService($db); $engine = new InterestEngineService($db);
$error = null; $success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $adminId = (int)auth_user()['id'];
        $action = $_POST['action'] ?? '';
        if ($action === 'update') {
            $svc->updateProduct((int)$_POST['id'], $_POST, $adminId);
            $success = t('Savings product updated.');
        } elseif ($action === 'create') {
            $svc->createProduct($_POST, $adminId);
            $success = t('Savings product created.');
        } elseif ($action === 'loan_update') {
            $svc->updateLoanProduct((int)$_POST['id'], $_POST, $adminId);
            $success = t('Loan product updated.');
        } elseif ($action === 'run_engine') {
            $posted = $engine->run();
            $matured = (new FixedDepositService($db))->processMaturities();
            $late = (new LoanService($db))->processLate();
            $success = t('Engine run: %s interest postings, %s deposits matured, %s loans defaulted.', null, [count($posted), (string)$matured, (string)$late['defaulted']]);
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$products = $svc->products(false);
$loanProducts = $svc->loanProducts(false);
$recent = $engine->history(null, 25);
$pageTitle = t('Banking Products');
$adminCurrent = 'products';
require __DIR__ . '/partials/header.php';
?>
<main class="container-fluid p-3 p-lg-4">
  <?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?=e($success)?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="fw-bold mb-0"><i class="bi bi-diagram-3 me-2"></i><?=e(t('Banking Products'))?></h2>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="run_engine">
      <button class="btn btn-primary"><i class="bi bi-play-circle me-1"></i><?=e(t('Run daily engine (interest · maturities · late loans)'))?></button>
    </form>
  </div>
  <p class="text-muted small"><?=e(t('All rates are simulated. The engine posts interest to the ledger, marks matured deposits and flags late installments.'))?></p>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-bold"><?=e(t('Savings products'))?></div>
    <div class="table-responsive"><table class="table align-middle mb-0 small">
      <thead class="table-light"><tr><th><?=e(t('Code'))?></th><th><?=e(t('Name'))?></th><th><?=e(t('Rate % p.a.'))?></th><th><?=e(t('Min. opening'))?></th><th><?=e(t('Min. daily'))?></th><th><?=e(t('Frequency'))?></th><th><?=e(t('Withdrawals'))?></th><th><?=e(t('Status'))?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($products as $p): ?>
        <tr>
          <td class="font-monospace"><?=e($p['code'])?></td>
          <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=$p['id']?>">
          <td><input name="name" value="<?=e($p['name'])?>" class="form-control form-control-sm" style="min-width:140px"></td>
          <td><input name="interest_rate" value="<?=e($p['interest_rate'])?>" class="form-control form-control-sm" style="width:80px"></td>
          <td><input name="min_opening_balance" value="<?=e($p['min_opening_balance'])?>" class="form-control form-control-sm" style="width:110px"></td>
          <td><input name="min_daily_balance" value="<?=e($p['min_daily_balance'])?>" class="form-control form-control-sm" style="width:110px"></td>
          <td><select name="calc_frequency" class="form-select form-select-sm"><option value="daily" <?=$p['calc_frequency']==='daily'?'selected':''?>><?=e(t('daily'))?></option><option value="monthly" <?=$p['calc_frequency']==='monthly'?'selected':''?>><?=e(t('monthly'))?></option></select></td>
          <td><select name="withdrawal_restriction" class="form-select form-select-sm"><?php foreach (['none','limited','restricted','locked'] as $wr): ?><option value="<?=$wr?>" <?=$p['withdrawal_restriction']===$wr?'selected':''?>><?=e(t($wr))?></option><?php endforeach; ?></select></td>
          <td><select name="status" class="form-select form-select-sm"><option value="active" <?=$p['status']==='active'?'selected':''?>><?=e(t('active'))?></option><option value="inactive" <?=$p['status']==='inactive'?'selected':''?>><?=e(t('inactive'))?></option></select></td>
          <td><button class="btn btn-sm btn-outline-primary"><?=e(t('Save'))?></button></td>
          </form>
        </tr>
        <?php endforeach; ?>
        <tr>
          <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create">
          <td><input name="code" class="form-control form-control-sm" placeholder="CODE" required></td>
          <td><input name="name" class="form-control form-control-sm" placeholder="<?=e(t('Name'))?>" required></td>
          <td><input name="interest_rate" class="form-control form-control-sm" style="width:80px" placeholder="4.0"></td>
          <td><input name="min_opening_balance" class="form-control form-control-sm" style="width:110px" placeholder="1000"></td>
          <td><input name="min_daily_balance" class="form-control form-control-sm" style="width:110px" placeholder="0"></td>
          <td><select name="calc_frequency" class="form-select form-select-sm"><option value="monthly"><?=e(t('monthly'))?></option><option value="daily"><?=e(t('daily'))?></option></select></td>
          <td><select name="withdrawal_restriction" class="form-select form-select-sm"><option value="none"><?=e(t('none'))?></option><option value="limited"><?=e(t('limited'))?></option><option value="restricted"><?=e(t('restricted'))?></option><option value="locked"><?=e(t('locked'))?></option></select></td>
          <td></td>
          <td><button class="btn btn-sm btn-success"><?=e(t('Add'))?></button></td>
          </form>
        </tr>
      </tbody>
    </table></div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-bold"><?=e(t('Loan products'))?></div>
    <div class="table-responsive"><table class="table align-middle mb-0 small">
      <thead class="table-light"><tr><th><?=e(t('Name'))?></th><th><?=e(t('Rate % p.a.'))?></th><th><?=e(t('Min. amount'))?></th><th><?=e(t('Max. amount'))?></th><th><?=e(t('Tenor (months)'))?></th><th><?=e(t('Status'))?></th><th></th></tr></thead>
      <tbody>
        <?php foreach ($loanProducts as $p): ?>
        <tr>
          <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="loan_update"><input type="hidden" name="id" value="<?=$p['id']?>">
          <td><?=e($p['name'])?></td>
          <td><input name="annual_rate" value="<?=e($p['annual_rate'])?>" class="form-control form-control-sm" style="width:80px"></td>
          <td><input name="min_amount" value="<?=e($p['min_amount'])?>" class="form-control form-control-sm" style="width:120px"></td>
          <td><input name="max_amount" value="<?=e($p['max_amount'])?>" class="form-control form-control-sm" style="width:130px"></td>
          <td class="d-flex gap-1"><input name="min_tenor_months" value="<?=e((int)$p['min_tenor_months'])?>" class="form-control form-control-sm" style="width:70px"><input name="max_tenor_months" value="<?=e((int)$p['max_tenor_months'])?>" class="form-control form-control-sm" style="width:70px"></td>
          <td><select name="status" class="form-select form-select-sm"><option value="active" <?=$p['status']==='active'?'selected':''?>><?=e(t('active'))?></option><option value="inactive" <?=$p['status']==='inactive'?'selected':''?>><?=e(t('inactive'))?></option></select></td>
          <td><button class="btn btn-sm btn-outline-primary"><?=e(t('Save'))?></button></td>
          </form>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-bold"><?=e(t('Recent interest postings'))?></div>
    <div class="table-responsive"><table class="table table-sm mb-0 small">
      <thead class="table-light"><tr><th><?=e(t('Account'))?></th><th><?=e(t('Period'))?></th><th><?=e(t('Days'))?></th><th><?=e(t('Avg. daily balance'))?></th><th><?=e(t('Rate'))?></th><th><?=e(t('Interest'))?></th><th><?=e(t('Reference'))?></th><th><?=e(t('Date'))?></th></tr></thead>
      <tbody>
        <?php if (!$recent): ?><tr><td colspan="8" class="text-center text-muted py-3"><?=e(t('No interest posted yet. Run the engine or wait for the daily worker.'))?></td></tr><?php endif; ?>
        <?php foreach ($recent as $r): ?><tr>
          <td class="font-monospace"><?=e($r['account_number'])?></td><td><?=e($r['period_start'])?> → <?=e($r['period_end'])?></td><td><?=(int)$r['days']?></td>
          <td><?=format_money((float)$r['avg_daily_balance'])?></td><td><?=e($r['annual_rate'])?>%</td><td class="text-success fw-bold"><?=format_money((float)$r['amount'])?></td>
          <td class="font-monospace"><?=e($r['reference'])?></td><td><?=e($r['created_at'])?></td>
        </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
