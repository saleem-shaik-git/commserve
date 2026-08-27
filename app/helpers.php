<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/i18n.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly'=>true,'secure'=>(bool)filter_var(env('SESSION_SECURE','false'), FILTER_VALIDATE_BOOLEAN),'samesite'=>'Lax']);
    session_start();
}

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect(string $path): never { header('Location: ' . $path); exit; }

/**
 * Application base path (e.g. "/commserve/public" or "" when the web server
 * document root already points at public/). Detected from the current script
 * unless overridden via BASE_PATH in .env.
 */
function base_path(): string {
    static $base = null;
    if ($base !== null) return $base;
    $configured = trim((string) env('BASE_PATH', ''));
    if ($configured !== '') return $base = rtrim($configured, '/');
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($script === '') return $base = '';
    $dir = dirname($script);
    // Admin pages live one level below public/; normalise to the public root.
    if (basename($dir) === 'admin') $dir = dirname($dir);
    if (basename($dir) === 'assets') $dir = dirname($dir);
    if ($dir === '/' || $dir === '.' || $dir === '\\') $dir = '';
    return $base = $dir;
}

/** Build an application-relative URL from a path relative to public/ (e.g. url('login.php')). */
function url(string $path = ''): string {
    $path = ltrim($path, '/');
    $base = base_path();
    if ($path === '') return $base;
    return ($base === '' ? '' : $base) . '/' . $path;
}

function csrf_token(): string { if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void { if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) { http_response_code(419); exit('Invalid CSRF token.'); } }
function auth_user(): ?array { return $_SESSION['user'] ?? null; }
function require_auth(): void { if (!auth_user()) redirect(url('login.php')); }
function require_role(string $role): void { require_auth(); if ((auth_user()['role'] ?? '') !== $role) { http_response_code(403); exit('Forbidden'); } }
function currency_symbol(string $currency='USD'): string { $c=strtoupper(trim($currency));return match($c){'USD'=>'$','EUR'=>'€','GBP'=>'£','NGN'=>'₦','JPY'=>'¥',default=>$c.' '}; }
function format_money(float|string $amount, string $currency='USD'): string { return currency_symbol($currency).number_format((float)$amount,2); }
function format_date(?string $date, string $fmt='M d, Y'): string { if(!$date) return '-'; $ts=strtotime($date); return $ts?date($fmt,$ts):$date; }
function money_color(string $entryType): string { return $entryType==='credit'?'text-success':'text-danger'; }
function safe_count(mixed $v): int { return is_countable($v) ? count($v) : 0; }
