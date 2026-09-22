<?php
declare(strict_types=1);
namespace AV;

if (!function_exists('AV\e')) {
    /**
     * Экранирование HTML.
     */
    function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('AV\ago')) {
    /**
     * Человекочитаемое «сколько времени назад».
     */
    function ago(?int $ts): string
    {
        if (!$ts) return 'никогда';
        $d = time() - $ts;
        if ($d < 90) return 'только что';
        if ($d < 3600) return round($d / 60) . ' мин назад';
        if ($d < 86400) return round($d / 3600) . ' ч назад';
        return round($d / 86400) . ' дн назад';
    }
}