<?php
require_once dirname(__DIR__, 2) . '/app/helpers.php';
require_role('admin');
require_once dirname(__DIR__, 2) . '/app/Services/MailerService.php';
$error = null; $success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        if (($_POST['action'] ?? '') === 'clear') {
            $n = MailerService::clearInbox();
            $success = t('%s message(s) removed.', null, [$n]);
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$driver = MailerService::driver();
$messages = MailerService::inbox(100);
$pageTitle = t('Dev Mailbox');
$adminCurrent = 'mailbox';
require __DIR__ . '/partials/header.php';
?>
<main class="container-fluid p-3 p-lg-4">
  <?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?=e($success)?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="fw-bold mb-0"><i class="bi bi-envelope me-2"></i><?=e(t('Dev Mailbox'))?></h2>
    <div class="d-flex gap-2">
      <a href="<?=url('admin/dev-mailbox.php')?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-clockwise me-1"></i><?=e(t('Refresh'))?></a>
      <form method="post" onsubmit="return confirm('<?=e(t('Remove all logged messages?'))?>')"><?=csrf_field()?>
        <input type="hidden" name="action" value="clear">
        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i><?=e(t('Clear'))?></button>
      </form>
    </div>
  </div>
  <p class="text-muted small"><?=e(t('Development mail inbox — every OTP email sent by the application is stored here when MAIL_DRIVER=log.'))?>
    <span class="badge text-bg-<?= $driver === 'log' ? 'success' : 'secondary' ?> ms-1"><?=e(t('Mail driver'))?>: <?=e(strtoupper($driver))?></span></p>
  <?php if ($driver !== 'log'): ?>
  <div class="alert alert-info small"><?=e(t('Messages are delivered by the configured driver; switch MAIL_DRIVER=log in .env to capture them here.'))?></div>
  <?php endif; ?>

  <?php if (!$messages): ?>
  <div class="card border-0 shadow-sm"><div class="card-body p-5 text-center text-muted"><i class="bi bi-inbox fs-1 d-block mb-3"></i><?=e(t('No messages yet.'))?></div></div>
  <?php else: ?>
  <div class="row g-3">
    <?php foreach ($messages as $i => $m): ?>
    <div class="col-12">
      <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div>
            <span class="fw-bold"><?=e($m['subject'])?></span>
            <span class="text-muted small">· <?=e(t('To'))?>: <?=e($m['to'])?></span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <small class="text-muted"><?=e($m['date'])?></small>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#mail<?=$i?>"><i class="bi bi-chevron-down"></i></button>
          </div>
        </div>
        <div class="collapse mt-2 <?= $i === 0 ? 'show' : '' ?>" id="mail<?=$i?>">
          <pre class="bg-light border rounded p-3 mb-0" style="white-space:pre-wrap"><?=e($m['body'])?></pre>
        </div>
      </div></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
