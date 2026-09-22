<?php
declare(strict_types=1);
namespace AV\Model;

class Integrity
{
    private array $cfg;
    private Logger $log;
    private string $baselineFile;

    public function __construct(array $cfg, Logger $log)
    {
        $this->cfg = $cfg;
        $this->log = $log;
        if (!is_dir($cfg['data_dir'])) mkdir($cfg['data_dir'], 0755, true);
        $this->baselineFile = $cfg['data_dir'] . DIRECTORY_SEPARATOR . 'baseline.json';
    }

    public function buildBaseline(): int
    {
        $hashes = [];
        $excludes = array_merge($this->cfg['exclude_paths'], $this->cfg['integrity_exclude_paths'] ?? []);
        $scannable = $this->cfg['scannable_extensions'];
        $whitelist = array_merge($this->cfg['scan_exclude_files'] ?? [], Whitelist::load($this->cfg));
        FileHelper::iterate($this->cfg['site_root'], $excludes, function ($abs, $rel) use (&$hashes, $scannable, $whitelist) {
            if (FileHelper::isProtected($abs, $this->cfg)) return;
            if (Whitelist::match($rel, $whitelist)) return;
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            if (!in_array($ext, $scannable, true)) return;
            $hashes[$rel] = hash_file('sha256', $abs);
        });
        file_put_contents($this->baselineFile, json_encode($hashes, JSON_UNESCAPED_UNICODE));
        $count = count($hashes);
        $this->log->info("Baseline создан: $count файлов");
        return $count;
    }

    public function hasBaseline(): bool
    {
        return file_exists($this->baselineFile);
    }

    public function check(): array
    {
        $result = ['new' => [], 'modified' => [], 'deleted' => []];
        if (!file_exists($this->baselineFile)) return $result;
        $baseline = json_decode(file_get_contents($this->baselineFile), true) ?: [];
        $current = [];
        $excludes = array_merge($this->cfg['exclude_paths'], $this->cfg['integrity_exclude_paths'] ?? []);
        $scannable = $this->cfg['scannable_extensions'];
        $whitelist = array_merge($this->cfg['scan_exclude_files'] ?? [], Whitelist::load($this->cfg));
        FileHelper::iterate($this->cfg['site_root'], $excludes, function ($abs, $rel) use (&$current, $scannable, $whitelist) {
            if (FileHelper::isProtected($abs, $this->cfg)) return;
            if (Whitelist::match($rel, $whitelist)) return;
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            if (!in_array($ext, $scannable, true)) return;
            $current[$rel] = hash_file('sha256', $abs);
        });
        foreach ($current as $rel => $h) {
            if (!isset($baseline[$rel])) $result['new'][] = $rel;
            elseif ($baseline[$rel] !== $h) $result['modified'][] = $rel;
        }
        foreach ($baseline as $rel => $h) {
            if (!isset($current[$rel])) $result['deleted'][] = $rel;
        }
        return $result;
    }
}
