<?php
declare(strict_types=1);
namespace AV\Controller;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\Mailer;
use AV\Model\Backup;
use AV\View;
use AV\Service\Auth;

class RestoreController
{
    public function index(): void
    {
        $CFG = Config::load();
        Auth::gate($CFG['web']);

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);
        $backups = Backup::listAll($CFG['backup_dir']);

        $action = $_GET['action'] ?? 'list';
        $stamp = $_GET['stamp'] ?? '';
        $what = $_GET['what'] ?? 'all';
        $restoreOutput = '';
        $msg = null; $msgType = 'info';

        if (($_GET['do'] ?? '') === 'del' && isset($backups[$stamp])) {
            if (Backup::delete($CFG['backup_dir'], $stamp)) {
                $log->info("Бэкап удалён: $stamp");
                $msg = "Бэкап <code>" . \AV\e($stamp) . "</code> удалён."; $msgType = 'ok';
                $backups = Backup::listAll($CFG['backup_dir']);
            } else {
                $msg = "Не удалось удалить бэкап <code>" . \AV\e($stamp) . "</code>."; $msgType = 'err';
            }
        }

        if (($_GET['do'] ?? '') === 'restore' && isset($backups[$stamp])) {
            $b = $backups[$stamp];
            ob_start();
            if (($what === 'all' || $what === 'files') && $b['files']) {
                echo "Восстановление файлов...\n";
                self::restoreFiles($b['files'], $CFG['site_root'], $log);
            }
            if (($what === 'all' || $what === 'db') && $b['db']) {
                echo "Восстановление БД...\n";
                self::restoreDb($b['db'], $CFG['db'], $log);
            }
            echo "Готово.";
            $restoreOutput = ob_get_clean();
            $action = 'restore';
        }

        ob_start();
        View::render('restore', [
            'action' => $action, 'stamp' => $stamp, 'what' => $what,
            'backups' => $backups, 'restoreOutput' => $restoreOutput,
            'msg' => $msg, 'msgType' => $msgType,
        ]);
        $content = ob_get_clean();

        ob_start();
        View::render('layout', ['title' => 'Восстановление', 'active' => 'restore', 'content' => $content]);
        echo ob_get_clean();
    }

    private static function restoreFiles(string $zipPath, string $root, Logger $log): bool
    {
        if (!class_exists('ZipArchive') || !is_file($zipPath)) return false;
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) return false;
        $ok = $zip->extractTo($root);
        $zip->close();
        if ($ok) $log->info("Файлы восстановлены из $zipPath");
        return $ok;
    }

    private static function restoreDb(string $sqlPath, array $db, Logger $log): bool
    {
        if (!is_file($sqlPath)) return false;
        $mysql = $db['mysql'] ?? '';
        if (!empty($mysql) && is_executable($mysql)) {
            $cmd = escapeshellcmd($mysql) .
                ' --host=' . escapeshellarg($db['host']) .
                ' --port=' . (int) $db['port'] .
                ' --user=' . escapeshellarg($db['user']) .
                ' --password=' . escapeshellarg($db['password']) .
                ' ' . escapeshellarg($db['name']) .
                ' < ' . escapeshellarg($sqlPath) . ' 2>/dev/null';
            @exec($cmd, $out, $code);
            if ($code === 0) { $log->info("БД восстановлена через mysql"); return true; }
        }
        try {
            $pdo = new \PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                $db['user'], $db['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $e) { $log->error("PDO: " . $e->getMessage()); return false; }
        $sql = @file_get_contents($sqlPath);
        if ($sql === false) return false;
        $pdo->exec("SET NAMES utf8mb4");
        $queries = self::splitSql($sql);
        foreach ($queries as $q) {
            if (trim($q) === '') continue;
            try { $pdo->exec($q); } catch (\Throwable $e) { $log->warn("Query error: " . $e->getMessage()); }
        }
        $log->info("БД восстановлена через PDO");
        return true;
    }

    private static function splitSql(string $sql): array
    {
        $out = []; $buf = ''; $len = strlen($sql);
        $inSingle = false; $inDouble = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($c === "'" && !$inDouble) $inSingle = !$inSingle;
            elseif ($c === '"' && !$inSingle) $inDouble = !$inDouble;
            elseif ($c === ';' && !$inSingle && !$inDouble) { $out[] = $buf; $buf = ''; continue; }
            $buf .= $c;
        }
        if (trim($buf) !== '') $out[] = $buf;
        return $out;
    }
}
