<?php
declare(strict_types=1);
namespace AV\Model;

class Quarantine
{
    private array $cfg;
    private Logger $log;

    public function __construct(array $cfg, Logger $log)
    {
        $this->cfg = $cfg;
        $this->log = $log;
    }

    public function move(string $abs, string $root): ?string
    {
        $rel = FileHelper::relPath($abs, $root);
        $dest = $this->cfg['quarantine_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $destDir = dirname($dest);
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        if (@rename($abs, $dest)) { $this->log->warn("В карантин: $rel"); return $dest; }
        if (@copy($abs, $dest)) { unlink($abs); $this->log->warn("В карантин (copy): $rel"); return $dest; }
        $this->log->error("Не удалось поместить в карантин: $rel");
        return null;
    }

    public function restoreFromBackup(string $abs, string $root, Backup $backup): bool
    {
        $rel = FileHelper::relPath($abs, $root);
        $zipPath = $backup->latestFileBackup();
        if (!$zipPath) { $this->log->error("Нет файлового бэкапа: $rel"); return false; }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) { $this->log->error("Не удалось открыть бэкап $zipPath"); return false; }
        $entry = str_replace('\\', '/', $rel);
        $content = $zip->getFromName($entry);
        $zip->close();
        if ($content === false) { $this->log->warn("Файл отсутствует в бэкапе: $rel"); return false; }
        $dir = dirname($abs);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (file_put_contents($abs, $content) !== false) { $this->log->info("Восстановлен из бэкапа: $rel"); return true; }
        $this->log->error("Не удалось записать: $rel");
        return false;
    }

    public function listFiles(): array
    {
        $qDir = rtrim($this->cfg['quarantine_dir'], '/\\');
        $root = $this->cfg['site_root'];
        $out = [];
        if (!is_dir($qDir)) return $out;
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($qDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $f) {
            if (!$f->isFile()) continue;
            $abs = $f->getPathname();
            $id = rtrim(strtr(base64_encode($abs), '+/', '-_'), '=');
            $rel = FileHelper::relPath($abs, $qDir);
            $orig = FileHelper::relPath($qDir . '/' . $rel, $root);
            $out[$id] = [
                'abs' => $abs, 'rel' => $rel, 'orig' => $orig,
                'size' => filesize($abs), 'mtime' => filemtime($abs),
                'ext' => strtolower(pathinfo($abs, PATHINFO_EXTENSION)),
            ];
        }
        return $out;
    }

    public function countFiles(): int
    {
        $qDir = rtrim($this->cfg['quarantine_dir'], '/\\');
        if (!is_dir($qDir)) return 0;
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($qDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) { if ($f->isFile()) $count++; }
        return $count;
    }
}
