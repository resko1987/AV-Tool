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
            if ($what === 'all' || $what === 'files') {
                if ($b['files']) {
                    echo "Восстановление файлов...\n";
                    $rf = self::restoreFiles($b['files'], $CFG['site_root'], $log);
                    echo "  файлов в архиве: {$rf['total']}\n";
                    echo "  восстановлено: {$rf['ok']}\n";
                    if ($rf['skipped'] > 0) echo "  пропущено (небезопасные пути в архиве): {$rf['skipped']}\n";
                    if ($rf['failed']) {
                        echo "  ОШИБКИ (" . count($rf['failed']) . "):\n";
                        foreach ($rf['failed'] as $p => $err) echo "    ! $p — $err\n";
                    }
                } else {
                    echo "В этом бэкапе нет файлового архива.\n";
                }
            }
            if ($what === 'all' || $what === 'db') {
                if ($b['db']) {
                    echo "Восстановление БД...\n";
                    $rd = self::restoreDb($b['db'], $CFG['db'], $log);
                    echo $rd ? "  БД восстановлена.\n" : "  ОШИБКА: восстановить БД не удалось (подробности в журнале).\n";
                } else {
                    echo "В этом бэкапе нет дампа БД.\n";
                }
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

    /**
     * Пофайловое восстановление из zip в site_root.
     *
     * ZipArchive::extractTo() молча пропускает файлы, которые не может
     * перезаписать (чужой владелец/права 444 и т.п.), и возвращает true —
     * поэтому восстанавливаем каждый файл отдельно и честно считаем ошибки.
     *
     * @return array{total:int,ok:int,skipped:int,failed:array<string,string>}
     */
    private static function restoreFiles(string $zipPath, string $root, Logger $log): array
    {
        $stat = ['total' => 0, 'ok' => 0, 'skipped' => 0, 'failed' => []];
        if (!class_exists('ZipArchive') || !is_file($zipPath)) {
            $stat['failed'][$zipPath] = 'архив недоступен';
            return $stat;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $stat['failed'][$zipPath] = 'не удалось открыть архив';
            return $stat;
        }

        $rootReal = @realpath($root) ?: rtrim($root, '/');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            $stat['total']++;

            // защита от path traversal (../) и абсолютных путей в архиве
            $norm = str_replace('\\', '/', $entry);
            if ($norm === '' || $norm[0] === '/' || strpos($norm, '../') !== false || strpos($norm, '/..') !== false) {
                $stat['skipped']++;
                $log->warn("Восстановление: пропущен небезопасный путь в архиве: $entry");
                continue;
            }
            if (substr($norm, -1) === '/') continue; // каталог

            $dest = $root . '/' . $norm;
            $dir = dirname($dest);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                $stat['failed'][$norm] = 'не удалось создать каталог ' . dirname($norm);
                continue;
            }

            // существующий файл с чужим владельцем или без права записи:
            // пробуем снять read-only и перезаписать; иначе — copy+unlink
            if (is_file($dest) && !is_writable($dest)) {
                @chmod($dest, 0644);
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $stat['failed'][$norm] = 'не удалось прочитать из архива';
                continue;
            }
            if (@file_put_contents($dest, $content) !== false) {
                @chmod($dest, 0644);
                $stat['ok']++;
                continue;
            }
            // fallback: copy поверх + удаление упрямого файла
            $tmp = $dest . '.av_restore.tmp';
            if (@file_put_contents($tmp, $content) !== false
                && (@rename($tmp, $dest) || (@unlink($dest) && @rename($tmp, $dest)))) {
                @chmod($dest, 0644);
                $stat['ok']++;
            } else {
                @unlink($tmp);
                $writableDir = is_writable($dir) ? 'да' : 'нет';
                $stat['failed'][$norm] = "нет прав на запись (каталог доступен для записи: $writableDir; владелец файла не позволяет перезапись)";
                $log->error("Восстановление: не удалось записать $norm");
            }
        }
        $zip->close();

        if (!$stat['failed']) {
            $log->info("Файлы восстановлены из $zipPath ({$stat['ok']} шт.)");
        } else {
            $log->error("Восстановление из $zipPath: ok={$stat['ok']}, ошибок=" . count($stat['failed']));
        }
        return $stat;
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
