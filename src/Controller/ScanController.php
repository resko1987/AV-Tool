<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\Mailer;
use AV\Model\Backup;
use AV\Model\Quarantine;
use AV\Model\Scanner;
use AV\View;
use AV\Service\Auth;

class ScanController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        if (!isset($_GET['run'])) {
            ob_start();
            View::render('scan', ['CFG' => $CFG]);
            $content = ob_get_clean();
        } else {
            if (!$CFG['antivirus_enabled']) {
                $content = '<div class="note warn">Антивирус отключён.</div>';
            } else {
                $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
                $mail = new Mailer($CFG['mail'], $log);
                $backup = new Backup($CFG, $log, $mail);
                $quarantine = new Quarantine($CFG, $log);
                $scanner = new Scanner($CFG, $log, $mail, $backup, $quarantine, $CFG['auto_restore']);
                $files = $scanner->findChanged((int) $CFG['scan_interval_minutes']);
                $threats = $scanner->scanFiles($files);
                ob_start();
                View::render('scan_result', ['files' => $files, 'threats' => $threats, 'count' => count($files), 'details' => $scanner->getThreatDetails(), 'action' => 'scan']);
                $content = ob_get_clean();
            }
        }

        ob_start();
        View::render('layout', ['title' => 'Сканирование', 'active' => 'scan', 'content' => $content]);
        echo ob_get_clean();
    }

    public function fullscan(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        if (!isset($_GET['run'])) {
            ob_start();
            View::render('fullscan', ['CFG' => $CFG]);
            $content = ob_get_clean();
        } else {
            if (!$CFG['antivirus_enabled']) {
                $content = '<div class="note warn">Антивирус отключён.</div>';
            } else {
                $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
                $mail = new Mailer($CFG['mail'], $log);
                $backup = new Backup($CFG, $log, $mail);
                $quarantine = new Quarantine($CFG, $log);
                $scanner = new Scanner($CFG, $log, $mail, $backup, $quarantine, $CFG['auto_restore']);
                $files = $scanner->findAll();
                $threats = $scanner->scanFiles($files);
                ob_start();
                View::render('scan_result', ['files' => $files, 'threats' => $threats, 'count' => count($files), 'details' => $scanner->getThreatDetails(), 'action' => 'fullscan']);
                $content = ob_get_clean();
            }
        }

        ob_start();
        View::render('layout', ['title' => 'Полное сканирование', 'active' => 'fullscan', 'content' => $content]);
        echo ob_get_clean();
    }
}
