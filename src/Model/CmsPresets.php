<?php
declare(strict_types=1);
namespace AV\Model;

class CmsPresets
{
    /**
     * Пресеты исключений сканера для популярных CMS.
     * Пути относительные — от корня сайта (site_root).
     */
    public const PRESETS = [
        'wordpress' => [
            'label'   => 'WordPress',
            'folders' => [
                'wp-content/cache',
                'wp-content/upgrade',
                'wp-content/languages',
                'wp-content/backup-db',
                'wp-content/ai1wm-backups',
                'wp-snapshots',
            ],
            'files'   => [
                'wp-content/debug.log',
            ],
        ],
        'joomla' => [
            'label'   => 'Joomla',
            'folders' => [
                'cache',
                'tmp',
                'logs',
                'administrator/logs',
                'administrator/cache',
                'administrator/backups',
                'administrator/components/com_akeeba/backup',
            ],
            'files'   => [],
        ],
        'opencart' => [
            'label'   => 'OpenCart',
            'folders' => [
                'system/storage/cache',
                'system/storage/logs',
                'system/storage/session',
                'system/storage/backup',
                'storage/cache',
                'storage/logs',
                'storage/session',
                'storage/backup',
                'image/cache',
                'admin/backup',
            ],
            'files'   => [],
        ],
    ];

    public static function get(string $cms): ?array
    {
        return self::PRESETS[$cms] ?? null;
    }

    public static function presets(): array
    {
        return self::PRESETS;
    }

    /**
     * Все пути пресета (папки + файлы), нормализованные.
     */
    public static function paths(string $cms): array
    {
        $preset = self::get($cms);
        if (!$preset) return [];
        $out = [];
        foreach ($preset['folders'] as $p) {
            $p = ltrim(str_replace('\\', '/', trim((string)$p)), '/');
            if ($p !== '') $out[] = rtrim($p, '/');
        }
        foreach ($preset['files'] as $p) {
            $p = ltrim(str_replace('\\', '/', trim((string)$p)), '/');
            if ($p !== '') $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    /**
     * Применён ли пресет: все его пути присутствуют в scan_whitelist.json.
     */
    public static function isApplied(array $cfg, string $cms): bool
    {
        $paths = self::paths($cms);
        if (!$paths) return false;
        $whitelist = Whitelist::load($cfg);
        return count(array_diff($paths, $whitelist)) === 0;
    }

    public static function applied(array $cfg): array
    {
        $out = [];
        foreach (self::PRESETS as $key => $preset) {
            if (self::isApplied($cfg, $key)) $out[] = $key;
        }
        return $out;
    }

    /**
     * Применить пресет: добавить его пути в scan_whitelist.json.
     */
    public static function apply(array $cfg, string $cms): bool
    {
        $preset = self::get($cms);
        if (!$preset) return false;
        $added = Whitelist::add($cfg, self::paths($cms));
        return $added >= 0;
    }

    /**
     * Убрать пресет: удалить его пути из scan_whitelist.json.
     */
    public static function remove(array $cfg, string $cms): bool
    {
        $paths = self::paths($cms);
        if (!$paths) return false;
        $cur = Whitelist::load($cfg);
        $new = array_values(array_filter($cur, fn($p) => !in_array($p, $paths, true)));
        if (count($new) === count($cur) && count($cur) > 0) return true;
        if (count($new) === count($cur)) return false;
        return Whitelist::save($cfg, $new);
    }
}