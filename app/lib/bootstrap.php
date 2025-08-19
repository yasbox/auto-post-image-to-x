<?php
declare(strict_types=1);

namespace App\Lib;

// Simple PSR-4-like autoloader for App\Lib
spl_autoload_register(function($class){
    if (str_starts_with($class, 'App\\Lib\\')) {
        $path = __DIR__ . '/' . substr($class, strlen('App\\Lib\\')) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});

// Ensure base directories exist
$base = realpath(__DIR__ . '/..');
foreach ([
    $base . '/data/inbox',
    $base . '/data/tmp/llm',
    $base . '/data/tmp/tweet',
    $base . '/data/meta',
    $base . '/config',
    $base . '/logs',
] as $dir) {
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_writable($dir)) { @chmod($dir, 0777); }
}

// Load .env into $_ENV
if (is_file($base . '/config/.env')) {
    $lines = file($base . '/config/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        $_ENV[$key] = $val;
        putenv($key . '=' . $val);
    }
}

// Configure session persistence (30 days)
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
@ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
if (function_exists('session_set_cookie_params')) {
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}


