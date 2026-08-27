<?php
require_once dirname(__DIR__) . '/app/helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(url('dashboard.php'));
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
$back = $_SERVER['HTTP_REFERER'] ?? url('dashboard.php');
$path = parse_url($back, PHP_URL_PATH) ?? '';
$base = base_path();
$internal = $base === '' ? true : str_starts_with($path, $base . '/');
redirect($internal ? $back : url('dashboard.php'));
