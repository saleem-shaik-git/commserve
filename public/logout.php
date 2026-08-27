<?php
require_once dirname(__DIR__).'/app/helpers.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); }
elseif (auth_user()) {
    // Record the outgoing session in user_sessions and revoke sibling sessions.
    try {
        $u = auth_user(); $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO user_sessions(user_id,session_hash,ip_address,user_agent) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_seen_at=CURRENT_TIMESTAMP');
        $stmt->execute([(int)$u['id'], hash('sha256', session_id()), $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
        $db->prepare('UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND session_hash<>? AND revoked_at IS NULL')->execute([(int)$u['id'], hash('sha256', session_id())]);
    } catch (Throwable $e) { error_log('CommServe logout session tracking: '.$e->getMessage()); }
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirect(url('login.php'));
