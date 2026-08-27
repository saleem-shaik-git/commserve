<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/FixedDepositService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user(); $db = Database::connection();
$svc = new FixedDepositService($db); $accountService = new AccountService($db);
$uid = (int)$user['id'];
$error = null; $success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $row = $svc->create($uid, (int)($_POST['product_id'] ?? 0), (int)($_POST['from_account'] ?? 0), trim((string)($_POST['amount'] ?? '')), trim((string)($_POST['pin'] ?? '')));
            $success = t('Fixed deposit placed: %s for %s days at %s%% — matures %s with a value of $%s.', null, [number_format((float)$row['principal'],2), (string)$row['term_days'], number_format((float)$row['interest'],2), $row['maturity_date'], number_format((float)$row['maturity_value'],2)]);
        } elseif ($action === 'withdraw') {
            $row = $svc->withdraw($uid, (int)($_POST['id'] ?? 0), trim((string)($_POST['pin'] ?? '')));
            $success = $row['early']
                ? t('Early withdrawal processed: payout $%s (interest $%s, penalty $%s).', null, [number_format((float)$row['payout'],2), number_format((float)$row['interest'],2), number_format((float)$row['penalty'],2)])
                : t('Matured deposit paid out: $%s.', null, [number_format((float)$row['payout'],2)]);
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$products = $svc->termProducts();
$deposits = $svc->listForUser($uid);
$accounts = $accountService->getAccounts($uid);
$totalBalance = $accountService->getTotalBalance($uid);
$pageTitle = t('Fixed Deposits'); $currentPage = 'savings';
require __DIR__ . '/partials/header.php'; require __DIR__ . '/partials/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div><h3 class="fw-bold mb-1"><?=e(t('Fixed Deposits'))?></h3><p class="text-muted mb-0"><?=e(t('Lock funds for a fixed term at a fixed rate — principal and interest are paid to your account at maturity.'))?></p></div>
  <a href="<?=url('savings.php')?>" class="btn btn-outline-primary"><?=e(t('Savings'))?></a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?=e($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?=e($success)?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm"><div class="card-body p-4">
      <h5 class="fw-bold"><i class="bi bi-lock me-2"></i><?=e(t('Place a fixed deposit'))?></h5>
      <form method="post" class="mt-3"><?=csrf_field()?>
        <input type="hidden" name="action" value="create">
        <div class="mb-3"><label class="form-label"><?=e(t('Product'))?></label><select name="product_id" class="form-select" required><?php foreach ($products as $p): ?><option value="<?=$p['id']?>"><?=e($p['name'])?> — <?=e($p['interest_rate'])?>% · <?=e((int)$p['default_term_days'])?> <?=e(t('days'))?> · <?=e(t('min'))?> <?=format_money((float)$p['min_opening_balance'])?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label"><?=e(t('From Account'))?></label><select name="from_account" class="form-select" required><option value=""><?=e(t('Select account'))?></option><?php foreach ($accounts as $a): ?><option value="<?=$a['id']?>"><?=e($a['type_name'])?> · <?=e($a['account_number'])?> · $<?=number_format((float)$a['available_balance'],2)?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label"><?=e(t('Amount (USD)'))?></label><div class="input-group"><span class="input-group-text">$</span><input name="amount" class="form-control" inputmode="decimal" required></div></div>
        <div class="mb-3"><label class="form-label"><?=e(t('Transaction PIN'))?></label><input name="pin" type="password" class="form-control" inputmode="numeric" pattern="\d{4,6}" maxlength="6" required></div>
        <div class="alert alert-info small mb-3"><?=e(t('Early withdrawal forfeits part of the accrued interest (penalty shown per product).'))?></div>
        <button class="btn btn-primary w-100"><?=e(t('Place deposit'))?></button>
      </form>
    </div></div>
  </div>

  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-bold"><?=e(t('My deposits'))?></div>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th><?=e(t('Reference'))?></th><th><?=e(t('Principal'))?></th><th><?=e(t('Rate / Term'))?></th><th><?=e(t('Maturity'))?></th><th><?=e(t('Maturity value'))?></th><th><?=e(t('Status'))?></th><th></th></tr></thead>
        <tbody>
          <?php if (!$deposits): ?><tr><td colspan="7" class="text-center text-muted py-4"><?=e(t('No fixed deposits yet.'))?></td></tr><?php endif; ?>
          <?php foreach ($deposits as $d): $withdrawable = in_array($d['status'], ['active','matured'], true); ?>
          <tr>
            <td class="font-monospace small"><?=e($d['reference'])?></td>
            <td class="fw-bold"><?=format_money((float)$d['principal'])?></td>
            <td class="small"><?=e($d['annual_rate'])?>% · <?=e((int)$d['term_days'])?><?=e(t('d'))?></td>
            <td class="small"><?=e(format_date($d['maturity_date']))?></td>
            <td><?=format_money((float)$d['maturity_value'])?></td>
            <td><span class="badge <?=$d['status']==='active'?'text-bg-primary':($d['status']==='matured'?'text-bg-success':'text-bg-secondary')?>"><?=e(tx_label($d['status']))?></span></td>
            <td>
              <?php if ($withdrawable): ?>
              <form method="post" class="d-flex gap-1 align-items-center" onsubmit="return confirm('<?=e($d['status']==='matured'?t('Pay out this matured deposit?'):t('Withdraw early and pay the penalty?'))?>')"><?=csrf_field()?>
                <input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?=$d['id']?>">
                <input name="pin" type="password" class="form-control form-control-sm" style="width:90px" placeholder="<?=e(t('PIN'))?>" pattern="\d{4,6}" maxlength="6" required>
                <button class="btn btn-sm btn-<?=$d['status']==='matured'?'success':'outline-warning'?>"><?=e($d['status']==='matured'?t('Withdraw'):t('Withdraw early'))?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
