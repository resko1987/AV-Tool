<?php
declare(strict_types=1);
namespace AV\Service;

/**
 * Permissions — самоисправление прав доступа к служебным файлам и папкам.
 *
 * Вызывается на каждом запуске (web + CLI). Идемпотентен и дёшев:
 * сначала читает текущие права (fileperms), chmod только если отличаются.
 *
 * Целевые права:
 *   - Служебные каталоги (data, logs, quarantine, backup) → 0750
 *     (владелец www-root = пользователь php-fpm, группа только на чтение)
 *   - Чувствительные JSON в data/ (whitelist, mail settings, baseline,
 *     login attempts) → 0640 (чтение только владельцу и группе)
 *   - Лог av.log → 0640
 */
class Permissions
{
    private static bool $done = false;

    /** @var string[] список выполненных исправлений (для лога) */
    private static array $actions = [];

    public static function ensure(array $cfg): void
    {
        if (self::$done) return;
        self::$done = true;

        // --- Каталоги ---
        $dirs = array_filter([
            $cfg['data_dir'] ?? '',
            rtrim($cfg['data_dir'] ?? '', '/\\') . '/update_backup',
            $cfg['logs']['dir'] ?? '',
            $cfg['quarantine_dir'] ?? '',
            $cfg['backup_dir'] ?? '',
        ]);
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
                self::$done = false; // новый каталог создали, но проверим права повторно
            }
            self::fixDir($dir);
        }

        // --- Чувствительные файлы в data/ ---
        $dataDir = rtrim($cfg['data_dir'] ?? '', '/\\');
        if ($dataDir !== '') {
            foreach (['scan_whitelist.json', 'mail_settings.json', 'baseline.json', 'login_attempts.json', 'license.dat', 'runtime.st', 'nginx.conf'] as $f) {
                self::fixFile($dataDir . '/' . $f, 0640);
            }
        }

        // --- Лог ---
        $logDir = rtrim($cfg['logs']['dir'] ?? '', '/\\');
        $logFile = $cfg['logs']['file'] ?? '';
        if ($logDir !== '' && $logFile !== '') {
            self::fixFile($logDir . '/' . $logFile, 0640);
        }
    }

    /**
     * Выполненные исправления (после вызова ensure()).
     * @return string[]
     */
    public static function actions(): array
    {
        return self::$actions;
    }

    private static function fixDir(string $dir): void
    {
        clearstatcache(true, $dir);
        $perms = @fileperms($dir);
        if ($perms === false) return;
        if (($perms & 0777) !== 0750 && @chmod($dir, 0750)) {
            self::$actions[] = "chmod 750 каталог: $dir (было " . substr(sprintf('%o', $perms), -4) . ")";
        }
    }

    private static function fixFile(string $file, int $mode): void
    {
        if (!is_file($file)) return;
        clearstatcache(true, $file);
        $perms = @fileperms($file);
        if ($perms === false) return;
        if (($perms & 0777) !== $mode) {
            if (@chmod($file, $mode)) {
                self::$actions[] = "chmod " . substr(sprintf('%o', $mode), -3) . " файл: $file (было " . substr(sprintf('%o', $perms), -4) . ")";
            } else {
                // chmod не удался: файл принадлежит другому пользователю.
                // Для чувствительных файлов (nginx.conf, .env-подобных) это
                // важно видеть в логе — иначе файл может остаться читаемым из веба.
                self::$actions[] = "ОШИБКА chmod " . substr(sprintf('%o', $mode), -3) . " файл: $file (владелец не позволяет смену прав)";
            }
        }
    }
}