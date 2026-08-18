<?php
require_once __DIR__ . '/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly'=>true,'secure'=>(bool)filter_var(env('SESSION_SECURE','false'), FILTER_VALIDATE_BOOLEAN),'samesite'=>'Lax']);
    session_start();
}

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect(string $path): never { header('Location: ' . $path); exit; }
function csrf_token(): string { if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void { if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) { http_response_code(419); exit('Invalid CSRF token.'); } }
function auth_user(): ?array { return $_SESSION['user'] ?? null; }
function require_auth(): void { if (!auth_user()) redirect('/commserve/public/login.php'); }
function require_role(string $role): void { require_auth(); if ((auth_user()['role'] ?? '') !== $role) { http_response_code(403); exit('Forbidden'); } }
