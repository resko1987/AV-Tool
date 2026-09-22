<?php
declare(strict_types=1);
namespace AV\Model;

class FileHelper
{
    public static function relPath(string $abs, string $root): string
    {
        $abs  = str_replace('\\', '/', $abs);
        $root = str_replace('\\', '/', rtrim($root, '/\\'));
        if (strpos($abs, $root) === 0) {
            $rel = ltrim(substr($abs, strlen($root)), '/');
            return $rel === '' ? '.' : $rel;
        }
        return $abs;
    }

    public static function isExcluded(string $rel, array $excludes): bool
    {
        if ($rel === '.') return false;
        foreach ($excludes as $ex) {
            $ex = trim($ex, '/\\');
            if ($ex === '' || $ex === '.') continue;
            if ($rel === $ex || strpos($rel, $ex . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    public static function isProtected(string $abs, array $cfg): bool
    {
        $abs = str_replace('\\', '/', $abs);
        $dirs = [
            $cfg['tool_dir'] ?? '',
            $cfg['backup_dir'] ?? '',
            $cfg['quarantine_dir'] ?? '',
            $cfg['data_dir'] ?? '',
            $cfg['logs_dir'] ?? '',
        ];
        foreach ($dirs as $d) {
            $d = str_replace('\\', '/', rtrim($d, '/\\'));
            if ($d !== '' && (strpos($abs, $d . '/') === 0 || $abs === $d)) {
                return true;
            }
        }
        return false;
    }

    public static function iterate(string $root, array $excludes, ?callable $cb): array
    {
        $found = [];
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($rii as $fileInfo) {
            if ($fileInfo->isDir()) continue;
            $abs = $fileInfo->getPathname();
            $rel = self::relPath($abs, $root);
            if (self::isExcluded($rel, $excludes)) continue;
            if ($cb) $cb($abs, $rel);
            $found[] = $abs;
        }
        return $found;
    }
}
