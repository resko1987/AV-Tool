<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\Mailer;
use AV\Model\Backup;
use AV\View;
use AV\Service\Auth;

class BackupController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        if (isset($_GET['run'])) {
            $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
            $mail = new Mailer($CFG['mail'], $log);
            ob_start();
            View::render('backup_run', ['CFG' => $CFG, 'log' => $log, 'mail' => $mail]);
            $content = ob_get_clean();
        } else {
            $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
            $mail = new Mailer($CFG['mail'], $log);
            $backup = new Backup($CFG, $log, $mail);
            $latest = $backup->latestFileBackup();
            ob_start();
            View::render('backup', ['CFG' => $CFG, 'latest' => $latest]);
            $content = ob_get_clean();
        }

        ob_start();
        View::render('layout', ['title' => 'Резервное копирование', 'active' => 'backup', 'content' => $content]);
        echo ob_get_clean();
    }
}
