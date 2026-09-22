<?php
declare(strict_types=1);
namespace AV\Service;

class Auth
{
    public static function sessionStart(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_name('AVSESSID');
            @session_start();
        }
    }

    public static function check(array $web): bool
    {
        if (!empty($web['allowed_ips']) && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $web['allowed_ips'], true)) {
            return false;
        }
        if ((string)($web['user'] ?? '') === '') return false;
        self::sessionStart();
        return !empty($_SESSION['av_auth_ok']);
    }

    public static function gate(array $web): void
    {
        if (self::check($web)) return;
        self::sessionStart();
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri !== '' && basename($uri) !== 'login.php') {
            $_SESSION['av_redirect'] = $uri;
        }
        header('Location: av.php?action=login');
        exit;
    }

    public static function logout(): void
    {
        self::sessionStart();
        $_SESSION = [];
        session_destroy();
        // Сбрасываем cookie сессии в браузере
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
    }
}
