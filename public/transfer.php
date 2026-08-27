<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_once dirname(__DIR__) . '/app/Services/BeneficiaryService.php';
require_once dirname(__DIR__) . '/app/Services/TransferService.php';
require_auth();

$user = auth_user();
$db = Database::connection();
$accountService = new AccountService($db);
$beneficiaryService = new BeneficiaryService($db);
$transferService = new TransferService($db);

$accounts = $accountService->getAccounts((int)$user['id']);
$beneficiaries = $beneficiaryService->list((int)$user['id']);
$totalBalance = $accountService->getTotalBalance((int)$user['id']);

// Other CommServe accounts (excluding own)
$stmt = $db->prepare('SELECT a.id, a.account_number, at.name AS type_name, u.first_name, u.last_name FROM accounts a JOIN users u ON u.id=a.user_id JOIN account_types at ON at.id=a.account_type_id WHERE a.user_id<>? AND a.status="active" ORDER BY u.first_name LIMIT 100');
$stmt->execute([(int)$user['id']]);
$otherAccounts = $stmt->fetchAll();

$type = $_GET['type'] ?? 'own'; // own, beneficiary, other
$fromPreselect = (int)($_GET['from'] ?? 0);
$error = null;
$success = null;
$initResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $from = (int)($_POST['from_account'] ?? 0);
        $transferType = $_POST['transfer_type'] ?? 'own';
        $to = 0;
        $amount = trim((string)($_POST['amount'] ?? ''));
        $description = trim((string)($_POST['description'] ?? 'Transfer'));
        $pin = trim((string)($_POST['transaction_pin'] ?? ''));
        $idempotencyKey = trim((string)($_POST['idempotency_key'] ?? ''));

        if ($transferType === 'own' || $transferType === 'other') {
            $to = (int)($_POST['to_account'] ?? 0);
        } elseif ($transferType === 'beneficiary') {
            $beneficiaryId = (int)($_POST['beneficiary_id'] ?? 0);
            $b = $beneficiaryService->activeOwned((int)$user['id'], $beneficiaryId);
            // Resolve beneficiary account_number to account id
            $stmt = $db->prepare('SELECT id FROM accounts WHERE account_number=? AND status="active" LIMIT 1');
            $stmt->execute([$b['account_number']]);
            $to = (int)$stmt->fetchColumn();
            if (!$to) throw new RuntimeException('Beneficiary account not found or inactive.');
        }

        if ($from === 0 || $to === 0) throw new RuntimeException('Select valid source and destination.');
        if ($idempotencyKey === '') $idempotencyKey = 'WEB-' . bin2hex(random_bytes(12));

        $result = $transferService->initiate($from, $to, $amount, $description, (int)$user['id'], $pin, $idempotencyKey);
        // Store demo OTP in session for display on confirmation page (demo only)
        if (!isset($_SESSION['demo_otps'])) $_SESSION['demo_otps'] = [];
        if (!empty($result['otp']) && empty($result['is_existing'])) {
            $_SESSION['demo_otps'][$result['reference']] = ['otp'=>$result['otp'], 'expires'=>$result['expires_at'] ?? ''];
        }
        // Redirect to confirmation page
        redirect('/commserve/public/transfer-confirm.php?ref=' . urlencode($result['reference']));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Transfer';
$currentPage = 'transfer';
require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="fw-bold mb-1">Transfers</h3>
    <p class="text-muted mb-0">Move simulated funds — own accounts, other CommServe users, beneficiaries. PIN + OTP protected.</p>
  </div>
  <span class="badge text-bg-info"><i class="bi bi-shield-lock me-1"></i>Secure • Ledger-backed</span>
</div>

<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white">
        <ul class="nav nav-pills card-header-pills" id="transferTabs" role="tablist">
          <li class="nav-item"><button class="nav-link <?= $type==='own'?'active':'' ?>" data-bs-toggle="pill" data-bs-target="#own" type="button"><i class="bi bi-arrow-left-right me-1"></i>Own Accounts</button></li>
          <li class="nav-item"><button class="nav-link <?= $type==='beneficiary'?'active':'' ?>" data-bs-toggle="pill" data-bs-target="#beneficiary" type="button"><i class="bi bi-person-check me-1"></i>Beneficiary</button></li>
          <li class="nav-item"><button class="nav-link <?= $type==='other'?'active':'' ?>" data-bs-toggle="pill" data-bs-target="#other" type="button"><i class="bi bi-bank me-1"></i>Other CommServe</button></li>
        </ul>
      </div>
      <div class="card-body p-4">
        <div class="tab-content">
          <!-- OWN -->
          <div class="tab-pane fade <?= $type==='own'?'show active':'' ?>" id="own">
            <h5 class="fw-bold">Own-account Transfer</h5><p class="text-muted small">Move money between your Savings and Current accounts instantly.</p>
            <form method="post" class="mt-3">
              <?= csrf_field() ?>
              <input type="hidden" name="transfer_type" value="own">
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">From Account</label><select name="from_account" class="form-select" required><option value="">Select</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $fromPreselect===$a['id']?'selected':'' ?>><?= e($a['type_name']) ?> · <?= e($a['account_number']) ?> · $<?= number_format((float)$a['available_balance'],2) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">To Account (Own)</label><select name="to_account" class="form-select" required><option value="">Select</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['type_name']) ?> · <?= e($a['account_number']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Amount ($)</label><div class="input-group"><span class="input-group-text">$</span><input name="amount" class="form-control" inputmode="decimal" placeholder="0.00" required></div></div>
                <div class="col-md-6"><label class="form-label">Transaction PIN</label><input name="transaction_pin" type="password" class="form-control" inputmode="numeric" pattern="\d{4,6}" maxlength="6" placeholder="4-6 digits" required></div>
                <div class="col-12"><label class="form-label">Description</label><input name="description" class="form-control" maxlength="255" placeholder="e.g. Savings to Current"></div>
                <div class="col-12"><label class="form-label">Idempotency Key <span class="text-muted">(optional, auto-generated)</span></label><input name="idempotency_key" class="form-control" minlength="8" maxlength="100" placeholder="Unique key for safe retry"></div>
                <div class="col-12"><div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-2"></i>Transfer will be in <b>pending</b> status until you confirm with OTP on next page. PIN is verified now, OTP next.</div></div>
                <div class="col-12"><button class="btn btn-primary w-100 py-2"><i class="bi bi-shield-lock me-2"></i>Continue to OTP Confirmation</button></div>
              </div>
            </form>
          </div>

          <!-- BENEFICIARY -->
          <div class="tab-pane fade <?= $type==='beneficiary'?'show active':'' ?>" id="beneficiary">
            <h5 class="fw-bold">Beneficiary Transfer</h5><p class="text-muted small">Send to saved beneficiaries. Add new ones in Beneficiaries page.</p>
            <?php if (empty($beneficiaries)): ?><div class="alert alert-warning">No beneficiaries. <a href="/commserve/public/beneficiaries.php">Add one</a></div>
            <?php else: ?>
            <form method="post" class="mt-3">
              <?= csrf_field() ?>
              <input type="hidden" name="transfer_type" value="beneficiary">
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">From Account</label><select name="from_account" class="form-select" required><option value="">Select</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $fromPreselect===$a['id']?'selected':'' ?>><?= e($a['type_name']) ?> · <?= e($a['account_number']) ?> · $<?= number_format((float)$a['available_balance'],2) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Beneficiary</label><select name="beneficiary_id" class="form-select" required><option value="">Select beneficiary</option><?php foreach ($beneficiaries as $b): if($b['status']!=='active') continue; ?><option value="<?= $b['id'] ?>"><?= e($b['name']) ?> · <?= e($b['account_number']) ?> · <?= e($b['bank_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Amount</label><div class="input-group"><span class="input-group-text">$</span><input name="amount" class="form-control" required></div></div>
                <div class="col-md-6"><label class="form-label">Transaction PIN</label><input name="transaction_pin" type="password" class="form-control" pattern="\d{4,6}" maxlength="6" required></div>
                <div class="col-12"><label class="form-label">Description</label><input name="description" class="form-control" placeholder="e.g. Family support"></div>
                <div class="col-12"><button class="btn btn-primary w-100 py-2"><i class="bi bi-person-check me-2"></i>Send to Beneficiary — Confirm OTP</button></div>
              </div>
            </form>
            <?php endif; ?>
          </div>

          <!-- OTHER -->
          <div class="tab-pane fade <?= $type==='other'?'show active':'' ?>" id="other">
            <h5 class="fw-bold">Other CommServe Accounts</h5><p class="text-muted small">Transfer to any other active CommServe customer account.</p>
            <form method="post" class="mt-3">
              <?= csrf_field() ?>
              <input type="hidden" name="transfer_type" value="other">
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">From Account</label><select name="from_account" class="form-select" required><option value="">Select</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $fromPreselect===$a['id']?'selected':'' ?>><?= e($a['type_name']) ?> · <?= e($a['account_number']) ?> · $<?= number_format((float)$a['available_balance'],2) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Destination Account</label><select name="to_account" class="form-select" required><option value="">Select</option><?php foreach ($otherAccounts as $oa): ?><option value="<?= $oa['id'] ?>"><?= e($oa['first_name'].' '.$oa['last_name']) ?> · <?= e($oa['account_number']) ?> · <?= e($oa['type_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Amount</label><div class="input-group"><span class="input-group-text">$</span><input name="amount" class="form-control" required></div></div>
                <div class="col-md-6"><label class="form-label">Transaction PIN</label><input name="transaction_pin" type="password" class="form-control" pattern="\d{4,6}" maxlength="6" required></div>
                <div class="col-12"><label class="form-label">Description</label><input name="description" class="form-control" placeholder="e.g. Payment"></div>
                <div class="col-12"><button class="btn btn-primary w-100 py-2"><i class="bi bi-send me-2"></i>Transfer — Verify OTP</button></div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <h6 class="fw-bold"><i class="bi bi-shield-check me-2"></i>How transfers work</h6>
        <div class="timeline mt-3">
          <div class="timeline-item"><div class="timeline-dot"></div><strong>1. Initiate</strong><div class="small text-muted">Choose accounts, enter amount, verify Transaction PIN</div></div>
          <div class="timeline-item"><div class="timeline-dot"></div><strong>2. OTP</strong><div class="small text-muted">We generate a 6-digit OTP (demo shows on screen). Valid 10 min.</div></div>
          <div class="timeline-item"><div class="timeline-dot"></div><strong>3. Confirm</strong><div class="small text-muted">Enter OTP to complete. Ledger entries created atomically.</div></div>
          <div class="timeline-item"><div class="timeline-dot"></div><strong>4. Receipt</strong><div class="small text-muted">Get transfer receipt with reference. Download or share.</div></div>
        </div>
        <hr>
        <div class="small text-muted"><i class="bi bi-lightbulb me-1"></i><strong>Limits:</strong> Per-transaction and daily limits enforced. Idempotency keys prevent duplicate transfers on retry.</div>
      </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
      <div class="card-header bg-white fw-bold"><i class="bi bi-wallet2 me-2"></i>Your Balances</div>
      <div class="card-body">
        <?php foreach ($accounts as $a): ?>
          <div class="d-flex justify-content-between align-items-center mb-2"><span class="small"><?= e($a['type_name']) ?> ****<?= e(substr($a['account_number'],-4)) ?></span><strong class="small">$<?= number_format((float)$a['available_balance'],2) ?></strong></div>
        <?php endforeach; ?>
        <hr><div class="d-flex justify-content-between"><span class="fw-bold">Total</span><span class="fw-bold">$<?= number_format($totalBalance,2) ?></span></div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
