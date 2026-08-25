<?php
require_once dirname(__DIR__) . '/app/helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/commserve/public/dashboard.php');
verify_csrf();
$locale = (string)($_POST['locale'] ?? 'en');
set_locale($locale);
try {
    if ($u = auth_user()) {
        $db = Database::connection();
        $db->prepare('UPDATE users SET locale=? WHERE id=?')->execute([current_locale(), (int)$u['id']]);
        $_SESSION['user']['locale'] = current_locale();
    }
} catch (Throwable $e) { error_log('CommServe locale update: '.$e->getMessage()); }
$back = $_SERVER['HTTP_REFERER'] ?? '/commserve/public/dashboard.php';
$path = parse_url($back, PHP_URL_PATH) ?? '';
redirect(preg_match('#^/commserve/public/#', $path) ? $back : '/commserve/public/dashboard.php');
