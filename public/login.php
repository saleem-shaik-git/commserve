<?php
require_once dirname(__DIR__) . '/app/helpers.php';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $stmt = Database::connection()->prepare('SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? LIMIT 1');
    $stmt->execute([$email]); $user = $stmt->fetch();
    if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$user['id'],'email'=>$user['email'],'name'=>$user['first_name'].' '.$user['last_name'],'role'=>$user['role']];
        $log = Database::connection()->prepare('INSERT INTO login_attempts(email,ip_address,success) VALUES(?,?,1)'); $log->execute([$email,$_SERVER['REMOTE_ADDR'] ?? null]);
        redirect($user['role'] === 'admin' ? '/commserve/public/admin/' : '/commserve/public/dashboard.php');
    }
    $log = Database::connection()->prepare('INSERT INTO login_attempts(email,ip_address,success) VALUES(?,?,0)'); $log->execute([$email,$_SERVER['REMOTE_ADDR'] ?? null]);
    $error = 'Invalid credentials or inactive account.';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login - <?=e(APP_NAME)?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="/commserve/public/assets/css/app.css" rel="stylesheet"></head><body class="auth-bg"><div class="container"><div class="row justify-content-center align-items-center min-vh-100"><div class="col-md-5 col-lg-4"><div class="card border-0 shadow-lg"><div class="card-body p-4 p-lg-5"><div class="text-center mb-4"><div class="brand-mark mx-auto mb-3">C</div><h3 class="fw-bold">CommServe</h3><p class="text-muted">Demo Banking Portal</p></div><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post"><?=csrf_field()?><div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control form-control-lg" required autofocus></div><div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control form-control-lg" required></div><button class="btn btn-primary btn-lg w-100">Sign in</button></form><div class="alert alert-light mt-4 small mb-0">Demo: <b>john@commserve.test</b> / <b>password</b><br>Admin: <b>admin@commserve.test</b> / <b>password</b></div></div></div><p class="text-center text-white-50 mt-3 small">SIMULATION ONLY — NO REAL MONEY</p></div></div></div></body></html>
