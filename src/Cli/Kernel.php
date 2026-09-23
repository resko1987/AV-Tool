<?php
declare(strict_types=1);
namespace AV\Cli;

use AV\Model\Config;
use AV\Model\Logger;
use AV\Model\Mailer;
use AV\Model\Backup;
use AV\Model\Quarantine;
use AV\Model\Integrity;
use AV\Model\License;
use AV\Model\Scanner;
use AV\Model\Updater;
use AV\Service\Permissions;

class Kernel
{
    const COLORS = [
        'red'    => "\033[31m",
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'cyan'   => "\033[36m",
        'reset'  => "\033[0m",
    ];

    public function run(array $argv): int
    {
        $CFG = Config::load();

        $log = new Logger($CFG['logs']['dir'], $CFG['logs']['file'], $CFG['logs']['max_size'], $CFG['logs']['max_files']);

        // Самоисправление прав на служебные каталоги/файлы
        Permissions::ensure($CFG);
        foreach (Permissions::actions() as $a) {
            $this->echo("Права исправлены: $a", 'yellow');
            $log->info("Permissions: $a");
        }

        $mail = new Mailer($CFG['mail'], $log);
        $backup = new Backup($CFG, $log, $mail);
        $quarantine = new Quarantine($CFG, $log);
        $integrity = new Integrity($CFG, $log);

        $args = $this->parseArgs($argv);
        $autoRestore = $CFG['auto_restore'] && !$args['no-restore'];

        // Лицензионный шлюз: все рабочие операции требуют действующей лицензии
        try {
            License::requireValid($CFG);
        } catch (\RuntimeException $e) {
            $this->echo($e->getMessage(), 'red');
            $log->error("License: " . $e->getMessage());
            return 2;
        }

        if ($args['baseline']) {
            $n = $integrity->buildBaseline();
            $this->echo("Baseline создан: $n файлов", 'green');
            return 0;
        }

        if ($args['update'] !== false) {
            $updater = new Updater($CFG, $log);
            try {
                // --update без тега: показать статус
                if ($args['update'] === '') {
                    $c = $updater->check();
                    $this->echo("Текущая версия: {$c['current']}", 'cyan');
                    if ($c['latest'] === null) {
                        $this->echo('Релизов/коммитов не найдено.', 'yellow');
                        return 0;
                    }
                    $this->echo('Последняя на GitHub: ' . $c['latest']['tag'] . ($c['latest']['name'] !== $c['latest']['tag'] ? " ({$c['latest']['name']})" : ''), 'cyan');
                    if ($c['updateAvailable']) {
                        $this->echo('Доступно обновление! Установить: php av.php --update=' . $c['latest']['tag'], 'yellow');
                    } else {
                        $this->echo('Установлена актуальная версия.', 'green');
                    }
                    return 0;
                }
                // --update=<tag>|latest: установить
                $tag = $args['update'] === 'latest'
                    ? (string)($updater->check()['latest']['tag'] ?? '')
                    : $args['update'];
                if ($tag === '') {
                    $this->echo('Не найдено ни одного релиза/коммита в GitHub.', 'red');
                    return 1;
                }
                $this->echo("Установка обновления $tag ...", 'cyan');
                $res = $updater->apply($tag);
                foreach ($res['steps'] as $s) $this->echo('  ' . $s);
                $this->echo('Обновление завершено: ' . $res['to'], 'green');
                return 0;
            } catch (\Throwable $e) {
                $this->echo('Ошибка обновления: ' . $e->getMessage(), 'red');
                $log->error('Update CLI: ' . $e->getMessage());
                return 1;
            }
        }

        if ($args['backup']) {
            $backup->run(true);
            return 0;
        }

        if (!$CFG['antivirus_enabled']) {
            $this->echo("Антивирус отключён.", 'yellow');
            return 0;
        }

        $scanner = new Scanner($CFG, $log, $mail, $backup, $quarantine, $autoRestore);

        if ($args['full-scan']) {
            $this->echo("Полное сканирование...", 'cyan');
            $files = $scanner->findAll();
            $threats = $scanner->scanFiles($files);
            $this->echo("Готово. Файлов: " . count($files) . ", угроз: $threats", $threats ? 'red' : 'green');
            return 0;
        }

        if ($args['scan-list'] !== null) {
            $listFile = $args['scan-list'];
            if (!is_file($listFile)) {
                $this->echo("Файл списка не найден: $listFile", 'red');
                return 1;
            }
            $files = file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->echo("Сканирование по списку: " . count($files) . " путей", 'cyan');
            $threats = $scanner->scanFiles($files);
            $this->echo("Готово. Угроз: $threats", $threats ? 'red' : 'green');
            return 0;
        }

        $minutes = (int) $CFG['scan_interval_minutes'];
        $this->echo("Сканирование изменённых за $minutes мин...", 'cyan');
        $files = $scanner->findChanged($minutes);
        $this->echo("Найдено: " . count($files), 'cyan');
        $threats = $scanner->scanFiles($files);
        $this->echo("Готово. Угроз: $threats", $threats ? 'red' : 'green');
        return 0;
    }

    private function parseArgs(array $argv): array
    {
        $opts = ['backup' => false, 'scan' => false, 'full-scan' => false, 'baseline' => false, 'no-restore' => false, 'scan-list' => null, 'update' => false];
        for ($i = 1; $i < count($argv); $i++) {
            $a = $argv[$i];
            if ($a === '--backup') $opts['backup'] = true;
            elseif ($a === '--scan') $opts['scan'] = true;
            elseif ($a === '--full-scan') $opts['full-scan'] = true;
            elseif ($a === '--baseline') $opts['baseline'] = true;
            elseif ($a === '--no-restore') $opts['no-restore'] = true;
            elseif ($a === '--update') $opts['update'] = '';            // статус
            elseif (strpos($a, '--update=') === 0) $opts['update'] = substr($a, strlen('--update='));
            elseif (strpos($a, '--scan-list=') === 0) $opts['scan-list'] = substr($a, strlen('--scan-list='));
        }
        return $opts;
    }

    private function echo(string $msg, string $color = ''): void
    {
        $out = $color && isset(self::COLORS[$color]) ? self::COLORS[$color] . $msg . self::COLORS['reset'] : $msg;
        fwrite(STDOUT, $out . PHP_EOL);
    }
}
