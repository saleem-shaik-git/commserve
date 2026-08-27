<?php
require_once dirname(__DIR__).'/app/helpers.php';
require_once dirname(__DIR__).'/app/Services/CryptoService.php';
require_once dirname(__DIR__).'/app/Services/AccountService.php';
require_auth();
$user=auth_user();$db=Database::connection();$svc=new CryptoService($db);$accountService=new AccountService($db);
$uid=(int)$user['id'];
$wallets=$svc->wallets($uid);
$accounts=$accountService->getAccounts($uid);
$totalBalance=$accountService->getTotalBalance($uid);
$side=($_GET['side']??'buy')==='sell'?'sell':'buy';
$symbol=strtoupper(trim($_GET['symbol']??'BTC'));
try{$svc->rate($symbol);}catch(Throwable $e){$symbol='BTC';}
$error=null;$success=null;$trade=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $side=($_POST['side']??'buy')==='sell'?'sell':'buy';
        $accountId=(int)($_POST['account_id']??0);
        $symbol=strtoupper(trim($_POST['symbol']??'BTC'));
        $pin=trim((string)($_POST['pin']??''));
        if($side==='buy'){
            $trade=$svc->buy($uid,$accountId,$symbol,trim((string)($_POST['usd_amount']??'')),$pin);
            $success=t('Order executed. Bought %s %s for $%s.',null,[$trade['crypto_amount'],$trade['symbol'],number_format((float)$trade['usd_amount'],2)]);
        }else{
            $trade=$svc->sell($uid,$accountId,$symbol,trim((string)($_POST['crypto_amount']??'')),$pin);
            $success=t('Order executed. Sold %s %s for $%s.',null,[$trade['crypto_amount'],$trade['symbol'],number_format((float)$trade['usd_amount'],2)]);
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$rates=[];
foreach($wallets as $w){$rates[$w['symbol']]=$w['eff_rate'];}
$selectedWallet=null;
foreach($wallets as $w){if($w['symbol']===$symbol){$selectedWallet=$w;break;}}
$pageTitle=t('Trade Crypto');$currentPage='crypto';
require __DIR__.'/partials/header.php';require __DIR__.'/partials/sidebar.php';
?>
<div class="row justify-content-center"><div class="col-lg-8">
  <nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?=url('crypto.php')?>"><?=e(t('Crypto Wallet'))?></a></li><li class="breadcrumb-item active"><?=e(t('Trade Crypto'))?></li></ol></nav>
  <?php if($error):?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?=e($error)?></div><?php endif;?>
  <?php if($success):?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?=e($success)?> <div class="small mt-1"><?=e(t('Reference'))?>: <span class="font-monospace"><?=e($trade['reference'])?></span> · <?=e(($trade['source']==='live')?t('Live rate'):t('Reference rate'))?> $<?=number_format((float)$trade['rate'],(float)$trade['rate']<1?4:2)?></div></div><?php endif;?>

  <div class="card border-0 shadow-sm"><div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h3 class="fw-bold mb-0"><i class="bi bi-currency-bitcoin me-2"></i><?=e(t('Trade Crypto'))?></h3>
      <span class="badge rate-badge <?=($selectedWallet['rate_source']??'reference')==='live'?'text-bg-success':'text-bg-secondary'?>"><?=$side==='buy'?e(t('Buy')):e(t('Sell'))?> · <?=($selectedWallet['rate_source']??'reference')==='live'?e(t('Live')):e(t('Reference'))?></span>
    </div>
    <p class="text-muted small"><?=e(t('Buy crypto with your account balance or sell back to your account at the current USD rate. Rates refresh every minute.'))?></p>

    <ul class="nav nav-pills mb-3" role="tablist">
      <li class="nav-item"><a class="nav-link <?=$side==='buy'?'active':''?>" href="<?=url('crypto-trade.php')?>?side=buy&symbol=<?=urlencode($symbol)?>"><i class="bi bi-plus-circle me-1"></i><?=e(t('Buy'))?></a></li>
      <li class="nav-item"><a class="nav-link <?=$side==='sell'?'active':''?>" href="<?=url('crypto-trade.php')?>?side=sell&symbol=<?=urlencode($symbol)?>"><i class="bi bi-dash-circle me-1"></i><?=e(t('Sell'))?></a></li>
    </ul>

    <form method="post" class="row g-3"><?=csrf_field()?>
      <input type="hidden" name="side" value="<?=e($side)?>">
      <div class="col-md-6"><label class="form-label"><?=e(t('From Account'))?> / <?=e(t('Fiat account'))?></label><select name="account_id" class="form-select" required><option value=""><?=e(t('Select account'))?></option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>"><?=e($a['type_name'])?> · <?=e($a['account_number'])?> · $<?=number_format((float)$a['available_balance'],2)?></option><?php endforeach;?></select></div>
      <div class="col-md-6"><label class="form-label"><?=e(t('Asset'))?></label><select name="symbol" class="form-select" id="symbol" required><?php foreach($wallets as $w):?><option value="<?=e($w['symbol'])?>" <?=$w['symbol']===$symbol?'selected':''?>><?=e($w['symbol'])?> — $<?=number_format((float)$w['eff_rate'],(float)$w['eff_rate']<1?4:2)?> · <?=e(rtrim(rtrim(number_format((float)$w['balance'],18,'.',''),'0'),'.'))?> <?=e(t('available'))?></option><?php endforeach;?></select></div>
      <div class="col-md-6"><label class="form-label"><?=$side==='buy'?e(t('Amount (USD)')):e(t('Amount (crypto)'))?></label>
        <?php if($side==='buy'):?><div class="input-group"><span class="input-group-text">$</span><input name="usd_amount" id="amount" class="form-control" inputmode="decimal" placeholder="0.00" required></div>
        <?php else:?><input name="crypto_amount" id="amount" class="form-control" inputmode="decimal" placeholder="0.00000000" required><?php endif;?></div>
      <div class="col-md-6"><label class="form-label"><?=e(t('Transaction PIN'))?></label><input name="pin" type="password" class="form-control" inputmode="numeric" pattern="\d{4,6}" maxlength="6" required></div>
      <div class="col-12"><div class="alert alert-info small mb-0" id="estimate"><?=e(t('Estimated'))?>: —</div></div>
      <div class="col-12"><button class="btn btn-<?=$side==='buy'?'success':'danger'?> btn-lg w-100"><i class="bi bi-lightning-charge me-2"></i><?=$side==='buy'?e(t('Buy Crypto')):e(t('Sell Crypto'))?></button></div>
    </form>
  </div></div>
  <div class="card border-0 shadow-sm mt-3"><div class="card-body p-3 small text-muted"><i class="bi bi-info-circle me-1"></i><?=e(t('Purchases debit your ledger-backed account balance; sales credit it immediately. Prices come from a public market feed and fall back to the bank reference rate when the feed is unreachable.'))?></div></div>
</div></div>
<script>
(function(){
  const rates=<?=json_encode($rates)?>, side=<?=json_encode($side)?>;
  const sym=document.getElementById('symbol'), amt=document.getElementById('amount'), est=document.getElementById('estimate');
  function upd(){
    const r=parseFloat(rates[sym.value]); if(!r||!amt.value){est.textContent='<?=e(t('Estimated'))?>: —';return;}
    const v=parseFloat(amt.value); if(isNaN(v)||v<=0){est.textContent='<?=e(t('Estimated'))?>: —';return;}
    if(side==='buy'){const c=v/r; est.textContent='<?=e(t('Estimated'))?>: '+c.toFixed(8).replace(/0+$/,'').replace(/\.$/,'')+' '+sym.value;}
    else{const u=v*r; est.textContent='<?=e(t('Estimated'))?>: $'+u.toFixed(2)+' USD';}
  }
  sym.addEventListener('change',upd); amt.addEventListener('input',upd); upd();
})();
</script>
<?php require __DIR__.'/partials/footer.php';?>
