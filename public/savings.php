<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/SavingsProductService.php';
require_once dirname(__DIR__) . '/app/Services/InterestEngineService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user(); $db = Database::connection();
$svc = new SavingsProductService($db); $interest = new InterestEngineService($db); $accountService = new AccountService($db);
$uid = (int)$user['id'];
$error = null; $success = null; $opened = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $opened = $svc->openAccount($uid, (int)($_POST['product_id'] ?? 0), (int)($_POST['from_account'] ?? 0), trim((string)($_POST['amount'] ?? '')), trim((string)($_POST['pin'] ?? '')));
        $success = t('Account opened. %s is ready — reference %s.', null, [$opened['account_number'], $opened['reference']]);
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$products = array_values(array_filter($svc->products(), fn($p) => !$p['is_term']));
$myAccounts = $svc->accountsForUser($uid);
$accounts = $accountService->getAccounts($uid);
$totalBalance = $accountService->getTotalBalance($uid);
$pageTitle = t('Savings'); $currentPage = 'savings';
require __DIR__ . '/partials/header.php'; require __DIR__ . '/partials/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div><h3 class="fw-bold mb-1"><?=e(t('Savings'))?></h3><p class="text-muted mb-0"><?=e(t('Choose a savings product — interest is calculated daily and posted straight to your ledger.'))?></p></div>
  <a href="<?=url('fixed-deposits.php')?>" class="btn btn-outline-primary"><i class="bi bi-lock me-1"></i><?=e(t('Fixed Deposits'))?></a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?=e($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?=e($success)?></div><?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($products as $p): ?>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 border-0 shadow-sm"><div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-start">
        <h5 class="fw-bold mb-1"><?=e($p['name'])?></h5>
        <span class="badge text-bg-success fs-6"><?=e($p['interest_rate'])?>% <?=e(t('p.a.'))?></span>
      </div>
      <div class="small text-muted mb-3"><?=e($p['calc_frequency']==='daily'?t('Daily interest'):t('Monthly interest'))?> · <?=e(t('Min. opening'))?>: <?=format_money((float)$p['min_opening_balance'])?></div>
      <ul class="list-unstyled small mb-3">
        <li><i class="bi bi-check2 text-success me-2"></i><?=e(t('Withdrawals'))?>: <?=e(tx_label($p['withdrawal_restriction']))?><?=((int)$p['max_withdrawals_per_month']>0)?' ('.(int)$p['max_withdrawals_per_month'].'/'.t('month').')':''?></li>
        <li><i class="bi bi-check2 text-success me-2"></i><?=e(t('Min. daily balance'))?>: <?=((float)$p['min_daily_balance']>0)?format_money((float)$p['min_daily_balance']):t('None')?></li>
      </ul>
      <button class="btn btn-primary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#openModal" data-product="<?=$p['id']?>" data-name="<?=e($p['name'])?>" data-min="<?=number_format((float)$p['min_opening_balance'],2,'.','')?>"><?=e(t('Open account'))?></button>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-bold"><i class="bi bi-piggy-bank me-2"></i><?=e(t('My savings accounts'))?></div>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead class="table-light"><tr><th><?=e(t('Account'))?></th><th><?=e(t('Product'))?></th><th><?=e(t('Balance'))?></th><th><?=e(t('Rate'))?></th><th><?=e(t('Interest paid'))?></th><th><?=e(t('Projected 30d'))?></th><th><?=e(t('Status'))?></th></tr></thead>
    <tbody>
      <?php if (!$myAccounts): ?><tr><td colspan="7" class="text-center text-muted py-4"><?=e(t('No product accounts yet.'))?></td></tr><?php endif; ?>
      <?php foreach ($myAccounts as $a): ?>
      <tr>
        <td class="font-monospace small"><?=e($a['account_number'])?></td>
        <td><?=e($a['product_name'])?></td>
        <td class="fw-bold"><?=format_money((float)$a['available_balance'])?></td>
        <td><?=e($a['interest_rate'])?>%</td>
        <td class="text-success"><?=format_money((float)$a['interest_paid'])?></td>
        <td class="text-success">≈ <?=format_money($interest->preview30((int)$a['id']))?></td>
        <td><span class="badge text-bg-<?=($a['status']==='active'?'success':'secondary')?>"><?=e(tx_label($a['status']))?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- Open-account modal -->
<div class="modal fade" id="openModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><?=csrf_field()?>
  <div class="modal-header"><h5 class="modal-title"><?=e(t('Open account'))?> — <span id="mName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" name="product_id" id="mProduct">
    <div class="mb-3"><label class="form-label"><?=e(t('From Account'))?></label><select name="from_account" class="form-select" required><option value=""><?=e(t('Select account'))?></option><?php foreach ($accounts as $a): ?><option value="<?=$a['id']?>"><?=e($a['type_name'])?> · <?=e($a['account_number'])?> · $<?=number_format((float)$a['available_balance'],2)?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><label class="form-label"><?=e(t('Amount (USD)'))?> <span class="text-muted" id="mMin"></span></label><div class="input-group"><span class="input-group-text">$</span><input name="amount" class="form-control" inputmode="decimal" required></div></div>
    <div class="mb-3"><label class="form-label"><?=e(t('Transaction PIN'))?></label><input name="pin" type="password" class="form-control" inputmode="numeric" pattern="\d{4,6}" maxlength="6" required></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?=e(t('Cancel'))?></button><button class="btn btn-primary"><?=e(t('Open account'))?></button></div>
</form></div></div>
<script>
document.getElementById('openModal')?.addEventListener('show.bs.modal', function (ev) {
  const btn = ev.relatedTarget;
  document.getElementById('mProduct').value = btn.dataset.product;
  document.getElementById('mName').textContent = btn.dataset.name;
  document.getElementById('mMin').textContent = '(min $' + btn.dataset.min + ')';
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
