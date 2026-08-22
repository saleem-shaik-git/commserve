<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/TransferService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user();
$db = Database::connection();
$transferService = new TransferService($db);
$accountService = new AccountService($db);

$ref = trim($_GET['ref'] ?? $_POST['reference'] ?? '');
$error = null;
$success = null;
$details = null;
$demoOtp = null;

if ($ref === '') redirect('/commserve/public/transfer.php');

try {
    $details = $transferService->getDetails($ref, (int)$user['id']);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = $_POST['action'] ?? 'confirm';
        if ($action === 'confirm') {
            $otp = $_POST['otp'] ?? '';
            $transferService->confirm($ref, (int)$user['id'], $otp);
            if (isset($_SESSION['demo_otps'][$ref])) unset($_SESSION['demo_otps'][$ref]);
            redirect('/commserve/public/transfer-receipt.php?ref=' . urlencode($ref));
        } elseif ($action === 'resend') {
            $demoOtp = $transferService->requestNewOtp($ref, (int)$user['id']);
            $_SESSION['demo_otps'][$ref] = ['otp'=>$demoOtp, 'expires'=>date('Y-m-d H:i:s', time()+600)];
            $success = 'New OTP generated. Demo OTP: ' . $demoOtp;
            $details = $transferService->getDetails($ref, (int)$user['id']);
        } elseif ($action === 'cancel') {
            $transferService->cancel($ref, (int)$user['id']);
            redirect('/commserve/public/transactions.php?msg=cancelled');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        try { $details = $transferService->getDetails($ref, (int)$user['id']); } catch (Throwable $ex) {}
    }
}

// For demo, show OTP from session if available (initiated just now)
$sessionOtp = null;
if ($details && $details['status'] === 'pending' && !$demoOtp) {
    if (!empty($_SESSION['demo_otps'][$ref]['otp'])) {
        $sessionOtp = $_SESSION['demo_otps'][$ref]['otp'];
        $sessionExpires = $_SESSION['demo_otps'][$ref]['expires'] ?? '';
    }
}

$pageTitle = 'Confirm Transfer';
$currentPage = 'transfer';
$totalBalance = $accountService->getTotalBalance((int)$user['id']);
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/commserve/public/transfer.php">Transfer</a></li><li class="breadcrumb-item active">Confirm</li></ol></nav>

    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= e($success) ?></div><?php endif; ?>

    <?php if (!$details): ?>
      <div class="alert alert-warning">Transfer not found.</div>
    <?php else: ?>
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <h5 class="fw-bold mb-0"><i class="bi bi-shield-lock me-2"></i>Confirm Transfer — OTP Verification</h5>
          <span class="badge <?= $details['status']==='pending'?'text-bg-warning':'text-bg-success' ?>"><?= e(strtoupper($details['status'])) ?></span>
        </div>
        <div class="card-body p-4">
          <div class="row g-4">
            <div class="col-md-6">
              <div class="small text-muted">Reference</div><div class="fw-bold font-monospace"><?= e($details['reference']) ?></div>
              <div class="small text-muted mt-3">From</div>
              <div class="fw-semibold"><?= e(($details['from_first']??'').' '.($details['from_last']??'')) ?> <span class="text-muted">· <?= e($details['from_type']??'') ?></span></div>
              <div class="font-monospace small"><?= e($details['from_account_number']??'') ?></div>
            </div>
            <div class="col-md-6">
              <div class="small text-muted">Amount</div><div class="display-6 fw-bold">₦<?= number_format((float)$details['amount'],2) ?></div>
              <div class="small text-muted mt-3">To</div>
              <div class="fw-semibold"><?= e(($details['to_first']??'').' '.($details['to_last']??'')) ?> <span class="text-muted">· <?= e($details['to_type']??'') ?></span></div>
              <div class="font-monospace small"><?= e($details['to_account_number']??'') ?></div>
            </div>
          </div>
          <hr>
          <div class="row small">
            <div class="col-md-6"><span class="text-muted">Description:</span> <?= e($details['description']) ?></div>
            <div class="col-md-3"><span class="text-muted">Currency:</span> <?= e($details['currency']) ?></div>
            <div class="col-md-3"><span class="text-muted">Initiated:</span> <?= e($details['created_at']) ?></div>
          </div>

          <?php if ($details['status'] === 'pending'): ?>
            <div class="alert alert-info mt-4">
              <h6 class="fw-bold"><i class="bi bi-phone me-2"></i>OTP Required</h6>
              <p class="small mb-2">Enter the 6-digit OTP to complete this transfer. In this demo, the OTP was displayed right after you initiated the transfer. If you missed it, click Resend OTP below to generate a new demo code.</p>
              <?php if ($details['otp_challenge']): ?>
                <div class="small">Expires: <?= e($details['otp_challenge']['expires_at']) ?> · Attempts: <?= e((string)$details['otp_challenge']['attempts']) ?>/5</div>
              <?php endif; ?>
            </div>

            <?php if ($sessionOtp): ?>
              <div class="alert alert-warning"><strong>Demo OTP (just generated):</strong> <span class="fs-4 fw-bold font-monospace"><?= e($sessionOtp) ?></span> — in production this would be sent via SMS/email. Expires <?= e($sessionExpires ?? '') ?></div>
            <?php endif; ?>
            <?php if ($demoOtp): ?>
              <div class="alert alert-warning"><strong>Demo OTP (resent):</strong> <span class="fs-4 fw-bold font-monospace"><?= e($demoOtp) ?></span> — in production this would be sent via SMS/email.</div>
            <?php endif; ?>

            <form method="post" class="mt-3">
              <?= csrf_field() ?>
              <input type="hidden" name="reference" value="<?= e($ref) ?>">
              <input type="hidden" name="action" value="confirm">
              <label class="form-label fw-semibold">Enter OTP</label>
              <input name="otp" class="form-control form-control-lg otp-input" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••" required autofocus>
              <button class="btn btn-primary btn-lg w-100 mt-3"><i class="bi bi-check-circle me-2"></i>Confirm Transfer — Complete Transaction</button>
            </form>

            <div class="d-flex gap-2 mt-3">
              <form method="post" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="reference" value="<?= e($ref) ?>"><input type="hidden" name="action" value="resend"><button class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-repeat me-1"></i>Resend OTP</button></form>
              <form method="post" class="flex-fill" onsubmit="return confirm('Cancel this transfer?')"><?= csrf_field() ?><input type="hidden" name="reference" value="<?= e($ref) ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger w-100"><i class="bi bi-x-circle me-1"></i>Cancel</button></form>
            </div>

          <?php else: ?>
            <div class="alert alert-success mt-4"><i class="bi bi-check-circle me-2"></i>Transfer already <?= e($details['status']) ?>. <a href="/commserve/public/transfer-receipt.php?ref=<?= urlencode($ref) ?>" class="alert-link">View receipt</a></div>
          <?php endif; ?>

          <?php if (!empty($details['events'])): ?>
            <hr><h6 class="fw-bold small text-muted text-uppercase">Timeline</h6>
            <?php foreach ($details['events'] as $ev): ?>
              <div class="d-flex gap-3 small mb-2"><span class="text-muted"><?= e($ev['created_at']) ?></span><span class="fw-semibold"><?= e($ev['event_type']) ?></span><span class="text-muted"><?= e(($ev['old_status']??'').' → '.($ev['new_status']??'')) ?></span></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 bg-light">
        <div class="card-body p-3 small text-muted"><i class="bi bi-info-circle me-2"></i>Transfers are ledger-backed. Once confirmed, debit and credit entries are created atomically and balances recalculated. Idempotency prevents double processing.</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
