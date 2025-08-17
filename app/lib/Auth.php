<?php
declare(strict_types=1);

namespace App\Lib;

final class Auth
{
    private static function passFile(): string { return __DIR__ . '/../config/password.json'; }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['login']) && $_SESSION['login'] === true;
    }

    public static function login(string $password): bool
    {
        $data = Util::readJson(self::passFile(), ['hash' => password_hash('changeme', PASSWORD_DEFAULT)]);
        $hash = (string)($data['hash'] ?? '');
        $ok = password_verify($password, $hash);
        usleep(250000);
        if ($ok) {
            $_SESSION['login'] = true;
            return true;
        }
        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}


