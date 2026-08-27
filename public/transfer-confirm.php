<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/NotifyingTransferService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user(); $db = Database::connection();
$transferService = new NotifyingTransferService($db); $accountService = new AccountService($db);
$ref = trim($_GET['ref'] ?? $_POST['reference'] ?? ''); $error=null; $success=null; $details=null;
if($ref==='') redirect(url('transfer.php'));
try{$details=$transferService->getDetails($ref,(int)$user['id']);}catch(Throwable $e){$error=$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    verify_csrf();$action=$_POST['action']??'confirm';
    if($action==='confirm'){
      $otp=$_POST['otp']??'';
      $result=$transferService->confirm($ref,(int)$user['id'],$otp);
      if(!empty($result['submitted_for_approval'])){
        $success=(int)$result['stage']>=TransferService::OTP_STAGES
            ? t('Stage %s of %s verified (%s). Submitted for admin approval — the funds move once an administrator approves it.',null,[$result['stage'],TransferService::OTP_STAGES,$result['stage_label']])
            : t('Stage %s of %s verified (%s). Submitted for admin approval — stage %s of 4 unlocks when an administrator approves it.',null,[$result['stage'],TransferService::OTP_STAGES,$result['stage_label'],(int)$result['stage']+1]);
      }else{
        $success=t('All 4 verification stages completed. Your transfer has been submitted for admin approval.');
      }
    }elseif($action==='resend'){
      $otp=$transferService->requestNewOtp($ref,(int)$user['id']);
      $success=t('A new one-time password has been generated for the current stage.');
    }elseif($action==='cancel'){
      $transferService->cancel($ref,(int)$user['id']);
      redirect(url('transactions.php?msg=cancelled'));
    }
  }catch(Throwable $e){
    $error=$e->getMessage();
  }
  try{$details=$transferService->getDetails($ref,(int)$user['id']);}catch(Throwable $ex){}
}
// Show the persisted OTP display copy while the stage is unresolved.
$displayOtp=(!empty($details['otp_challenge']['otp_code'])&&empty($details['otp_stage_submitted'])&&($details['status']??'')==='pending')?$details['otp_challenge']['otp_code']:null;
$currentStage=$details?(int)($details['otp_stage']??1):1;
$approvedCount=$details?(int)($details['otp_stage_approved_count']??0):0;
$stageSubmitted=$details&&!empty($details['otp_stage_submitted']);
$totalStages=(int)($details['otp_stages_total']??TransferService::OTP_STAGES);
$status=$details?($details['status']??''):'';
$pageTitle=t('Confirm Transfer');$currentPage='transfer';$totalBalance=$accountService->getTotalBalance((int)$user['id']);
require __DIR__.'/partials/header.php';require __DIR__.'/partials/sidebar.php';
?>
<div class="row justify-content-center"><div class="col-lg-9"><nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?=url('transfer.php')?>"><?=e(t('Transfer'))?></a></li><li class="breadcrumb-item active"><?=e(t('Verification'))?></li></ol></nav>
<?php if($error):?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?=e($error)?></div><?php endif;?>
<?php if($success):?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?=e($success)?></div><?php endif;?>
<?php if(!$details):?><div class="alert alert-warning"><?=e(t('Transfer not found.'))?></div>
<?php else:?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <h5 class="fw-bold mb-0"><i class="bi bi-shield-lock me-2"></i><?=e(t('Transfer Verification'))?></h5>
    <span class="badge <?=$status==='pending'?'text-bg-warning':($status==='awaiting_approval'?'text-bg-info':($status==='completed'?'text-bg-success':'text-bg-secondary'))?>"><?=e(tx_label($status))?></span>
  </div>
  <div class="card-body p-4">
    <!-- 4-stage stepper -->
    <div class="d-flex justify-content-between mb-4 flex-wrap gap-2">
      <?php foreach([1=>t('COT'),2=>t('IMF'),3=>t('Tax Code'),4=>t('Final')] as $n=>$label):
        $done=$n<=$approvedCount;
        $submittedNow=$stageSubmitted&&$n===$currentStage;
        $active=$status==='pending'&&$n===$currentStage&&!$stageSubmitted;
      ?>
      <div class="text-center flex-fill step-chip <?= $done?'step-done':'' ?> <?= $active?'step-active':'' ?>">
        <div class="step-dot mx-auto"><i class="bi <?= $done?'bi-check-circle-fill':($submittedNow?'bi-hourglass-split':'bi-'.$n.'-circle'.($active?'-fill':'')) ?>"></i></div>
        <div class="small fw-semibold mt-1"><?=e(t('Stage'))?> <?= $n ?></div>
        <div class="small text-muted"><?=e($label)?></div>
        <?php if($submittedNow):?><div class="badge text-bg-info mt-1"><?=e(t('Awaiting approval'))?></div><?php endif;?>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-4">
      <div class="col-md-6">
        <div class="small text-muted"><?=e(t('Reference'))?></div>
        <div class="fw-bold font-monospace"><?=e($details['reference'])?></div>
        <div class="small text-muted mt-3"><?=e(t('From'))?></div>
        <div class="fw-semibold"><?=e(($details['from_first']??'').' '.($details['from_last']??''))?> <span class="text-muted">· <?=e($details['from_type']??'')?></span></div>
        <div class="font-monospace small"><?=e($details['from_account_number']??'')?></div>
      </div>
      <div class="col-md-6">
        <div class="small text-muted"><?=e(t('Amount'))?></div>
        <div class="display-6 fw-bold"><?=format_money((float)$details['amount'],(string)$details['currency'])?></div>
        <div class="small text-muted mt-3"><?=e(t('To'))?></div>
        <div class="fw-semibold"><?=e(($details['to_first']??'').' '.($details['to_last']??''))?> <span class="text-muted">· <?=e($details['to_type']??'')?></span></div>
        <div class="font-monospace small"><?=e($details['to_account_number']??'')?></div>
      </div>
    </div>
    <hr>
    <div class="row small">
      <div class="col-md-6"><span class="text-muted"><?=e(t('Description'))?>:</span> <?=e($details['description'])?></div>
      <div class="col-md-3"><span class="text-muted"><?=e(t('Currency'))?>:</span> <?=e($details['currency'])?></div>
      <div class="col-md-3"><span class="text-muted"><?=e(t('Initiated'))?>:</span> <?=e($details['created_at'])?></div>
    </div>

    <?php if($status==='pending' && $stageSubmitted):?>
      <div class="alert alert-info mt-4">
        <h6 class="fw-bold mb-1"><i class="bi bi-hourglass-split me-1"></i><?=e(t('Stage %s of %s',null,[$currentStage,$totalStages]))?> — <?=e(t($details['otp_stage_label']))?>: <?=e(t('Awaiting admin approval'))?></h6>
        <p class="small mb-2"><?=e(t('Your one-time password was verified. An administrator must approve this stage before the next one unlocks. You will be notified once it is approved.'))?></p>
        <div class="small"><?=e(t('Track the status any time from your transaction history.'))?> <a href="<?=url('transactions.php')?>"><?=e(t('Transaction history'))?></a></div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <a href="<?=url('transactions.php')?>" class="btn btn-primary"><i class="bi bi-receipt me-1"></i><?=e(t('View transaction status'))?></a>
        <form method="post" class="flex-fill" onsubmit="return confirm('<?=e(t('Cancel this transfer?'))?>')"><?=csrf_field()?><input type="hidden" name="reference" value="<?=e($ref)?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger w-100"><?=e(t('Cancel'))?></button></form>
      </div>
    <?php elseif($status==='pending'):?>
      <div class="alert alert-primary mt-4">
        <h6 class="fw-bold mb-1"><i class="bi bi-phone me-1"></i><?=e(t('Stage %s of %s',null,[$currentStage,$totalStages]))?> — <?=e(t(TransferService::stageLabel($currentStage)))?></h6>
        <p class="small mb-2"><?=e(t('Enter the 6-digit OTP to pass this stage. Each stage is verified by you, then approved by an administrator before the next one unlocks.'))?></p>
        <?php if($details['otp_challenge']):?><div class="small"><?=e(t('Expires:'))?> <?=e($details['otp_challenge']['expires_at'])?> · <?=e(t('Attempts:'))?> <?=e((string)$details['otp_challenge']['attempts'])?>/5</div><?php endif;?>
      </div>
      <?php if($displayOtp):?><div class="alert alert-warning"><strong><?=e(t('One-Time Password'))?> — <?=e(t('Stage'))?> <?=$currentStage?> (<?=e(t('sent to your email'))?>):</strong> <span class="fs-4 fw-bold font-monospace"><?=e($displayOtp)?></span></div><?php endif;?>
      <form method="post" class="mt-3"><?=csrf_field()?>
        <input type="hidden" name="reference" value="<?=e($ref)?>"><input type="hidden" name="action" value="confirm">
        <label class="form-label fw-semibold"><?=e(t('Enter OTP'))?> — <?=e(t(TransferService::stageLabel($currentStage)))?></label>
        <input name="otp" class="form-control form-control-lg otp-input" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••" required autofocus>
        <button class="btn btn-primary btn-lg w-100 mt-3"><i class="bi bi-check-circle me-1"></i><?=e(t('Verify Stage %s of %s',null,[$currentStage,$totalStages]))?></button>
      </form>
      <div class="d-flex gap-2 mt-3">
        <form method="post" class="flex-fill"><?=csrf_field()?><input type="hidden" name="reference" value="<?=e($ref)?>"><input type="hidden" name="action" value="resend"><button class="btn btn-outline-secondary w-100"><?=e(t('Resend OTP'))?></button></form>
        <form method="post" class="flex-fill" onsubmit="return confirm('<?=e(t('Cancel this transfer?'))?>')"><?=csrf_field()?><input type="hidden" name="reference" value="<?=e($ref)?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger w-100"><?=e(t('Cancel'))?></button></form>
      </div>
    <?php elseif($status==='awaiting_approval'):?>
      <div class="alert alert-info mt-4">
        <h6 class="fw-bold mb-1"><i class="bi bi-person-check me-1"></i><?=e(t('Awaiting admin approval'))?></h6>
        <p class="small mb-2"><?=e(t('All 4 verification stages passed. An administrator must release this transfer before the funds move. You will be notified when it is approved or rejected.'))?></p>
        <div class="small"><?=e(t('Track the status any time from your transaction history.'))?> <a href="<?=url('transactions.php')?>"><?=e(t('Transaction history'))?></a></div>
      </div>
      <a href="<?=url('transactions.php')?>" class="btn btn-primary"><i class="bi bi-receipt me-1"></i><?=e(t('View transaction status'))?></a>
      <a href="<?=url('transfer.php')?>" class="btn btn-outline-primary"><?=e(t('New transfer'))?></a>
    <?php elseif($status==='completed'):?>
      <div class="alert alert-success mt-4"><?=e(t('Transfer completed.'))?> <a href="<?=url('transfer-receipt.php')?>?ref=<?=urlencode($ref)?>" class="alert-link"><?=e(t('View receipt'))?></a></div>
    <?php else:?>
      <div class="alert alert-secondary mt-4"><?=e(t('Status'))?>: <?=e(tx_label($status))?>. <a href="<?=url('transactions.php')?>" class="alert-link"><?=e(t('View details'))?></a></div>
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
