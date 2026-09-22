<?php
declare(strict_types=1);
namespace AV\Model;

class Whitelist
{
    public static function file(array $cfg): string
    {
        return rtrim($cfg['data_dir'], '/\\') . DIRECTORY_SEPARATOR . 'scan_whitelist.json';
    }

    public static function load(array $cfg): array
    {
        $f = self::file($cfg);
        if (!is_file($f)) return [];
        $arr = json_decode((string)@file_get_contents($f), true);
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            $p = ltrim(str_replace('\\', '/', $p), '/');
            if ($p !== '') $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    public static function save(array $cfg, array $paths): bool
    {
        $f = self::file($cfg);
        $dir = dirname($f);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $clean = [];
        foreach ($paths as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            $p = ltrim(str_replace('\\', '/', $p), '/');
            if ($p !== '') $clean[] = $p;
        }
        $clean = array_values(array_unique($clean));
        sort($clean, SORT_NATURAL | SORT_FLAG_CASE);
        $ok = file_put_contents($f, json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
        if ($ok) @chmod($f, 0640);
        return $ok;
    }

    public static function add(array $cfg, array $newPaths): int
    {
        $cur = self::load($cfg);
        $set = array_flip($cur);
        $added = 0;
        foreach ($newPaths as $p) {
            $p = ltrim(str_replace('\\', '/', trim((string)$p)), '/');
            if ($p === '' || isset($set[$p])) continue;
            $set[$p] = true;
            $added++;
        }
        if ($added && !self::save($cfg, array_keys($set))) {
            return -1; // пути новые были, но запись на диск не удалась
        }
        return $added;
    }

    public static function remove(array $cfg, string $path): bool
    {
        $cur = self::load($cfg);
        $new = array_values(array_filter($cur, fn($p) => $p !== $path));
        if (count($new) === count($cur)) return false;
        return self::save($cfg, $new);
    }

    public static function isMask(string $path): bool
    {
        return strpos($path, '*') !== false || strpos($path, '?') !== false;
    }

    public static function match(string $rel, array $whitelist): bool
    {
        $relLower = strtolower(str_replace('\\', '/', $rel));
        foreach ($whitelist as $w) {
            $w = strtolower(trim(str_replace('\\', '/', $w), '/'));
            if ($w === '') continue;
            if (self::isMask($w)) {
                if (@fnmatch($w, $relLower)) return true;
                continue;
            }
            if ($relLower === $w) return true;
            if (substr($relLower, -strlen($w) - 1) === '/' . $w) return true;
            if (strpos($relLower, $w . '/') === 0) return true;
        }
        return false;
    }
}
