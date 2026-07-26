<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('PHP 8.1 or newer is required.');
}

foreach (['pdo_mysql', 'curl', 'openssl', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        http_response_code(500);
        exit('Required PHP extension is missing: ' . htmlspecialchars($extension, ENT_QUOTES, 'UTF-8'));
    }
}

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    header('Location: install.php');
    exit;
}

$config = require $configFile;
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Tehran');

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_name('usermanager_session');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

require APP_ROOT . '/app/Database.php';
require APP_ROOT . '/app/Auth.php';
require APP_ROOT . '/app/helpers.php';
require APP_ROOT . '/app/MikrotikClient.php';
require APP_ROOT . '/app/Services.php';

try {
    Database::connect($config['db']);
} catch (Throwable $exception) {
    http_response_code(500);
    if (!empty($config['app']['debug'])) {
        exit(e($exception->getMessage()));
    }
    exit('Database connection failed. Check config.php.');
}

set_exception_handler(static function (Throwable $exception) use ($config): void {
    error_log('[UserManager] ' . $exception);
    http_response_code(500);
    $message = !empty($config['app']['debug'])
        ? $exception->getMessage()
        : tr('خطای غیرمنتظره‌ای رخ داد. جزئیات در گزارش خطای هاست ثبت شد.', 'An unexpected error occurred. Details were written to the hosting error log.');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;max-width:700px;margin:60px auto;padding:24px;border:1px solid #ddd;border-radius:12px">' . e($message) . '</div>';
});

