<?php
declare(strict_types=1);
namespace AV\Service;

class RateLimiter
{
    private string $file;
    private int $window;
    private int $max;

    public function __construct(string $file, int $window = 600, int $max = 5)
    {
        $this->file = $file;
        $this->window = $window;
        $this->max = $max;
    }

    public function isBlocked(): bool
    {
        $now = time();
        $attempts = $this->clean($this->read(), $now);
        $this->write($attempts);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return isset($attempts[$ip]) && count($attempts[$ip]) >= $this->max;
    }

    public function record(): void
    {
        $now = time();
        $attempts = $this->read();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!isset($attempts[$ip]) || !is_array($attempts[$ip])) $attempts[$ip] = [];
        $attempts[$ip][] = $now;
        $attempts[$ip] = array_values(array_filter($attempts[$ip], fn($t) => $t > $now - $this->window));
        $this->write($attempts);
    }

    public function getFails(): int
    {
        $now = time();
        $attempts = $this->read();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return isset($attempts[$ip]) ? count($attempts[$ip]) : 0;
    }

    private function read(): array
    {
        $a = is_file($this->file) ? json_decode((string)@file_get_contents($this->file), true) : null;
        return is_array($a) ? $a : [];
    }

    private function write(array $a): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        @file_put_contents($this->file, json_encode($a, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($this->file, 0640);
    }

    private function clean(array $a, int $now): array
    {
        $out = [];
        foreach ($a as $ip => $list) {
            $arr = is_array($list) ? $list : [];
            $arr = array_values(array_filter($arr, fn($t) => is_numeric($t) && (int)$t > $now - $this->window));
            if ($arr) $out[$ip] = $arr;
        }
        return $out;
    }
}
