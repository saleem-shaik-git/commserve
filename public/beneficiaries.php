<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/BeneficiaryService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user();
$db = Database::connection();
$svc = new BeneficiaryService($db);
$accountService = new AccountService($db);

$error = null;
$success = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $svc->create((int)$user['id'], $_POST['name'] ?? '', $_POST['account_number'] ?? '', $_POST['bank_name'] ?? '');
            $success = 'Beneficiary added successfully.';
        } elseif ($action === 'disable') {
            $svc->disable((int)$user['id'], (int)$_POST['id']);
            $success = 'Beneficiary disabled.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$items = $svc->list((int)$user['id']);
$totalBalance = $accountService->getTotalBalance((int)$user['id']);

$pageTitle = 'Beneficiaries';
$currentPage = 'beneficiaries';
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div><h3 class="fw-bold mb-1">Beneficiaries</h3><p class="text-muted mb-0">Manage saved accounts for quick transfers — verified against active CommServe accounts.</p></div>
  <a href="/commserve/public/transfer.php?type=beneficiary" class="btn btn-primary"><i class="bi bi-send me-2"></i>Send to Beneficiary</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-bold"><i class="bi bi-person-plus me-2"></i>Add Beneficiary</div>
      <div class="card-body p-4">
        <form method="post" class="row g-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add">
          <div class="col-12"><label class="form-label">Beneficiary Name</label><input name="name" class="form-control" placeholder="e.g. John Doe" required></div>
          <div class="col-12"><label class="form-label">Account Number (10 digits)</label><input name="account_number" class="form-control" inputmode="numeric" pattern="\d{10}" maxlength="10" placeholder="0100000001" required><div class="form-text">Must be an active CommServe account.</div></div>
          <div class="col-12"><label class="form-label">Bank Name</label><input name="bank_name" class="form-control" value="CommServe Demo Bank" required></div>
          <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-plus-circle me-2"></i>Add Beneficiary</button></div>
        </form>
        <hr><div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Beneficiaries are validated server-side. Only active accounts can be added. You can disable but not delete for audit.</div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center"><h6 class="fw-bold mb-0">Your Beneficiaries (<?= count($items) ?>)</h6><span class="badge text-bg-light border">Active: <?= count(array_filter($items, fn($b)=>$b['status']==='active')) ?></span></div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>Name</th><th>Account</th><th>Bank</th><th>Status</th><th>Added</th><th></th></tr></thead>
          <tbody>
          <?php if (!$items): ?><tr><td colspan="6" class="text-center text-muted py-5">No beneficiaries yet. Add one to enable quick transfers.</td></tr>
          <?php else: foreach ($items as $b): ?>
            <tr>
              <td><div class="fw-semibold"><?= e($b['name']) ?></div><div class="small text-muted">ID: <?= $b['id'] ?></div></td>
              <td class="font-monospace"><?= e($b['account_number']) ?></td>
              <td><?= e($b['bank_name']) ?></td>
              <td><span class="badge <?= $b['status']==='active'?'text-bg-success':'text-bg-secondary' ?>"><?= e($b['status']) ?></span></td>
              <td class="small text-muted"><?= e(date('M d, Y', strtotime($b['created_at']))) ?></td>
              <td>
                <?php if ($b['status']==='active'): ?>
                  <div class="d-flex gap-1">
                    <a href="/commserve/public/transfer.php?type=beneficiary&beneficiary=<?= $b['id'] ?>" class="btn btn-sm btn-primary">Send</a>
                    <form method="post" onsubmit="return confirm('Disable beneficiary?')"><?= csrf_field() ?><input type="hidden" name="action" value="disable"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn btn-sm btn-outline-danger">Disable</button></form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card border-0 bg-light mt-4">
      <div class="card-body p-3 small text-muted"><i class="bi bi-shield-check me-2"></i>Transfers to beneficiaries require Transaction PIN and OTP. All beneficiary creations are logged for audit.</div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
