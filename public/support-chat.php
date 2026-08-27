<?php
require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/Services/ChatService.php';
require_once dirname(__DIR__) . '/app/Services/AccountService.php';
require_auth();
$user = auth_user(); $db = Database::connection();
$svc = new ChatService($db); $accountService = new AccountService($db);
$uid = (int)$user['id'];
$error = null; $success = null;

// AJAX poll: returns new messages as JSON and marks them read.
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $threadId = (int)($_GET['thread'] ?? 0);
        $after = (int)($_GET['after'] ?? 0);
        // Ownership check: customers may only poll their own threads.
        $chk = $db->prepare('SELECT id FROM chat_threads WHERE id=? AND user_id=? LIMIT 1');
        $chk->execute([$threadId, $uid]);
        if (!$chk->fetchColumn()) throw new RuntimeException('denied');
        $rows = $svc->messages($threadId, 'customer', $after, true);
        $out = [];
        foreach ($rows as $m) {
            $out[] = ['id' => (int)$m['id'], 'role' => (string)$m['sender_role'],
                      'name' => $m['sender_role'] === 'admin' ? t('Support Team') : ($m['first_name'] ?? ''),
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
        $action = $_POST['action'] ?? '';
        if ($action === 'open') {
            $id = $svc->openThread($uid, (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''));
            redirect(url('support-chat.php') . '?thread=' . $id);
        } elseif ($action === 'send') {
            $svc->sendCustomer($uid, (int)($_POST['thread'] ?? 0), (string)($_POST['body'] ?? ''));
            redirect(url('support-chat.php') . '?thread=' . (int)$_POST['thread'] . '#bottom');
        } elseif ($action === 'close') {
            $svc->setStatus((int)($_POST['thread'] ?? 0), 'closed', false, $uid);
            $success = t('Conversation closed.');
        } elseif ($action === 'reopen') {
            $svc->setStatus((int)($_POST['thread'] ?? 0), 'open', false, $uid);
            $success = t('Conversation reopened.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$threads = $svc->threadsForUser($uid);
$activeId = (int)($_GET['thread'] ?? 0);
if ($activeId === 0 && $threads) {
    foreach ($threads as $th) { if ($th['status'] === 'open') { $activeId = (int)$th['id']; break; } }
    if ($activeId === 0) $activeId = (int)$threads[0]['id'];
}
$active = null; $messages = [];
if ($activeId) {
    foreach ($threads as $th) if ((int)$th['id'] === $activeId) $active = $th;
    if ($active) $messages = $svc->messages($activeId, 'customer', 0, true);
}
$totalBalance = $accountService->getTotalBalance($uid);
$pageTitle = t('Live Chat'); $currentPage = 'chat';
require __DIR__ . '/partials/header.php'; require __DIR__ . '/partials/sidebar.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-bold"><i class="bi bi-chat-dots me-2"></i><?=e(t('Live Chat'))?></div>
      <div class="card-body p-2">
        <?php if (!$threads): ?><div class="text-muted small text-center py-4"><?=e(t('No conversations yet.'))?></div><?php endif; ?>
        <?php foreach ($threads as $th): ?>
          <a href="<?=url('support-chat.php')?>?thread=<?=$th['id']?>" class="d-block border rounded p-2 mb-2 text-decoration-none <?= (int)$th['id']===$activeId ? 'bg-light' : '' ?>">
            <div class="d-flex justify-content-between align-items-center">
              <span class="fw-semibold small text-truncate"><?=e($th['subject'] !== '' ? $th['subject'] : t('Conversation'))?></span>
              <?php if ((int)$th['unread'] > 0): ?><span class="badge text-bg-danger"><?=(int)$th['unread']?></span><?php endif; ?>
            </div>
            <div class="small text-muted text-truncate"><?=e(mb_substr((string)$th['last_message'], 0, 60))?></div>
            <div class="d-flex justify-content-between align-items-center mt-1">
              <span class="badge <?=$th['status']==='open'?'text-bg-success':'text-bg-secondary'?>"><?=e(tx_label($th['status']))?></span>
              <small class="text-muted"><?=e(format_date($th['last_message_at'],'M d, H:i'))?></small>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card border-0 shadow-sm">
      <div class="card-body p-3">
        <h6 class="fw-bold mb-2"><i class="bi bi-plus-circle me-1"></i><?=e(t('Start a new conversation'))?></h6>
        <form method="post"><?=csrf_field()?>
          <input type="hidden" name="action" value="open">
          <div class="mb-2"><input name="subject" class="form-control form-control-sm" maxlength="200" placeholder="<?=e(t('Subject'))?>"></div>
          <div class="mb-2"><textarea name="body" class="form-control form-control-sm" rows="3" placeholder="<?=e(t('How can we help you?'))?>" required></textarea></div>
          <button class="btn btn-primary btn-sm w-100"><?=e(t('Start conversation'))?></button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?=e($success)?></div><?php endif; ?>
    <?php if (!$active): ?>
      <div class="card border-0 shadow-sm"><div class="card-body p-5 text-center text-muted">
        <i class="bi bi-chat-square-text fs-1 d-block mb-3"></i><?=e(t('Start a new conversation'))?> — <?=e(t('our team replies here in real time.'))?>
      </div></div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <span class="fw-bold"><?=e($active['subject'] !== '' ? $active['subject'] : t('Conversation'))?></span>
          <span class="badge ms-1 <?=$active['status']==='open'?'text-bg-success':'text-bg-secondary'?>"><?=e(tx_label($active['status']))?></span>
        </div>
        <form method="post"><?=csrf_field()?>
          <input type="hidden" name="thread" value="<?=$active['id']?>">
          <input type="hidden" name="action" value="<?=$active['status']==='open'?'close':'reopen'?>">
          <button class="btn btn-sm btn-outline-secondary"><?=$active['status']==='open'?e(t('Close conversation')):e(t('Reopen'))?></button>
        </form>
      </div>
      <div class="card-body chat-window p-3" id="chatWindow" data-thread="<?=$active['id']?>">
        <?php foreach ($messages as $m): ?>
          <div class="chat-row <?= $m['sender_role']==='customer' ? 'chat-me' : 'chat-them' ?>">
            <div class="chat-bubble">
              <div class="chat-name small fw-semibold"><?=e($m['sender_role']==='admin'?t('Support Team'):(string)$user['name'])?></div>
              <div class="chat-body"><?=e($m['body'])?></div>
              <div class="chat-time small text-muted"><?=e($m['created_at'])?></div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$messages): ?><div class="text-center text-muted small py-3"><?=e(t('No messages yet.'))?></div><?php endif; ?>
        <div id="chatBottom"></div>
      </div>
      <?php if ($active['status']==='open'): ?>
      <div class="card-footer bg-white">
        <form method="post" class="d-flex gap-2"><?=csrf_field()?>
          <input type="hidden" name="thread" value="<?=$active['id']?>">
          <input type="hidden" name="action" value="send">
          <textarea name="body" class="form-control" rows="2" placeholder="<?=e(t('Type your message…'))?>" required></textarea>
          <button class="btn btn-primary align-self-end"><i class="bi bi-send me-1"></i><?=e(t('Send'))?></button>
        </form>
      </div>
      <?php else: ?>
      <div class="card-footer bg-white text-muted small"><?=e(t('This conversation is closed. Reopen it to continue.'))?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  const win=document.getElementById('chatWindow'); if(!win)return;
  const thread=win.dataset.thread; let last=0;
  document.querySelectorAll('#chatWindow [data-id]').forEach(el=>{last=Math.max(last,+el.dataset.id);});
  function scroll(){const b=document.getElementById('chatBottom');if(b)b.scrollIntoView({block:'end'});}
  function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function add(m){
    const row=document.createElement('div');
    row.className='chat-row '+(m.role==='customer'?'chat-me':'chat-them');
    row.innerHTML='<div class="chat-bubble" data-id="'+m.id+'"><div class="chat-name small fw-semibold">'+esc(m.name)+'</div><div class="chat-body">'+esc(m.body)+'</div><div class="chat-time small text-muted">'+esc(m.at)+'</div></div>';
    document.getElementById('chatBottom').before(row);
  }
  async function poll(){
    try{
      const res=await fetch('<?=e(url('support-chat.php'))?>?ajax=1&thread='+thread+'&after='+last,{headers:{'X-Requested-With':'XMLHttpRequest'}});
      const data=await res.json();
      if(data.ok&&data.messages.length){
        data.messages.forEach(m=>{add(m);last=Math.max(last,m.id);});
        scroll();
        document.title='(+) <?=e(t('Live Chat'))?>';
      }
    }catch(e){}
  }
  scroll(); setInterval(poll,5000);
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
