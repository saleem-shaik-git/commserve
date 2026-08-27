<?php
require_once dirname(__DIR__, 2) . '/app/helpers.php';
require_role('admin');
require_once dirname(__DIR__, 2) . '/app/Services/ChatService.php';
$db = Database::connection();
$svc = new ChatService($db);
$admin = auth_user();
$error = null; $success = null;

// AJAX poll for the open conversation.
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $threadId = (int)($_GET['thread'] ?? 0);
        $after = (int)($_GET['after'] ?? 0);
        $rows = $svc->messages($threadId, 'admin', $after, true);
        $out = [];
        foreach ($rows as $m) {
            $out[] = ['id' => (int)$m['id'], 'role' => (string)$m['sender_role'],
                      'name' => $m['sender_role'] === 'admin' ? t('Support Team') : trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
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
        $threadId = (int)($_POST['thread'] ?? 0);
        if ($action === 'send') {
            $svc->sendAdmin((int)$admin['id'], $threadId, (string)($_POST['body'] ?? ''));
            redirect(url('admin/support-chat.php') . '?thread=' . $threadId . '#bottom');
        } elseif ($action === 'status') {
            $svc->setStatus($threadId, $_POST['status'] === 'closed' ? 'closed' : 'open', true, 0);
            $success = $_POST['status'] === 'closed' ? t('Conversation closed.') : t('Conversation reopened.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$threads = $svc->threadsForAdmin();
$activeId = (int)($_GET['thread'] ?? 0);
if ($activeId === 0 && $threads) $activeId = (int)$threads[0]['id'];
$active = null; $messages = [];
if ($activeId) {
    $active = $svc->thread($activeId);
    if ($active) $messages = $svc->messages($activeId, 'admin', 0, true);
}
$pageTitle = t('Live Chat');
$adminCurrent = 'chat';
require __DIR__ . '/partials/header.php';
?>
<main class="container-fluid p-3 p-lg-4">
  <?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?=e($success)?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="fw-bold mb-0"><i class="bi bi-headset me-2"></i><?=e(t('Live Chat'))?></h2>
    <span class="text-muted small"><?=e(t('Answer customer messages in real time.'))?></span>
  </div>
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-bold"><?=e(t('Conversations'))?></div>
        <div class="card-body p-2">
          <?php if (!$threads): ?><div class="text-muted small text-center py-4"><?=e(t('No conversations yet.'))?></div><?php endif; ?>
          <?php foreach ($threads as $th): ?>
            <a href="<?=url('admin/support-chat.php')?>?thread=<?=$th['id']?>" class="d-block border rounded p-2 mb-2 text-decoration-none <?= (int)$th['id']===$activeId ? 'bg-light' : '' ?>">
              <div class="d-flex justify-content-between align-items-center">
                <span class="fw-semibold small text-truncate"><?=e($th['first_name'].' '.$th['last_name'])?></span>
                <?php if ((int)$th['unread'] > 0): ?><span class="badge text-bg-danger"><?=(int)$th['unread']?></span><?php endif; ?>
              </div>
              <div class="small text-muted text-truncate"><?=e($th['subject'] !== '' ? $th['subject'] : t('Conversation'))?> · <?=e(mb_substr((string)$th['last_message'], 0, 50))?></div>
              <div class="d-flex justify-content-between align-items-center mt-1">
                <span class="badge <?=$th['status']==='open'?'text-bg-success':'text-bg-secondary'?>"><?=e(tx_label($th['status']))?></span>
                <small class="text-muted"><?=e(format_date($th['last_message_at'],'M d, H:i'))?></small>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <?php if (!$active): ?>
        <div class="card border-0 shadow-sm"><div class="card-body p-5 text-center text-muted"><i class="bi bi-chat-square-text fs-1 d-block mb-3"></i><?=e(t('Select a conversation to reply.'))?></div></div>
      <?php else: ?>
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <span class="fw-bold"><?=e($active['first_name'].' '.$active['last_name'])?></span>
            <span class="text-muted small">· <?=e($active['email'])?> · <?=e($active['subject'] !== '' ? $active['subject'] : t('Conversation'))?></span>
            <span class="badge ms-1 <?=$active['status']==='open'?'text-bg-success':'text-bg-secondary'?>"><?=e(tx_label($active['status']))?></span>
          </div>
          <form method="post"><?=csrf_field()?>
            <input type="hidden" name="thread" value="<?=$active['id']?>">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="status" value="<?=$active['status']==='open'?'closed':'open'?>">
            <button class="btn btn-sm btn-outline-secondary"><?=$active['status']==='open'?e(t('Close conversation')):e(t('Reopen'))?></button>
          </form>
        </div>
        <div class="card-body chat-window p-3" id="chatWindow" data-thread="<?=$active['id']?>">
          <?php foreach ($messages as $m): ?>
            <div class="chat-row <?= $m['sender_role']==='admin' ? 'chat-me' : 'chat-them' ?>">
              <div class="chat-bubble" data-id="<?=$m['id']?>">
                <div class="chat-name small fw-semibold"><?=e($m['sender_role']==='admin'?t('Support Team'):trim(($m['first_name']??'').' '.($m['last_name']??'')))?></div>
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
</main>
<script>
(function(){
  const win=document.getElementById('chatWindow'); if(!win)return;
  const thread=win.dataset.thread; let last=0;
  document.querySelectorAll('#chatWindow [data-id]').forEach(el=>{last=Math.max(last,+el.dataset.id);});
  function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function scroll(){const b=document.getElementById('chatBottom');if(b)b.scrollIntoView({block:'end'});}
  function add(m){
    const row=document.createElement('div');
    row.className='chat-row '+(m.role==='admin'?'chat-me':'chat-them');
    row.innerHTML='<div class="chat-bubble" data-id="'+m.id+'"><div class="chat-name small fw-semibold">'+esc(m.name)+'</div><div class="chat-body">'+esc(m.body)+'</div><div class="chat-time small text-muted">'+esc(m.at)+'</div></div>';
    document.getElementById('chatBottom').before(row);
  }
  async function poll(){
    try{
      const res=await fetch('?ajax=1&thread='+thread+'&after='+last,{headers:{'X-Requested-With':'XMLHttpRequest'}});
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
