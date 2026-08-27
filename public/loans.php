<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/LoanService.php';
require_once dirname(__DIR__) . '/app/Services/CreditService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user(); $db = Database::connection();
$svc = new LoanService($db); $accountService = new AccountService($db);
$uid = (int)$user['id'];
$error = null; $success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'apply') {
            $svc->apply($uid, (int)($_POST['product_id'] ?? 0), (int)($_POST['account_id'] ?? 0), trim((string)($_POST['amount'] ?? '')), (int)($_POST['tenor'] ?? 0), (string)($_POST['purpose'] ?? ''));
            $success = t('Application submitted. Your credit score has been assessed — track the decision below.');
        } elseif ($action === 'repay') {
            $row = $svc->repay($uid, (int)($_POST['loan_id'] ?? 0), (int)($_POST['account_id'] ?? 0), trim((string)($_POST['amount'] ?? '')), trim((string)($_POST['pin'] ?? '')));
            $success = t('Repayment of $%s posted (interest $%s, principal $%s). Outstanding: $%s.', null, [number_format((float)$row['amount'],2), number_format((float)$row['interest_part'],2), number_format((float)$row['principal_part'],2), number_format((float)$row['outstanding'],2)]);
            if ($row['completed']) $success .= ' ' . t('Loan completed — well done!');
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$credit = (new CreditService($db))->score($uid);
$products = $svc->loanProducts();
$accounts = $accountService->getAccounts($uid);
$applications = $svc->applicationsForUser($uid);
$loans = $svc->loansForUser($uid);
$schedules = [];
foreach ($loans as $l) { $schedules[$l['id']] = $svc->schedule((int)$l['id']); }
$totalBalance = $accountService->getTotalBalance($uid);
$pageTitle = t('Loans'); $currentPage = 'loans';
require __DIR__ . '/partials/header.php'; require __DIR__ . '/partials/sidebar.php';
$bandColor = match ($credit['band']) { 'Excellent' => 'success', 'Good' => 'primary', 'Fair' => 'info', 'Weak' => 'warning', default => 'danger' };
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div><h3 class="fw-bold mb-1"><?=e(t('Loans'))?></h3><p class="text-muted mb-0"><?=e(t('Apply, track and repay — every disbursement and repayment hits the ledger.'))?></p></div>
</div>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?=e($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?=e($success)?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-4 text-center">
      <div class="small text-muted"><?=e(t('Your credit score'))?></div>
      <div class="display-4 fw-bold text-<?=$bandColor?>"><?=(int)$credit['score']?></div>
      <span class="badge text-bg-<?=$bandColor?>"><?=e(t($credit['band']))?></span>
      <div class="small text-muted mt-2"><?=e(t('Based on account age, activity, balances and repayment history.'))?></div>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-4">
      <h5 class="fw-bold"><i class="bi bi-bank me-2"></i><?=e(t('Apply for a loan'))?></h5>
      <form method="post" class="mt-3"><?=csrf_field()?>
        <input type="hidden" name="action" value="apply">
        <div class="mb-3"><label class="form-label"><?=e(t('Product'))?></label><select name="product_id" id="lp" class="form-select" required><?php foreach ($products as $p): ?><option value="<?=$p['id']?>" data-rate="<?=$p['annual_rate']?>" data-min="<?=$p['min_amount']?>" data-max="<?=$p['max_amount']?>" data-tmin="<?=$p['min_tenor_months']?>" data-tmax="<?=$p['max_tenor_months']?>"><?=e($p['name'])?> — <?=e($p['annual_rate'])?>%</option><?php endforeach; ?></select></div>
        <div class="row g-2"><div class="col-7 mb-3"><label class="form-label"><?=e(t('Amount (USD)'))?></label><input name="amount" id="la" class="form-control" inputmode="decimal" required></div>
        <div class="col-5 mb-3"><label class="form-label"><?=e(t('Tenor (months)'))?></label><input name="tenor" id="lt" type="number" class="form-control" required></div></div>
        <div class="mb-3"><label class="form-label"><?=e(t('Disbursement account'))?></label><select name="account_id" class="form-select" required><option value=""><?=e(t('Select account'))?></option><?php foreach ($accounts as $a): ?><option value="<?=$a['id']?>"><?=e($a['type_name'])?> · <?=e($a['account_number'])?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label"><?=e(t('Purpose'))?></label><input name="purpose" class="form-control" maxlength="255"></div>
        <div class="alert alert-info small" id="lEst"><?=e(t('Estimated monthly payment'))?>: —</div>
        <button class="btn btn-primary w-100"><?=e(t('Submit application'))?></button>
      </form>
    </div></div>
  </div>

  <div class="col-lg-8">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-bold"><?=e(t('Applications'))?></div>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th><?=e(t('Product'))?></th><th><?=e(t('Amount'))?></th><th><?=e(t('Tenor'))?></th><th><?=e(t('Score'))?></th><th><?=e(t('Status'))?></th><th><?=e(t('Decision'))?></th></tr></thead>
        <tbody>
          <?php if (!$applications): ?><tr><td colspan="7" class="text-center text-muted py-4"><?=e(t('No applications yet.'))?></td></tr><?php endif; ?>
          <?php foreach ($applications as $a): ?>
          <tr>
            <td>#<?=$a['id']?></td><td><?=e($a['product_name'])?></td><td class="fw-bold"><?=format_money((float)$a['amount'])?></td><td><?=e((int)$a['tenor_months'])?> <?=e(t('mo'))?></td>
            <td><?=(int)($a['credit_score'] ?? 0) ?: '—'?></td>
            <td><span class="badge <?=$a['status']==='disbursed'?'text-bg-success':($a['status']==='approved'?'text-bg-primary':($a['status']==='rejected'?'text-bg-danger':'text-bg-warning'))?>"><?=e(tx_label($a['status']))?></span></td>
            <td class="small text-muted" style="max-width:260px"><?=e($a['decision_reason'] ?? '—')?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <?php foreach ($loans as $l): $sched = $schedules[$l['id']]; ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><span class="fw-bold"><?=e($l['reference'])?></span> <span class="text-muted small">· <?=e($l['product_name'])?> · <?=format_money((float)$l['principal'])?> @ <?=e($l['annual_rate'])?>%</span>
        <span class="badge <?=$l['status']==='active'?'text-bg-primary':($l['status']==='completed'?'text-bg-success':'text-bg-danger')?> ms-1"><?=e(tx_label($l['status']))?></span></div>
        <div class="small"><?=e(t('Monthly'))?>: <b><?=format_money((float)$l['monthly_payment'])?></b> · <?=e(t('Outstanding'))?>: <b><?=format_money((float)$l['outstanding_principal'])?></b> · <?=e(t('Next due'))?>: <?=e($l['next_due_date'] ?: '—')?><?=((int)$l['late_count']>0)?' · <span class="text-danger">'.e((int)$l['late_count']).' '.e(t('late')).'</span>':''?></div>
      </div>
      <div class="card-body">
        <div class="table-responsive"><table class="table table-sm mb-3">
          <thead class="table-light"><tr><th>#</th><th><?=e(t('Due date'))?></th><th><?=e(t('Principal'))?></th><th><?=e(t('Interest'))?></th><th><?=e(t('Total'))?></th><th><?=e(t('Paid'))?></th><th><?=e(t('Status'))?></th></tr></thead>
          <tbody><?php foreach ($sched as $si): ?><tr class="<?=$si['status']==='late'?'table-danger':''?>">
            <td><?=(int)$si['installment_no']?></td><td><?=e($si['due_date'])?></td><td><?=format_money((float)$si['principal_due'])?></td><td><?=format_money((float)$si['interest_due'])?></td><td class="fw-bold"><?=format_money((float)$si['total_due'])?></td><td><?=format_money((float)$si['paid_amount'])?></td>
            <td><span class="badge <?=$si['status']==='paid'?'text-bg-success':($si['status']==='late'?'text-bg-danger':($si['status']==='partial'?'text-bg-warning':'text-bg-secondary'))?>"><?=e(tx_label($si['status']))?></span></td>
          </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php if ($l['status'] === 'active'): ?>
        <form method="post" class="row g-2 align-items-end"><?=csrf_field()?>
          <input type="hidden" name="action" value="repay"><input type="hidden" name="loan_id" value="<?=$l['id']?>">
          <div class="col-md-4"><label class="form-label small"><?=e(t('Repay from'))?></label><select name="account_id" class="form-select form-select-sm" required><?php foreach ($accounts as $a): ?><option value="<?=$a['id']?>"><?=e($a['account_number'])?> ($<?=number_format((float)$a['available_balance'],2)?>)</option><?php endforeach; ?></select></div>
          <div class="col-md-3"><label class="form-label small"><?=e(t('Amount (USD)'))?></label><input name="amount" class="form-control form-control-sm" inputmode="decimal" required></div>
          <div class="col-md-2"><label class="form-label small"><?=e(t('PIN'))?></label><input name="pin" type="password" class="form-control form-control-sm" pattern="\d{4,6}" maxlength="6" required></div>
          <div class="col-md-3"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-cash-stack me-1"></i><?=e(t('Repay'))?></button></div>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$loans): ?><div class="card border-0 shadow-sm"><div class="card-body p-4 text-center text-muted"><?=e(t('No loans yet.'))?></div></div><?php endif; ?>
  </div>
</div>
<script>
(function(){
  const p=document.getElementById('lp'),a=document.getElementById('la'),t=document.getElementById('lt'),est=document.getElementById('lEst');
  function pay(P,annual,n){const r=annual/100/12;return r===0?P/n:P*r/(1-Math.pow(1+r,-n));}
  function upd(){const o=p.selectedOptions[0];if(!o||!a.value||!t.value){return;}const P=parseFloat(a.value),n=parseInt(t.value,10);
    const mn=parseFloat(o.dataset.min),mx=parseFloat(o.dataset.max),tmin=+o.dataset.tmin,tmax=+o.dataset.tmax;
    if(!(P>=mn&&P<=mx&&n>=tmin&&n<=tmax)){est.textContent='<?=e(t('Check the product amount/tenor limits.'))?>';return;}
    est.textContent='<?=e(t('Estimated monthly payment'))?>: $'+pay(P,parseFloat(o.dataset.rate),n).toFixed(2);}
  [p,a,t].forEach(el=>el.addEventListener('input',upd)); upd();
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
