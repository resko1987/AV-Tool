<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\License;
use AV\Model\Updater;
use AV\View;
use AV\Service\Auth;

class UpdateController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);
        License::requireValid($CFG); // обновления — платный функционал

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);

        $msg = null; $msgType = 'info';
        $check = null; $checkError = null;
        $steps = null;
        $backups = [];

        try {
            $updater = new Updater($CFG, $log);
            $check = $updater->check();
            $backups = $updater->backups();
        } catch (\Throwable $e) {
            $checkError = $e->getMessage();
        }

        // --- Действия (POST, чтобы GET-ссылку нельзя было отдать третьему лицу) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $check !== null) {
            $do = $_POST['do'] ?? '';
            try {
                if ($do === 'check') {
                    // Просто перезагрузка страницы с пересчётом (уже сделано выше)
                    header('Location: av.php?action=update');
                    exit;
                }
                if ($do === 'apply') {
                    $tag = (string)($_POST['tag'] ?? $check['latest']['tag'] ?? '');
                    if ($tag === '') {
                        throw new \RuntimeException('Не указана версия для обновления.');
                    }
                    $res = $updater->apply($tag);
                    $steps = $res['steps'];
                    $msg = 'Программа обновлена до версии <b>' . \AV\e((string)$res['to']) . '</b>';
                    $msgType = 'ok';
                    $log->warn("Update: установлена версия {$res['to']} (веб, по кнопке)");
                    // Перечитываем состояние
                    $check = $updater->check();
                    $backups = $updater->backups();
                } elseif ($do === 'rollback') {
                    $steps = $updater->rollback();
                    $msg = 'Выполнен откат на предыдущую версию.';
                    $msgType = 'warn';
                    $log->warn('Update: откат на предыдущую версию (веб)');
                    $check = $updater->check();
                    $backups = $updater->backups();
                }
            } catch (\Throwable $e) {
                $msg = 'Ошибка: ' . \AV\e($e->getMessage());
                $msgType = 'err';
                $log->error('Update: ' . $e->getMessage());
            }
        }

        ob_start();
        View::render('update', [
            'msg' => $msg, 'msgType' => $msgType,
            'check' => $check, 'checkError' => $checkError,
            'steps' => $steps, 'backups' => $backups,
            'repo' => (string)($CFG['update']['repo'] ?? ''),
        ]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Обновление', 'active' => 'update', 'content' => $content]);
        echo ob_get_clean();
    }
}
