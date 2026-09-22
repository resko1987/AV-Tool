<?php
declare(strict_types=1);
namespace AV\Model;

class Logger
{
    private string $file;
    private int $maxSize;
    private int $maxFiles;

    public function __construct(string $dir, string $file, int $maxSize, int $maxFiles)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $file;
        $this->maxSize = $maxSize;
        $this->maxFiles = $maxFiles;
    }

    public function log(string $level, string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        $line = "[$ts] [$level] $message" . PHP_EOL;
        $this->rotate();
        file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    public function info(string $m): void  { $this->log('INFO', $m); }
    public function warn(string $m): void  { $this->log('WARN', $m); }
    public function error(string $m): void { $this->log('ERROR', $m); }
    public function crit(string $m): void  { $this->log('CRIT', $m); }

    private function rotate(): void
    {
        if (!file_exists($this->file) || filesize($this->file) < $this->maxSize) {
            return;
        }
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $old = $this->file . '.' . $i;
            $newer = $this->file . '.' . ($i - 1);
            if (file_exists($newer)) {
                rename($newer, $old);
            }
        }
        rename($this->file, $this->file . '.1');
    }
}
