<?php
require_once dirname(__DIR__, 2) . '/app/helpers.php';
require_role('admin');
require_once dirname(__DIR__, 2) . '/app/Services/LoanService.php';
require_once dirname(__DIR__, 2) . '/app/Services/CreditService.php';
$db = Database::connection();
$svc = new LoanService($db);
$error = null; $success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $adminId = (int)auth_user()['id'];
        $action = $_POST['action'] ?? '';
        if ($action === 'approve') { $svc->decide((int)$_POST['id'], $adminId, true, (string)($_POST['reason'] ?? '')); $success = t('Application approved. Disburse it below.'); }
        elseif ($action === 'reject') { $svc->decide((int)$_POST['id'], $adminId, false, (string)($_POST['reason'] ?? '')); $success = t('Application rejected.'); }
        elseif ($action === 'disburse') { $row = $svc->disburse((int)$_POST['id'], $adminId); $success = t('Loan disbursed (%s). Monthly payment $%s, first due %s.', null, [$row['reference'], number_format((float)$row['monthly_payment'], 2), $row['next_due']]); }
        elseif ($action === 'late') { $r = $svc->processLate(); $success = t('Late processing done (%s loans defaulted).', null, [(string)$r['defaulted']]); }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$apps = $svc->pendingApplications();
$loans = $svc->portfolio();
$pageTitle = t('Loans');
$adminCurrent = 'loans';
require __DIR__ . '/partials/header.php';
?>
<main class="container-fluid p-3 p-lg-4">
  <?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?=e($success)?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="fw-bold mb-0"><i class="bi bi-cash-stack me-2"></i><?=e(t('Lending'))?></h2>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="late"><button class="btn btn-outline-primary btn-sm"><?=e(t('Process late installments'))?></button></form>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-bold"><?=e(t('Applications'))?></div>
    <div class="table-responsive"><table class="table align-middle mb-0 small">
      <thead class="table-light"><tr><th>#</th><th><?=e(t('Customer'))?></th><th><?=e(t('Product'))?></th><th><?=e(t('Amount'))?></th><th><?=e(t('Tenor'))?></th><th><?=e(t('Score'))?></th><th><?=e(t('Status'))?></th><th><?=e(t('Decision'))?></th></tr></thead>
      <tbody>
        <?php if (!$apps): ?><tr><td colspan="8" class="text-center text-muted py-3"><?=e(t('No applications awaiting action.'))?></td></tr><?php endif; ?>
        <?php foreach ($apps as $a): ?>
        <tr>
          <td>#<?=$a['id']?></td><td><?=e($a['email'])?></td><td><?=e($a['product_name'])?> · <?=e($a['annual_rate'])?>%</td>
          <td class="fw-bold"><?=format_money((float)$a['amount'])?></td><td><?=(int)$a['tenor_months']?> <?=e(t('mo'))?></td>
          <td><span class="badge text-bg-<?=CreditService::bandFor((int)$a['credit_score'])==='Excellent'||CreditService::bandFor((int)$a['credit_score'])==='Good'?'success':(CreditService::bandFor((int)$a['credit_score'])==='Fair'?'info':'danger')?>"><?=(int)$a['credit_score']?> · <?=e(t(CreditService::bandFor((int)$a['credit_score'])))?></span></td>
          <td><span class="badge <?=$a['status']==='approved'?'text-bg-primary':($a['status']==='under_review'?'text-bg-warning':'text-bg-secondary')?>"><?=e(tx_label($a['status']))?></span></td>
          <td>
            <?php if (in_array($a['status'], ['pending','under_review'], true)): ?>
            <form method="post" class="d-flex gap-1 flex-wrap"><?=csrf_field()?>
              <input type="hidden" name="id" value="<?=$a['id']?>">
              <input name="reason" class="form-control form-control-sm" style="width:180px" placeholder="<?=e(t('Reason'))?>" required>
              <button name="action" value="approve" class="btn btn-sm btn-success"><?=e(t('Approve'))?></button>
              <button name="action" value="reject" class="btn btn-sm btn-outline-danger"><?=e(t('Reject'))?></button>
            </form>
            <?php elseif ($a['status'] === 'approved'): ?>
            <form method="post" onsubmit="return confirm('<?=e(t('Disburse this loan to the customer account?'))?>')"><?=csrf_field()?>
              <input type="hidden" name="id" value="<?=$a['id']?>">
              <button name="action" value="disburse" class="btn btn-sm btn-primary"><?=e(t('Disburse'))?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-bold"><?=e(t('Loan portfolio'))?></div>
    <div class="table-responsive"><table class="table align-middle mb-0 small">
      <thead class="table-light"><tr><th><?=e(t('Reference'))?></th><th><?=e(t('Customer'))?></th><th><?=e(t('Product'))?></th><th><?=e(t('Principal'))?></th><th><?=e(t('Outstanding'))?></th><th><?=e(t('Monthly'))?></th><th><?=e(t('Next due'))?></th><th><?=e(t('Late'))?></th><th><?=e(t('Status'))?></th></tr></thead>
      <tbody>
        <?php if (!$loans): ?><tr><td colspan="9" class="text-center text-muted py-3"><?=e(t('No loans yet.'))?></td></tr><?php endif; ?>
        <?php foreach ($loans as $l): ?><tr class="<?=$l['status']==='defaulted'?'table-danger':''?>">
          <td class="font-monospace"><?=e($l['reference'])?></td><td><?=e($l['email'])?></td><td><?=e($l['product_name'])?></td>
          <td><?=format_money((float)$l['principal'])?></td><td class="fw-bold"><?=format_money((float)$l['outstanding_principal'])?></td>
          <td><?=format_money((float)$l['monthly_payment'])?></td><td><?=e($l['next_due_date'] ?: '—')?></td><td><?=(int)$l['late_count']?></td>
          <td><span class="badge <?=$l['status']==='active'?'text-bg-primary':($l['status']==='completed'?'text-bg-success':'text-bg-danger')?>"><?=e(tx_label($l['status']))?></span></td>
        </tr><?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
