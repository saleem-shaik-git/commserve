<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/BankingService.php';
require_auth();

$db = Database::connection();
$user = auth_user();
$service = new BankingService($db);
$message = null;
$error = null;

$stmt = $db->prepare('SELECT a.*, at.name AS type, at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.user_id=? AND a.status="active" ORDER BY a.id');
$stmt->execute([$user['id']]);
$accounts = $stmt->fetchAll();

$stmt = $db->prepare('SELECT b.* FROM beneficiaries b WHERE b.user_id=? ORDER BY b.name');
$stmt->execute([$user['id']]);
$beneficiaries = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $from = (int)($_POST['from_account'] ?? 0);
        $to = (int)($_POST['to_account'] ?? 0);
        $amount = trim((string)($_POST['amount'] ?? ''));
        $description = trim((string)($_POST['description'] ?? 'Internal transfer'));

        $stmt = $db->prepare('SELECT COUNT(*) FROM accounts WHERE id=? AND user_id=? AND status="active"');
        $stmt->execute([$from, $user['id']]);
        if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Invalid source account.');

        $reference = $service->transfer($from, $to, $amount, $description ?: 'Internal transfer');
        $message = 'Transfer completed successfully. Reference: ' . $reference;

        $stmt = $db->prepare('SELECT a.*, at.name AS type, at.currency FROM accounts a JOIN account_types at ON at.id=a.account_type_id WHERE a.user_id=? AND a.status="active" ORDER BY a.id');
        $stmt->execute([$user['id']]);
        $accounts = $stmt->fetchAll();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transfer - <?=e(APP_NAME)?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"><link href="/commserve/public/assets/css/app.css" rel="stylesheet"></head>
<body><nav class="navbar bg-white border-bottom"><div class="container-fluid"><a class="navbar-brand fw-bold" href="/commserve/public/dashboard.php">CommServe <span class="badge text-bg-warning">DEMO</span></a><a class="btn btn-outline-secondary btn-sm" href="/commserve/public/dashboard.php">Dashboard</a></div></nav>
<main class="container py-5" style="max-width:900px"><div class="mb-4"><h2 class="fw-bold">Transfer Money</h2><p class="text-muted mb-0">Move simulated funds between active CommServe accounts.</p></div>
<?php if ($message): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?=e($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?=e($error)?></div><?php endif; ?>
<div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="post"><?=csrf_field()?><div class="row g-3">
<div class="col-md-6"><label class="form-label">From account</label><select class="form-select" name="from_account" required><option value="">Select account</option><?php foreach($accounts as $a): ?><option value="<?=e((string)$a['id'])?>"><?=e($a['type'])?> · ****<?=e(substr($a['account_number'],-4))?> · ₦<?=number_format((float)$a['available_balance'],2)?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">To account</label><select class="form-select" name="to_account" required><option value="">Select destination</option><?php foreach($beneficiaries as $b): ?><option value="<?=e((string)($db->query("SELECT id FROM accounts WHERE account_number=".$db->quote($b['account_number']))->fetchColumn() ?: 0))?>"><?=e($b['name'])?> · <?=e($b['account_number'])?> · <?=e($b['bank_name'])?></option><?php endforeach; ?><?php $stmt=$db->prepare('SELECT a.id,u.first_name,u.last_name,a.account_number,at.name type FROM accounts a JOIN users u ON u.id=a.user_id JOIN account_types at ON at.id=a.account_type_id WHERE a.user_id<>? AND a.status="active" ORDER BY u.first_name');$stmt->execute([$user['id']]); foreach($stmt->fetchAll() as $a): ?><option value="<?=e((string)$a['id'])?>"><?=e($a['first_name'].' '.$a['last_name'])?> · <?=e($a['account_number'])?> · <?=e($a['type'])?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label class="form-label">Amount</label><div class="input-group"><span class="input-group-text">₦</span><input class="form-control" name="amount" inputmode="decimal" placeholder="0.00" required></div></div>
<div class="col-md-6"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="255" placeholder="e.g. Family transfer"></div>
<div class="col-12"><div class="alert alert-info small mb-0">Transfers are simulated. No real funds or external banking rails are used.</div></div>
<div class="col-12"><button class="btn btn-primary px-4"><i class="bi bi-send me-2"></i>Transfer funds</button></div>
</div></form></div></div></main></body></html>
