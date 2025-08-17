<?php
declare(strict_types=1);

namespace App\Lib;

final class Csrf
{
    public static function issue(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function validate(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            Util::jsonResponse(['error' => 'CSRF'], 403);
        }
    }
}


