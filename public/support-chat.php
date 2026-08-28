<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/ChatService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user(); $db = Database::connection();
$svc = new ChatService($db); $accountService = new AccountService($db);
$uid = (int)$user['id'];
$error = null;

// AJAX poll: returns new messages as JSON and marks them read.
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $threadId = $svc->ensureThread($uid); // one conversation per customer
        $after = (int)($_GET['after'] ?? 0);
        $rows = $svc->messages($threadId, 'customer', $after, true);
        $out = [];
        foreach ($rows as $m) {
            $out[] = ['id' => (int)$m['id'], 'role' => (string)$m['sender_role'],
                      'name' => $m['sender_role'] === 'admin' ? t('Support Team') : (string)$user['name'],
                      'body' => (string)$m['body'], 'at' => (string)$m['created_at']];
        }
        echo json_encode(['ok' => true, 'messages' => $out], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'messages' => []]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $svc->sendCustomer($uid, (string)($_POST['body'] ?? ''));
        redirect(url('support-chat.php') . '#bottom');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$threadId = $svc->ensureThread($uid);
$thread = $svc->thread($threadId);
$messages = $svc->messages($threadId, 'customer', 0, true);
$totalBalance = $accountService->getTotalBalance($uid);
$pageTitle = t('Live Chat'); $currentPage = 'chat';
require __DIR__ . '/partials/header.php'; require __DIR__ . '/partials/sidebar.php';
?>
<div class="row justify-content-center"><div class="col-lg-9">
  <nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?=url('dashboard.php')?>"><?=e(t('Dashboard'))?></a></li><li class="breadcrumb-item active"><?=e(t('Live Chat'))?></li></ol></nav>
  <?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <span class="fw-bold"><i class="bi bi-headset me-2"></i><?=e(t('Live Chat'))?></span>
        <span class="badge ms-1 <?=($thread['status']??'open')==='open'?'text-bg-success':'text-bg-secondary'?>"><?=e(tx_label($thread['status']??'open'))?></span>
      </div>
      <small class="text-muted"><?=e(t('Our whole team sees this conversation — any administrator can reply.'))?></small>
    </div>
    <div class="card-body chat-window p-3" id="chatWindow" data-thread="<?=$threadId?>">
      <?php foreach ($messages as $m): ?>
        <div class="chat-row <?= $m['sender_role']==='customer' ? 'chat-me' : 'chat-them' ?>">
          <div class="chat-bubble" data-id="<?=$m['id']?>">
            <div class="chat-name small fw-semibold"><?=e($m['sender_role']==='admin'?t('Support Team'):(string)$user['name'])?></div>
            <div class="chat-body"><?=e($m['body'])?></div>
            <div class="chat-time small text-muted"><?=e($m['created_at'])?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$messages): ?>
        <div class="text-center text-muted small py-4"><i class="bi bi-chat-dots fs-2 d-block mb-2"></i><?=e(t('Say hello — your message starts your conversation with our team.'))?></div>
      <?php endif; ?>
      <div id="chatBottom"></div>
    </div>
    <div class="card-footer bg-white">
      <form method="post" class="d-flex gap-2"><?=csrf_field()?>
        <textarea name="body" class="form-control" rows="2" placeholder="<?=e(t('Type your message…'))?>" required></textarea>
        <button class="btn btn-primary align-self-end"><i class="bi bi-send me-1"></i><?=e(t('Send'))?></button>
      </form>
      <div class="small text-muted mt-2"><?=e(t('Replies arrive here in real time.'))?><?php if (($thread['status']??'open')==='closed'): ?> · <?=e(t('This conversation is closed — sending a message reopens it.'))?><?php endif; ?></div>
    </div>
  </div>
</div></div>
<script>
(function(){
  const win=document.getElementById('chatWindow'); if(!win)return;
  const thread=win.dataset.thread; let last=0;
  document.querySelectorAll('#chatWindow [data-id]').forEach(el=>{last=Math.max(last,+el.dataset.id);});
  function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function scroll(){const b=document.getElementById('chatBottom');if(b)b.scrollIntoView({block:'end'});}
  function add(m){
    const row=document.createElement('div');
    row.className='chat-row '+(m.role==='customer'?'chat-me':'chat-them');
    row.innerHTML='<div class="chat-bubble" data-id="'+m.id+'"><div class="chat-name small fw-semibold">'+esc(m.name)+'</div><div class="chat-body">'+esc(m.body)+'</div><div class="chat-time small text-muted">'+esc(m.at)+'</div></div>';
    document.getElementById('chatBottom').before(row);
  }
  async function poll(){
    try{
      const res=await fetch('<?=e(url('support-chat.php'))?>?ajax=1&after='+last,{headers:{'X-Requested-With':'XMLHttpRequest'}});
      const data=await res.json();
      if(data.ok&&data.messages.length){
        data.messages.forEach(m=>{add(m);last=Math.max(last,m.id);});
        scroll();
      }
    }catch(e){}
  }
  scroll(); setInterval(poll,5000);
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
