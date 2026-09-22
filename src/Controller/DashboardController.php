<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\Mailer;
use AV\Model\Backup;
use AV\Model\Integrity;
use AV\Model\License;
use AV\Model\Quarantine;
use AV\View;
use AV\Service\Auth;

class DashboardController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
        $mail = new Mailer($CFG['mail'], $log);
        $backup = new Backup($CFG, $log, $mail);
        $integrity = new Integrity($CFG, $log);
        $quarantine = new Quarantine($CFG, $log);

        $latest = $backup->latestFileBackup();
        $hasBase = $integrity->hasBaseline();
        $autoRestore = $CFG['auto_restore'];

        // DB check
        $dbState = ['ok' => false, 'msg' => ''];
        try {
            $pdo = new \PDO(
                "mysql:host={$CFG['db']['host']};port=" . (int)$CFG['db']['port'] . ";dbname={$CFG['db']['name']};charset=utf8mb4",
                $CFG['db']['user'], $CFG['db']['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]
            );
            $tables = (int)$pdo->query("SHOW TABLES")->rowCount();
            $dbState = ['ok' => true, 'msg' => "{$CFG['db']['name']} ({$tables} таблиц)"];
        } catch (\Throwable $e) {
            $dbState = ['ok' => false, 'msg' => $e->getMessage()];
        }

        // Cron activity: последний запуск бэкапа = запись "Бэкап создан" в av.log
        // (крон-задание пишет туда через Logger; cron_backup.log может не существовать,
        // если вывод крона ещё не появлялся)
        $cronBackup = 0;
        $avLog = $CFG['tool_dir'] . '/logs/av.log';
        if (is_file($avLog)) {
            $lines = @file($avLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (strpos($lines[$i], 'Бэкап создан') !== false
                    && preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}):\d{2}\]/', $lines[$i], $m)) {
                    $cronBackup = (int)strtotime($m[1]);
                    break;
                }
            }
        }
        // Скан-логи: scan_recent.log пишется shell-скриптом со своими метками
        $cronScan = 0;
        $scanLog = $CFG['tool_dir'] . '/logs/scan_recent.log';
        if (is_file($scanLog)) {
            $lines = @file($scanLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}):\d{2}\]/', $lines[$i], $m)) {
                    $cronScan = (int)strtotime($m[1]);
                    break;
                }
            }
        }

        $qCount = $quarantine->countFiles();

        // Disk
        $free = @disk_free_space($CFG['backup_dir']);
        $freeTxt = $free !== false ? round($free / 1024 / 1024 / 1024, 1) . ' ГБ' : '—';

        // Backup size (защита от отсутствующей папки)
        $bSize = 0;
        if (is_dir($CFG['backup_dir'])) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($CFG['backup_dir'], \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) $bSize += $f->getSize();
            }
        }
        $bCount = 0;
        foreach (glob($CFG['backup_dir'] . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', basename($d))) $bCount++;
        }

        // Server info
        $srv = [];
        $srv['os'] = function_exists('php_uname') ? php_uname('s') . ' ' . php_uname('r') : '—';
        if (is_readable('/proc/uptime')) {
            $up = (float)file_get_contents('/proc/uptime');
            $d = (int)($up / 86400); $h = (int)($up % 86400 / 3600);
            $srv['uptime'] = $d > 0 ? "{$d} д {$h} ч" : "{$h} ч";
        } else { $srv['uptime'] = '—'; }
        $srv['cpu_cores'] = is_readable('/proc/cpuinfo') ? substr_count((string)@file_get_contents('/proc/cpuinfo'), 'processor') : null;
        if ($srv['cpu_cores']) $srv['cpu'] = $srv['cpu_cores'] . ' ядер';
        if (is_readable('/proc/loadavg')) {
            $load = explode(' ', (string)@file_get_contents('/proc/loadavg'));
            $srv['load'] = round((float)$load[0], 2) . ' / ' . round((float)$load[1], 2) . ' / ' . round((float)$load[2], 2);
        }
        if (is_readable('/proc/meminfo')) {
            preg_match('/MemTotal:\s+(\d+)/', (string)@file_get_contents('/proc/meminfo'), $mt);
            preg_match('/MemAvailable:\s+(\d+)/', (string)@file_get_contents('/proc/meminfo'), $ma);
            if ($mt) {
                $totalMb = round($mt[1] / 1024);
                $availMb = isset($ma[1]) ? round($ma[1] / 1024) : 0;
                $usedMb = $totalMb - $availMb;
                $pct = $totalMb > 0 ? round($usedMb / $totalMb * 100) : 0;
                $srv['ram'] = ['used' => $usedMb, 'total' => $totalMb, 'pct' => $pct];
            }
        }
        $total = @disk_total_space($CFG['site_root']);
        $freeB = @disk_free_space($CFG['backup_dir']);
        if ($total && $freeB !== false) {
            $usedB = $total - $freeB;
            $srv['disk'] = ['free' => round($freeB / 1024 / 1024 / 1024, 1), 'total' => round($total / 1024 / 1024 / 1024, 1), 'pct' => round($usedB / $total * 100)];
        }
        $srv['php'] = PHP_VERSION;

        $agoBackup = self::ago($latest ? @filemtime($latest) : null);
        $agoScan = self::ago($cronScan ?: null);

        ob_start();
        View::render('dashboard', [
            'CFG' => $CFG, 'backup' => $latest, 'dbState' => $dbState,
            'cronBackup' => $cronBackup, 'cronScan' => $cronScan, 'qCount' => $qCount,
            'hasBase' => $hasBase, 'antivirusEnabled' => $CFG['antivirus_enabled'],
            'autoRestore' => $autoRestore, 'srv' => $srv, 'bCount' => $bCount,
            'bSize' => $bSize, 'freeTxt' => $freeTxt, 'latestBackup' => $latest,
            'agoBackup' => $agoBackup, 'agoScan' => $agoScan,
            'license' => License::status($CFG),
        ]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Статус системы', 'active' => 'status', 'content' => $content]);
        echo ob_get_clean();
    }

    private static function ago(?int $ts): string
    {
        if (!$ts) return 'никогда';
        $d = time() - $ts;
        if ($d < 90) return 'только что';
        if ($d < 3600) return round($d / 60) . ' мин назад';
        if ($d < 86400) return round($d / 3600) . ' ч назад';
        return round($d / 86400) . ' дн назад';
    }
}