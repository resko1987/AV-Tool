<?php
declare(strict_types=1);
namespace AV\Model;

class Backup
{
    private array $cfg;
    private Logger $log;
    private Mailer $mail;

    public function __construct(array $cfg, Logger $log, Mailer $mail)
    {
        $this->cfg = $cfg;
        $this->log = $log;
        $this->mail = $mail;
    }

    public function run(bool $withRotation = true): ?string
    {
        License::requireValid($this->cfg);

        $root = $this->cfg['site_root'];
        $backupDir = $this->cfg['backup_dir'];
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            $msg = "Не удалось создать папку бэкапов: $backupDir";
            $this->log->error($msg);
            return null;
        }

        $free = @disk_free_space($backupDir);
        if ($free !== false && $free < $this->cfg['min_free_space']) {
            $msg = "Недостаточно свободного места для бэкапа: " . round($free / 1024 / 1024) . " МБ < " . round($this->cfg['min_free_space'] / 1024 / 1024) . " МБ";
            $this->log->error($msg);
            $this->mail->send("ОШИБКА бэкапа", $msg);
            return null;
        }

        $stamp = date('Y-m-d_H-i-s');
        $dest = $backupDir . DIRECTORY_SEPARATOR . $stamp;
        mkdir($dest, 0755, true);
        mkdir($dest . DIRECTORY_SEPARATOR . 'db', 0755, true);
        mkdir($dest . DIRECTORY_SEPARATOR . 'files', 0755, true);

        $filesZip = $dest . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . "files_$stamp.zip";
        $okFiles = $this->backupFiles($root, $filesZip);

        $dbSql = $dest . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . "db_$stamp.sql";
        $okDb = $this->backupDatabase($dbSql);

        if ($okFiles && $okDb) {
            $this->log->info("Бэкап создан: $stamp");
            if ($withRotation) $this->rotate();
            return $stamp;
        }

        $this->log->error("Бэкап НЕ создан полностью: files=" . ($okFiles ? 'ok' : 'FAIL') . " db=" . ($okDb ? 'ok' : 'FAIL'));
        $this->mail->send("ОШИБКА бэкапа", "files=" . ($okFiles ? 'ok' : 'FAIL') . " db=" . ($okDb ? 'ok' : 'FAIL'));
        return null;
    }

    private function backupFiles(string $root, string $zipPath): bool
    {
        if (!class_exists('ZipArchive')) {
            $this->log->error("Расширение zip недоступно");
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            $this->log->error("Не удалось открыть zip: $zipPath");
            return false;
        }
        $excludes = $this->cfg['exclude_paths'];
        $whitelist = array_merge($this->cfg['scan_exclude_files'] ?? [], Whitelist::load($this->cfg));
        $count = 0;
        FileHelper::iterate($root, $excludes, function ($abs, $rel) use ($zip, &$count, $whitelist) {
            if (FileHelper::isProtected($abs, $this->cfg)) return;
            if (Whitelist::match($rel, $whitelist)) return;
            $zip->addFile($abs, $rel);
            $count++;
        });
        $zip->close();
        $this->log->info("Архив файлов: $count файлов -> $zipPath");
        return true;
    }

    private function backupDatabase(string $sqlPath): bool
    {
        $db = $this->cfg['db'];
        if (!empty($db['mysqldump']) && is_executable($db['mysqldump'])) {
            $cmd = escapeshellcmd($db['mysqldump']) .
                ' --host=' . escapeshellarg($db['host']) .
                ' --port=' . (int) $db['port'] .
                ' --user=' . escapeshellarg($db['user']) .
                ' --password=' . escapeshellarg($db['password']) .
                ' ' . escapeshellarg($db['name']) .
                ' > ' . escapeshellarg($sqlPath) . ' 2>/dev/null';
            @exec($cmd, $out, $code);
            if ($code === 0 && file_exists($sqlPath) && filesize($sqlPath) > 0) {
                $this->log->info("Дамп БД через mysqldump -> $sqlPath");
                return true;
            }
            $this->log->warn("mysqldump не сработал (код $code), пробуем PDO");
        }

        try {
            $pdo = new \PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                $db['user'], $db['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
            );
        } catch (\Throwable $e) {
            $this->log->error("Нет подключения к БД: " . $e->getMessage());
            return false;
        }

        $out = "-- AV DB dump " . date('Y-m-d H:i:s') . PHP_EOL;
        $out .= "SET NAMES utf8mb4;" . PHP_EOL;
        $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $out .= PHP_EOL . "DROP TABLE IF EXISTS `$table`;" . PHP_EOL;
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_ASSOC);
            $out .= $create['Create Table'] . ";" . PHP_EOL;
            $rows = $pdo->query("SELECT * FROM `$table`");
            foreach ($rows as $row) {
                $cols = array_map(fn($c) => '`' . $c . '`', array_keys($row));
                $vals = array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote((string) $v);
                }, array_values($row));
                $out .= "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");" . PHP_EOL;
            }
        }
        file_put_contents($sqlPath, $out);
        $this->log->info("Дамп БД через PDO -> $sqlPath");
        return true;
    }

    public function rotate(): void
    {
        $backupDir = $this->cfg['backup_dir'];
        $folders = [];
        foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $d) {
            $base = basename($d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $base)) {
                $folders[$base] = $d;
            }
        }
        if (empty($folders)) return;
        ksort($folders);

        $keep = [];
        $names = array_keys($folders);
        $recent = array_slice($names, -$this->cfg['keep_daily'], null, true);
        foreach ($recent as $n) $keep[$n] = true;

        $weekly = [];
        foreach ($folders as $name => $d) {
            $ts = strtotime(str_replace('_', ' ', $name));
            if ($ts !== false && (int) date('w', $ts) === 0) $weekly[$name] = $ts;
        }
        arsort($weekly);
        $i = 0;
        foreach ($weekly as $name => $ts) {
            if ($i < $this->cfg['keep_weekly']) { $keep[$name] = true; $i++; }
        }

        $monthly = [];
        foreach ($folders as $name => $d) {
            $ts = strtotime(str_replace('_', ' ', $name));
            if ($ts !== false && (int) date('j', $ts) === 1) $monthly[$name] = $ts;
        }
        arsort($monthly);
        $i = 0;
        foreach ($monthly as $name => $ts) {
            if ($i < $this->cfg['keep_monthly']) { $keep[$name] = true; $i++; }
        }

        $deleted = 0;
        foreach ($folders as $name => $d) {
            if (!isset($keep[$name])) {
                $this->rmDir($d);
                $this->log->info("Удалён старый бэкап: $name");
                $deleted++;
            }
        }
    }

    private function rmDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) rmdir($f->getPathname());
            else unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public function latestFileBackup(): ?string
    {
        $backupDir = $this->cfg['backup_dir'];
        $best = null;
        $bestTime = 0;
        foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $d) {
            foreach (glob($d . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'files_*.zip') as $z) {
                $t = filemtime($z);
                if ($t > $bestTime) { $bestTime = $t; $best = $z; }
            }
        }
        return $best;
    }

    public static function listAll(string $backupDir): array
    {
        $out = [];
        foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $d) {
            $base = basename($d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $base)) continue;
            $files = glob($d . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'files_*.zip');
            $db = glob($d . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'db_*.sql');
            $out[$base] = [
                'dir'   => $d,
                'files' => $files[0] ?? null,
                'db'    => $db[0] ?? null,
            ];
        }
        krsort($out);
        return $out;
    }

    /**
     * Удалить бэкап (директорию со stamped-именем) рекурсивно.
     */
    public static function delete(string $backupDir, string $stamp): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $stamp)) return false;
        $dir = $backupDir . DIRECTORY_SEPARATOR . $stamp;
        if (!is_dir($dir)) return false;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isFile() || $item->isLink()) @unlink($item->getPathname());
            elseif ($item->isDir()) @rmdir($item->getPathname());
        }
        return @rmdir($dir);
    }
}
