<?php
// Load environment values from .env without requiring Composer.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"");
    }
}
function env(string $key, mixed $default = null): mixed { return $_ENV[$key] ?? $default; }
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
define('APP_NAME', (string) env('APP_NAME', 'CommServe Bank'));
define('DEFAULT_CURRENCY', (string) env('DEFAULT_CURRENCY', 'USD'));
define('DEFAULT_LOCALE', (string) env('DEFAULT_LOCALE', 'en'));
