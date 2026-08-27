<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/SecurityService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user();
$db = Database::connection();
$svc = new SecurityService($db);
$accountService = new AccountService($db);

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $pin = (string)($_POST['pin'] ?? '');
        $confirm = (string)($_POST['confirm_pin'] ?? '');
        if ($pin !== $confirm) throw new RuntimeException(t('PIN confirmation does not match.'));
        $svc->setTransactionPin((int)$user['id'], $pin);
        $success = t('Transaction PIN saved successfully. You can now authorize transfers.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $db->prepare('SELECT transaction_pin_hash FROM users WHERE id=?');
$stmt->execute([(int)$user['id']]);
$hasPin = (bool)$stmt->fetchColumn();

$totalBalance = $accountService->getTotalBalance((int)$user['id']);
$pageTitle = t('Transaction PIN');
$currentPage = 'pin';
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div><h3 class="fw-bold mb-1"><i class="bi bi-shield-lock me-2"></i><?=e(t('Transaction PIN'))?></h3><p class="text-muted mb-0"><?=e(t('Secure your transfers with a 4-6 digit PIN + OTP.'))?></p></div>
      <span class="badge <?= $hasPin?'text-bg-success':'text-bg-warning' ?>"><?= $hasPin?e(t('PIN SET')):e(t('NOT SET')) ?></span>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="row g-4">
      <div class="col-md-6">
        <div class="card border-0 shadow-sm">
          <div class="card-body p-4">
            <h5 class="fw-bold"><?=e(t('Set / Update PIN'))?></h5><p class="text-muted small"><?=e(t('Used to authorize all transfers. Keep it secret, never share OTP.'))?></p>
            <form method="post" class="mt-3">
              <?= csrf_field() ?>
              <div class="mb-3"><label class="form-label"><?=e(t('New PIN (4-6 digits)'))?></label><input class="form-control form-control-lg" name="pin" type="password" inputmode="numeric" pattern="\d{4,6}" maxlength="6" minlength="4" placeholder="••••" required></div>
              <div class="mb-3"><label class="form-label"><?=e(t('Confirm PIN'))?></label><input class="form-control form-control-lg" name="confirm_pin" type="password" inputmode="numeric" pattern="\d{4,6}" maxlength="6" minlength="4" placeholder="••••" required></div>
              <button class="btn btn-primary w-100 py-2"><i class="bi bi-shield-check me-2"></i><?=e(t('Save PIN'))?></button>
            </form>
            <hr><div class="small text-muted"><i class="bi bi-info-circle me-1"></i><?=e(t('PIN is hashed with bcrypt. OTP is additional layer — 6 digits, 10 min expiry, 5 attempts max.'))?></div>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card border-0 shadow-sm mb-3">
          <div class="card-header bg-white fw-bold"><i class="bi bi-shield-check me-2"></i><?=e(t('Security Status'))?></div>
          <div class="card-body">
            <div class="d-flex justify-content-between mb-3"><span><?=e(t('Transaction PIN'))?></span><span class="badge <?= $hasPin?'text-bg-success':'text-bg-danger' ?>"><?= $hasPin?e(t('Active')):e(t('Not Set')) ?></span></div>
            <div class="d-flex justify-content-between mb-3"><span><?=e(t('OTP Verification'))?></span><span class="badge text-bg-success"><?=e(t('Enabled'))?></span></div>
            <div class="d-flex justify-content-between mb-3"><span><?=e(t('Ledger Protection'))?></span><span class="badge text-bg-success"><?=e(t('Active'))?></span></div>
            <div class="d-flex justify-content-between"><span><?=e(t('Session Security'))?></span><span class="badge text-bg-success">HttpOnly • Lax</span></div>
          </div>
        </div>

        <div class="card border-0 bg-primary text-white">
          <div class="card-body p-4">
            <h6 class="fw-bold"><i class="bi bi-lightbulb me-2"></i><?=e(t('Tips'))?></h6>
            <ul class="small mb-0">
              <li><?=e(t('Choose a PIN you can remember but others can\'t guess'))?></li>
              <li><?=e(t('Never use 1234, 0000, or your birth year'))?></li>
              <li><?=e(t('OTP expires in 10 minutes — request new if expired'))?></li>
              <li><?=e(t('All PIN changes are audited'))?></li>
            </ul>
          </div>
        </div>

        <div class="card border-0 shadow-sm mt-3">
          <div class="card-body p-3 text-center">
            <div class="small text-muted mb-2"><?=e(t('Need help?'))?></div>
            <a href="<?=url('transfer.php')?>" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-send me-1"></i><?=e(t('Test Transfer Flow'))?></a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
