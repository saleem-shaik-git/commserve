<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/NotifyingTransferService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user(); $db = Database::connection();
$transferService = new NotifyingTransferService($db); $accountService = new AccountService($db);
$ref = trim($_GET['ref'] ?? $_POST['reference'] ?? ''); $error=null; $success=null; $details=null; $demoOtp=null; $stageDone=null;
if($ref==='') redirect(url('transfer.php'));
try{$details=$transferService->getDetails($ref,(int)$user['id']);}catch(Throwable $e){$error=$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    verify_csrf();$action=$_POST['action']??'confirm';
    if($action==='confirm'){
      $otp=$_POST['otp']??'';
      $result=$transferService->confirm($ref,(int)$user['id'],$otp);
      if(!empty($result['completed'])){
        if(isset($_SESSION['demo_otps'][$ref]))unset($_SESSION['demo_otps'][$ref]);
        $success='All 4 verification stages completed. Your transfer has been submitted for admin approval.';
      }else{
        $_SESSION['demo_otps'][$ref]=['otp'=>$result['otp'],'expires'=>$result['expires_at']??date('Y-m-d H:i:s',time()+600)];
        $stageDone=$result;$demoOtp=$result['otp'];
        $success='Stage '.$result['stage'].' of 4 verified ('.TransferService::stageLabel((int)$result['stage']).'). Next: stage '.$result['next_stage'].' — '.$result['next_label'].'.';
      }
    }elseif($action==='resend'){
      $demoOtp=$transferService->requestNewOtp($ref,(int)$user['id']);
      $_SESSION['demo_otps'][$ref]=['otp'=>$demoOtp,'expires'=>date('Y-m-d H:i:s',time()+600)];
      $success='New OTP generated for the current stage. Demo OTP: '.$demoOtp;
    }elseif($action==='cancel'){
      $transferService->cancel($ref,(int)$user['id']);
      redirect(url('transactions.php?msg=cancelled'));
    }
  }catch(Throwable $e){
    $error=$e->getMessage();
  }
  try{$details=$transferService->getDetails($ref,(int)$user['id']);}catch(Throwable $ex){}
}
$sessionOtp=null;$sessionExpires='';
if($details&&($details['status']??'')==='pending'&&!$demoOtp&&isset($_SESSION['demo_otps'][$ref]['otp'])){$sessionOtp=$_SESSION['demo_otps'][$ref]['otp'];$sessionExpires=$_SESSION['demo_otps'][$ref]['expires']??'';}
$currentStage=$details?(int)($details['otp_stage']??1):1;
$totalStages=(int)($details['otp_stages_total']??TransferService::OTP_STAGES);
$status=$details?($details['status']??''):'';
$pageTitle='Confirm Transfer';$currentPage='transfer';$totalBalance=$accountService->getTotalBalance((int)$user['id']);
require __DIR__.'/partials/header.php';require __DIR__.'/partials/sidebar.php';
?>
<div class="row justify-content-center"><div class="col-lg-9"><nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?=url('transfer.php')?>">Transfer</a></li><li class="breadcrumb-item active">Verification</li></ol></nav>
<?php if($error):?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?=e($error)?></div><?php endif;?>
<?php if($success):?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?=e($success)?></div><?php endif;?>
<?php if(!$details):?><div class="alert alert-warning">Transfer not found.</div>
<?php else:?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <h5 class="fw-bold mb-0"><i class="bi bi-shield-lock me-2"></i>Transfer Verification</h5>
    <span class="badge <?=$status==='pending'?'text-bg-warning':($status==='awaiting_approval'?'text-bg-info':($status==='completed'?'text-bg-success':'text-bg-secondary'))?>"><?=e(strtoupper($status))?></span>
  </div>
  <div class="card-body p-4">
    <!-- 4-stage stepper -->
    <div class="d-flex justify-content-between mb-4 flex-wrap gap-2">
      <?php foreach([1=>'Identity','Amount','Beneficiary','Final'] as $n=>$label):
        $done=$n<$currentStage;
        $active=$status==='pending'&&$n===$currentStage;
      ?>
      <div class="text-center flex-fill step-chip <?= $done?'step-done':'' ?> <?= $active?'step-active':'' ?>">
        <div class="step-dot mx-auto"><i class="bi <?= $done?'bi-check-circle-fill':'bi-'.$n?>-circle'.($active?'-fill':'') ?>"></i></div>
        <div class="small fw-semibold mt-1">Stage <?= $n ?></div>
        <div class="small text-muted"><?= $label ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-4">
      <div class="col-md-6">
        <div class="small text-muted">Reference</div>
        <div class="fw-bold font-monospace"><?=e($details['reference'])?></div>
        <div class="small text-muted mt-3">From</div>
        <div class="fw-semibold"><?=e(($details['from_first']??'').' '.($details['from_last']??''))?> <span class="text-muted">· <?=e($details['from_type']??'')?></span></div>
        <div class="font-monospace small"><?=e($details['from_account_number']??'')?></div>
      </div>
      <div class="col-md-6">
        <div class="small text-muted">Amount</div>
        <div class="display-6 fw-bold"><?=format_money((float)$details['amount'],(string)$details['currency'])?></div>
        <div class="small text-muted mt-3">To</div>
        <div class="fw-semibold"><?=e(($details['to_first']??'').' '.($details['to_last']??''))?> <span class="text-muted">· <?=e($details['to_type']??'')?></span></div>
        <div class="font-monospace small"><?=e($details['to_account_number']??'')?></div>
      </div>
    </div>
    <hr>
    <div class="row small">
      <div class="col-md-6"><span class="text-muted">Description:</span> <?=e($details['description'])?></div>
      <div class="col-md-3"><span class="text-muted">Currency:</span> <?=e($details['currency'])?></div>
      <div class="col-md-3"><span class="text-muted">Initiated:</span> <?=e($details['created_at'])?></div>
    </div>

    <?php if($status==='pending'):?>
      <div class="alert alert-primary mt-4">
        <h6 class="fw-bold mb-1"><i class="bi bi-phone me-1"></i>Stage <?=$currentStage?> of <?=$totalStages?> — <?=e(TransferService::stageLabel($currentStage))?></h6>
        <p class="small mb-2">Enter the 6-digit OTP to pass this stage. Each stage issues a fresh OTP.</p>
        <?php if($details['otp_challenge']):?><div class="small">Expires: <?=e($details['otp_challenge']['expires_at'])?> · Attempts: <?=e((string)$details['otp_challenge']['attempts'])?>/5</div><?php endif;?>
      </div>
      <?php if($sessionOtp):?><div class="alert alert-warning"><strong>Demo OTP (stage <?=$currentStage?>):</strong> <span class="fs-4 fw-bold font-monospace"><?=e($sessionOtp)?></span> — expires <?=e($sessionExpires)?></div><?php endif;?>
      <?php if($demoOtp && !$sessionOtp):?><div class="alert alert-warning"><strong>Demo OTP:</strong> <span class="fs-4 fw-bold font-monospace"><?=e($demoOtp)?></span></div><?php endif;?>
      <form method="post" class="mt-3"><?=csrf_field()?>
        <input type="hidden" name="reference" value="<?=e($ref)?>"><input type="hidden" name="action" value="confirm">
        <label class="form-label fw-semibold">Enter OTP — <?=e(TransferService::stageLabel($currentStage))?></label>
        <input name="otp" class="form-control form-control-lg otp-input" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••" required autofocus>
        <button class="btn btn-primary btn-lg w-100 mt-3"><i class="bi bi-check-circle me-1"></i>Verify Stage <?=$currentStage?> of <?=$totalStages?></button>
      </form>
      <div class="d-flex gap-2 mt-3">
        <form method="post" class="flex-fill"><?=csrf_field()?><input type="hidden" name="reference" value="<?=e($ref)?>"><input type="hidden" name="action" value="resend"><button class="btn btn-outline-secondary w-100">Resend OTP</button></form>
        <form method="post" class="flex-fill" onsubmit="return confirm('Cancel this transfer?')"><?=csrf_field()?><input type="hidden" name="reference" value="<?=e($ref)?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger w-100">Cancel</button></form>
      </div>
    <?php elseif($status==='awaiting_approval'):?>
      <div class="alert alert-info mt-4">
        <h6 class="fw-bold mb-1"><i class="bi bi-person-check me-1"></i>Awaiting admin approval</h6>
        <p class="small mb-2">All 4 verification stages passed. An administrator must release this transfer before the funds move. You will be notified when it is approved or rejected.</p>
        <div class="small">You can track the status any time from your <a href="<?=url('transactions.php')?>">transaction history</a>.</div>
      </div>
      <a href="<?=url('transactions.php')?>" class="btn btn-primary"><i class="bi bi-receipt me-1"></i>View transaction status</a>
      <a href="<?=url('transfer.php')?>" class="btn btn-outline-primary">New transfer</a>
    <?php elseif($status==='completed'):?>
      <div class="alert alert-success mt-4">Transfer completed. <a href="<?=url('transfer-receipt.php')?>?ref=<?=urlencode($ref)?>" class="alert-link">View receipt</a></div>
    <?php else:?>
      <div class="alert alert-secondary mt-4">Transfer status: <?=e(strtoupper($status))?>. <a href="<?=url('transactions.php')?>" class="alert-link">View details</a></div>
    <?php endif;?>
  </div>
</div>
<?php endif;?>
</div>
<style>
.step-chip{opacity:.45}
.step-chip.step-active{opacity:1}
.step-chip.step-done{opacity:.9}
.step-dot{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:#e9ecef;color:#6c757d;font-size:18px}
.step-active .step-dot{background:#0d6efd;color:#fff;box-shadow:0 0 0 4px rgba(13,110,253,.15)}
.step-done .step-dot{background:#198754;color:#fff}
</style>
<?php require __DIR__.'/partials/footer.php'; ?>
