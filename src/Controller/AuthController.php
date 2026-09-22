<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Service\Auth;
use AV\Service\RateLimiter;
use AV\View;

class AuthController
{
    public function login(): void
    {
        $CFG = Config::load();
        Auth::sessionStart();

        if (!empty($_SESSION['av_auth_ok'])) {
            header('Location: av.php?action=status');
            exit;
        }

        $user = (string)($CFG['web']['user'] ?? '');
        if ($user === '') {
            http_response_code(403);
            echo "403: веб-доступ отключён.";
            exit;
        }

        $rl = new RateLimiter(
            rtrim($CFG['tool_dir'], '/\\') . '/data/login_attempts.json'
        );

        if ($rl->isBlocked()) {
            http_response_code(429);
            echo "429 Слишком много попыток. Повторите через 10 минут.";
            exit;
        }

        if (empty($_SESSION['av_csrf'])) {
            $_SESSION['av_csrf'] = bin2hex(random_bytes(16));
        }

        $error = '';
        $emailPrev = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (trim((string)($_POST['website'] ?? '')) !== '') {
                header('Location: av.php?action=login&blocked=1');
                exit;
            }

            $sentCsrf = (string)($_POST['csrf'] ?? '');
            if ($sentCsrf === '' || !hash_equals((string)$_SESSION['av_csrf'], $sentCsrf)) {
                $error = 'Сессия формы истекла.';
            } else {
                $formTime = (int)($_POST['form_time'] ?? 0);
                $elapsed = time() - $formTime;
                if ($formTime <= 0 || $elapsed < 2) {
                    $error = 'Слишком быстрая отправка.';
                    usleep(800000);
                } else {
                    $email = trim((string)($_POST['email'] ?? ''));
                    $password = (string)($_POST['password'] ?? '');
                    $emailPrev = $email;

                    $pass = (string)($CFG['web']['pass'] ?? '');
                    $okUser = $email !== '' && hash_equals($user, $email);
                    $okPass = $password !== '' && hash_equals($pass, $password);

                    if ($okUser && $okPass) {
                        session_regenerate_id(true);
                        $_SESSION['av_auth_ok'] = true;
                        $target = (string)($_SESSION['av_redirect'] ?? 'av.php?action=status');
                        unset($_SESSION['av_redirect']);
                        if (strpos($target, '//') !== false || preg_match('#^[a-z]+:#i', $target)) {
                            $target = 'av.php?action=status';
                        }
                        header('Location: ' . $target);
                        exit;
                    }

                    $rl->record();
                    $error = 'Неверный email или пароль.';
                    $fails = $rl->getFails();
                    if ($fails >= 3) {
                        sleep(min(10, ($fails - 2) * 2));
                    }
                }
            }
        }

        View::render('login', ['error' => $error, 'emailPrev' => $emailPrev, 'csrf' => $_SESSION['av_csrf'] ?? '']);
    }

    public function logout(): void
    {
        Auth::logout();
        // Чистый редирект на форму входа (без 401/WWW-Authenticate —
        // он вызывал нативное окно Basic-авторизации браузера вместо формы)
        header('Location: av.php?action=login');
        exit;
    }
}
