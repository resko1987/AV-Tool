<?php
declare(strict_types=1);
namespace AV\Model;

use RuntimeException;
use ZipArchive;

/**
 * Updater — система обновлений AV Tool из GitHub.
 *
 * Логика «слежения за последним коммитом»:
 *  1) check()   — точка обновления = последний релиз/тег; если их нет — последний коммит ветки.
 *                 Сравнение идёт с локальным файлом VERSION (тег «v0.1.0» или короткий sha).
 *                 Несовпадение → «требуется обновление».
 *  2) apply()   — бэкап текущих файлов в data/update_backup/<версия>_<время>/ →
 *                 распаковка zip → копирование в каталог инструмента
 *                 (data/, logs/, backup/, quarantine/, vendor/, .env, .git НЕ трогаем) →
 *                 запись VERSION → проверка синтаксиса PHP (php -l). Ошибка → откат.
 *  3) rollback()— вернуть файлы и VERSION из последнего бэкапа.
 */
class Updater
{
    /** Элементы первого уровня в архиве, которые НИКОГДА не накатываются */
    private const PRESERVE = [
        'data', 'logs', 'backup', 'quarantine', 'vendor', '.git',
        '.env', '.gitignore', 'AGENTS.md', 'license-panel', 'lending', 'tools',
    ];

    private array $cfg;
    private Logger $log;
    private UpdateSource $source;

    public function __construct(array $cfg, Logger $log, ?UpdateSource $source = null)
    {
        $this->cfg = $cfg;
        $this->log = $log;
        $upd = $cfg['update'] ?? [];
        $this->source = $source ?? new GithubUpdateSource(
            (string)($upd['repo'] ?? ''),
            (string)($upd['branch'] ?? 'main')
        );
    }

    /** Текущая версия: содержимое VERSION (тег или короткий sha), нормализованное. */
    public function current(): string
    {
        return self::normalize((string)@file_get_contents($this->cfg['tool_dir'] . '/VERSION'));
    }

    public function versionFile(): string
    {
        return $this->cfg['tool_dir'] . '/VERSION';
    }

    /**
     * Проверить обновления.
     * @return array{current:string, latest:?array, releases:array[], updateAvailable:bool}
     */
    public function check(): array
    {
        $releases = $this->source->releases(20);
        foreach ($releases as &$r) {
            $r['normalized'] = self::normalize((string)$r['tag']);
        }
        unset($r);
        $current = $this->current();
        $latest = $releases[0] ?? null;
        // Требуется обновление, если последняя точка в GitHub отличается от локальной VERSION
        $available = $latest !== null
            && $latest['normalized'] !== ''
            && $latest['normalized'] !== $current;
        return [
            'current' => $current !== '' ? $current : 'unknown',
            'latest' => $latest,
            'releases' => $releases,
            'updateAvailable' => $available,
        ];
    }

    /**
     * Установить версию по тегу/sha.
     * @return array{ok:bool, to:string, steps:string[]}
     */
    public function apply(string $tag): array
    {
        $steps = [];
        $release = $this->findRelease($tag);
        $root = $this->cfg['tool_dir'];
        $targetVersion = self::normalize((string)$release['tag']);

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Для обновления нужно PHP-расширение zip (ZipArchive).');
        }
        if (!is_writable($root)) {
            throw new RuntimeException('Каталог программы недоступен для записи: ' . $root);
        }

        $zipPath = null;
        $backupDir = null;
        try {
            // 1. Скачать архив
            $zipPath = $this->source->download($release);
            $steps[] = 'Скачан архив ' . $release['tag'] . ' (' . self::humanSize((int)@filesize($zipPath)) . ')';

            // 2. Бэкап: текущие файлы из архива + текущий VERSION (чтобы откат восстановил версию)
            $backupDir = $this->backupDir() . '/' . $this->current() . '_' . date('Ymd_His');
            $list = $this->backupCurrent($zipPath, $backupDir);
            $vf = $this->versionFile();
            if (is_file($vf)) {
                @copy($vf, $backupDir . '/.version');
            }
            $steps[] = 'Бэкап текущих файлов: ' . count($list) . ' шт → ' . self::relHome($root, $backupDir);

            // 3. Распаковка во временный каталог
            $tmpDir = $this->unpack($zipPath);
            $steps[] = 'Архив распакован';
            try {
                // 4. Накатываем файлы (атомарно: .avtmp + rename)
                $copied = $this->copyTree($tmpDir, $root);
                $steps[] = "Файлов обновлено/добавлено: $copied";

                // 5. Версия в репозитории может отставать от тега релиза — пишем фактическую
                file_put_contents($vf, $targetVersion . "\n");

                // 6. Санити-чек: синтаксис PHP после наката
                $bad = $this->lint($root);
                if ($bad !== []) {
                    throw new RuntimeException("Синтаксические ошибки после обновления:\n" . implode("\n", $bad));
                }
                $steps[] = 'Проверка синтаксиса PHP: OK';
            } catch (\Throwable $e) {
                $this->rollbackFrom($backupDir); // вернёт файлы и .version → VERSION
                $steps[] = 'ОШИБКА: ' . $e->getMessage() . ' — выполнен откат на предыдущую версию';
                throw new RuntimeException($e->getMessage() . ' (изменения откачены, программа не повреждена)');
            } finally {
                self::rrmdir($tmpDir);
            }

            $this->pruneBackups();
            $this->log->warn("Обновление установлено: {$release['tag']} (файлов: $copied)");
            $steps[] = 'Готово: программа обновлена до ' . $targetVersion;
            return ['ok' => true, 'to' => $targetVersion, 'steps' => $steps];
        } finally {
            if ($zipPath !== null && is_file($zipPath)) @unlink($zipPath);
        }
    }

    /**
     * Откат на предыдущую версию (из последнего бэкапа).
     * @return string[]
     */
    public function rollback(): array
    {
        $dir = $this->latestBackup();
        if ($dir === null) {
            throw new RuntimeException('Нет бэкапов для отката.');
        }
        $n = $this->rollbackFrom($dir);
        $this->log->warn("Выполнен откат обновления из $dir (файлов: $n)");
        return ["Восстановлено файлов: $n", 'Источник: ' . self::relHome($this->cfg['tool_dir'], $dir)];
    }

    /** Список бэкапов перед обновлениями (новые первыми): [ [dir,label,mtime,size] ] */
    public function backups(): array
    {
        $base = $this->backupDir();
        $out = [];
        if (is_dir($base)) {
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                $out[] = [
                    'dir' => $d,
                    'label' => basename($d),
                    'mtime' => (int)@filemtime($d),
                    'size' => self::dirSize($d),
                ];
            }
            usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        }
        return $out;
    }

    // ----------------------------------------------------------------

    /** Нормализация версии: «v1.2.3» → «1.2.3», sha коммита без изменений. */
    public static function normalize(string $tag): string
    {
        $tag = trim($tag);
        if ($tag !== '' && ($tag[0] === 'v' || $tag[0] === 'V')) {
            $tag = substr($tag, 1);
        }
        return strtolower($tag); // sha коммита регистронезависим
    }

    private function findRelease(string $tag): array
    {
        $tag = self::normalize($tag);
        foreach ($this->source->releases(50) as $r) {
            if (self::normalize((string)$r['tag']) === $tag) return $r;
        }
        throw new RuntimeException("Версия «$tag» не найдена в GitHub.");
    }

    private function backupDir(): string
    {
        $dir = rtrim($this->cfg['data_dir'], '/\\') . '/update_backup';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        return $dir;
    }

    private function latestBackup(): ?string
    {
        $all = $this->backups();
        return $all[0]['dir'] ?? null;
    }

    /**
     * Сохранить текущие версии файлов, которые придут в архиве (кроме PRESERVE).
     * @return string[] относительные пути
     */
    private function backupCurrent(string $zipPath, string $destDir): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Не удалось открыть архив обновления.');
        }
        $rootPrefix = null;
        $saved = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if ($name === '' || substr($name, -1) === '/') continue;
                $rel = self::stripRoot($name, $rootPrefix);
                if ($rel === null || self::isPreserved($rel)) continue;
                $src = $this->cfg['tool_dir'] . '/' . $rel;
                if (!is_file($src)) continue; // файла ещё нет — бэкапить нечего
                $dst = $destDir . '/' . $rel;
                $d = dirname($dst);
                if (!is_dir($d)) mkdir($d, 0750, true);
                if (!@copy($src, $dst)) {
                    throw new RuntimeException('Бэкап: не удалось скопировать ' . $rel);
                }
                $saved[] = $rel;
            }
        } finally {
            $zip->close();
        }
        return $saved;
    }

    private function unpack(string $zipPath): string
    {
        $tmp = sys_get_temp_dir() . '/av_upd_x_' . bin2hex(random_bytes(6));
        mkdir($tmp, 0750, true);
        $zip = new ZipArchive();
        $ok = $zip->open($zipPath);
        if ($ok !== true) {
            self::rrmdir($tmp);
            throw new RuntimeException("Не удалось распаковать архив (код $ok).");
        }
        $zip->extractTo($tmp);
        $zip->close();

        // GitHub архив содержит единый корневой каталог «owner-repo-<sha|tag>/» — поднимаем содержимое
        $entries = array_values(array_diff(scandir($tmp) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($tmp . '/' . $entries[0])) {
            $inner = $tmp . '/' . $entries[0];
            foreach (array_diff(scandir($inner) ?: [], ['.', '..']) as $item) {
                rename($inner . '/' . $item, $tmp . '/' . $item);
            }
            rmdir($inner);
        }
        return $tmp;
    }

    /** Скопировать дерево $src → $dst (без PRESERVE), атомарно через .avtmp. Возвращает число файлов. */
    private function copyTree(string $src, string $dst): int
    {
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $rel = substr($file->getPathname(), strlen($src) + 1);
            $rel = str_replace('\\', '/', $rel);
            if (self::isPreserved($rel)) continue;
            $target = $dst . '/' . $rel;
            if ($file->isDir()) {
                if (!is_dir($target)) mkdir($target, 0750, true);
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            $tmpName = $target . '.avtmp';
            if (!@copy($file->getPathname(), $tmpName)) {
                throw new RuntimeException("Не удалось записать файл: $rel (проверьте права)");
            }
            @chmod($tmpName, 0644);
            if (!@rename($tmpName, $target)) {
                @unlink($tmpName);
                throw new RuntimeException("Не удалось заменить файл: $rel");
            }
            $count++;
        }
        return $count;
    }

    /** Восстановить файлы из бэкапа (включая .version → VERSION). Возвращает число файлов. */
    private function rollbackFrom(string $backupDir): int
    {
        $root = $this->cfg['tool_dir'];
        $count = 0;

        // Сначала VERSION, чтобы даже при ошибке дальше версия была корректной
        $savedVersion = $backupDir . '/.version';
        if (is_file($savedVersion)) {
            @copy($savedVersion, $this->versionFile());
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) continue;
            $rel = substr($file->getPathname(), strlen($backupDir) + 1);
            if ($rel === '.version') continue;
            $target = $root . '/' . str_replace('\\', '/', $rel);
            $dir = dirname($target);
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            if (!@copy($file->getPathname(), $target)) {
                throw new RuntimeException('Откат: не удалось восстановить ' . $rel);
            }
            $count++;
        }
        return $count;
    }

    /**
     * php -l по всем PHP-файлам инструмента (без vendor/data/logs).
     * @return string[] ошибки (пусто = всё ок). PHP-бинарник недоступен → пусто.
     */
    private function lint(string $root): array
    {
        $php = self::detectPhpBinary();
        if ($php === null) return [];
        $errors = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
            $path = $file->getPathname();
            $rel = substr($path, strlen($root) + 1);
            $first = explode('/', str_replace('\\', '/', $rel))[0];
            if (in_array($first, ['vendor', 'data', 'logs', 'backup', 'quarantine', 'license-panel', 'lending', 'tools'], true)) continue;
            $out = [];
            @exec(escapeshellarg($php) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $errors[] = $rel . ': ' . implode(' ', $out);
            }
        }
        return $errors;
    }

    private static function detectPhpBinary(): ?string
    {
        static $bin = null, $done = false;
        if ($done) return $bin;
        $done = true;
        $o = [];
        foreach (['php', '/usr/bin/php', '/usr/local/bin/php'] as $cand) {
            @exec(escapeshellarg($cand) . ' -v 2>/dev/null', $o, $code);
            if (($code ?? 1) === 0) { $bin = $cand; return $bin; }
        }
        return null;
    }

    /** Оставить только 3 последних бэкапа обновлений. */
    private function pruneBackups(): void
    {
        foreach (array_slice($this->backups(), 3) as $b) {
            self::rrmdir($b['dir']);
        }
    }

    /**
     * Относительный путь внутри архива без корневого префикса;
     * null — запись не подходит.
     *
     * Архив может быть двух видов:
     *  - с верхним каталогом (GitHub codeload и make_release.sh): «release-1.0/av.php» → «av.php»;
     *  - файлы прямо в корне (вручную собранный): «av.php» → «av.php».
     */
    private static function stripRoot(string $name, ?string &$prefix): ?string
    {
        $name = ltrim($name, '/');
        if ($prefix === null) {
            $pos = strpos($name, '/');
            // нет слеша — файл в корне zip, обёртки нет
            $prefix = $pos === false ? '' : substr($name, 0, $pos + 1);
        }
        if ($prefix !== '' && !str_starts_with($name, $prefix)) return null;
        $rel = $prefix === '' ? $name : substr($name, strlen($prefix));
        if ($rel === '' || str_contains($rel, '..')) return null; // анти-traversal
        return $rel;
    }

    private static function isPreserved(string $rel): bool
    {
        $first = explode('/', $rel)[0];
        return in_array($first, self::PRESERVE, true);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private static function dirSize(string $dir): int
    {
        $size = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) $size += (int)$file->getSize();
        }
        return $size;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' МБ';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' КБ';
        return $bytes . ' Б';
    }

    private static function relHome(string $root, string $path): string
    {
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
