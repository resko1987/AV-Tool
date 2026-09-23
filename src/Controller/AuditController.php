<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\ServerAudit;
use AV\View;
use AV\Service\Auth;

class AuditController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
        $audit = null;
        $msg = null;
        $msgType = 'info';

        // --- Исправление прав на чувствительный файл (кнопка «640») ---
        if (($_GET['do'] ?? '') === 'fix' && isset($_GET['path'], $_GET['csrf'])) {
            Auth::sessionStart();
            $csrf = (string)($_SESSION['av_csrf'] ?? '');
            if ($csrf === '' || !hash_equals($csrf, (string)$_GET['csrf'])) {
                $msg = 'Сессия истекла — обновите страницу и попробуйте снова.';
                $msgType = 'err';
            } else {
                $res = (new ServerAudit($CFG, $log))->fixSensitive((string)$_GET['path']);
                $msg = $res['msg'] . ' <code>' . \AV\e((string)$_GET['path']) . '</code>';
                $msgType = $res['ok'] ? 'ok' : 'err';
                // после смены прав сразу перезапускаем аудит, чтобы показать актуальное состояние
                if ($res['ok']) {
                    $audit = (new ServerAudit($CFG, $log))->run();
                }
            }
        }

        $run = isset($_GET['run']);
        if ($run) {
            // Аудит может занять время на больших деревьях
            @set_time_limit(300);
            $audit = (new ServerAudit($CFG, $log))->run();
        }

        // CSRF-токен для кнопок исправления
        Auth::sessionStart();
        if (empty($_SESSION['av_csrf'])) {
            $_SESSION['av_csrf'] = bin2hex(random_bytes(16));
        }
        $csrf = (string)$_SESSION['av_csrf'];

        ob_start();
        View::render('audit', ['CFG' => $CFG, 'audit' => $audit, 'msg' => $msg, 'msgType' => $msgType, 'csrf' => $csrf]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Аудит сервера', 'active' => 'audit', 'content' => $content]);
        echo ob_get_clean();
    }
}