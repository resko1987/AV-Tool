<?php
declare(strict_types=1);
namespace AV\Model;

class Config
{
    private static ?array $instance = null;

    public static function load(): array
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        self::$instance = self::build();
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function env(string $key, string $default = ''): string
    {
        static $vars = null;
        if ($vars === null) {
            $vars = [];
            $envFile = dirname(__DIR__, 2) . '/.env';
            if (is_file($envFile) && is_readable($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') continue;
                    $pos = strpos($line, '=');
                    if ($pos === false) continue;
                    $k = trim(substr($line, 0, $pos));
                    $v = trim(substr($line, $pos + 1));
                    if (strlen($v) >= 2 && (($v[0] === '"' && $v[strlen($v) - 1] === '"') || ($v[0] === "'" && $v[strlen($v) - 1] === "'"))) {
                        $v = substr($v, 1, -1);
                    }
                    if ($k !== '') $vars[$k] = $v;
                }
            }
        }
        $real = getenv($key);
        if ($real !== false && $real !== '') return $real;
        return (string)($vars[$key] ?? $default);
    }

    private static function build(): array
    {
        $siteRoot = dirname(__DIR__, 3);
        $backupDir = dirname(__DIR__, 2) . '/backup';
        $toolDir = dirname(__DIR__, 2);

        $excludePaths = [
            'backup', 'antivirus', 'antivirus_old', 'cache', 'tmp', 'temp', 'logs', 'vendor', '.git',
        ];

        $integrityExcludePaths = ['templates'];

        $db = [
            'driver'   => 'mysql',
            'host'     => self::env('AV_DB_HOST', 'localhost'),
            'port'     => (int)self::env('AV_DB_PORT', '3306'),
            'user'     => self::env('AV_DB_USER', ''),
            'password' => self::env('AV_DB_PASSWORD', ''),
            'name'     => self::env('AV_DB_NAME', ''),
            'mysqldump' => '/usr/bin/mysqldump',
            'mysql'     => '/usr/bin/mysql',
        ];

        $keepDaily = 7;
        $keepWeekly = 4;
        $keepMonthly = 3;

        $antivirusEnabled = true;
        $scanIntervalMinutes = 60;
        $autoRestore = true;

        // Контекст для «контекстных» паттернов (Wordfence-подход): вызов опасной
        // функции становится угрозой ТОЛЬКО если в аргументе есть суперглобал
        // ($_GET/$_POST/...) или кодировщик (base64_decode/gzinflate/\xNN и т.п.).
        $dangerCallContext = '/(?:base64_decode|gzinflate|gzuncompress|str_rot13|rawurldecode|urldecode|hex2bin|pack|chr)\s*\(|\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)\b|\$\{?\s*[\'"]?_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)\b|\\\\x[0-9a-fA-F]{2}/i';

        // Для hex_string \xNN сам по себе не доказательство (легитимные hex-таблицы
        // криптолибраций с pack()/chr() для ASN.1) — нужен явный кодировщик payload
        // или суперглобал рядом (без pack/chr/urldecode, слишком частых в либах).
        $dangerHexContext = '/(?:base64_decode|gzinflate|gzuncompress|str_rot13|hex2bin)\s*\(|\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)\b|\$\{?\s*[\'"]?_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)\b/i';

        $dangerousPatterns = [
            'eval('            => ['re' => '/\beval\s*\((?!\s*\))/i', 'context' => $dangerCallContext],
            'gzinflate('       => ['re' => '/gzinflate\s*\(/i', 'context' => $dangerCallContext],
            'shell_exec('      => ['re' => '/shell_exec\s*\(/i', 'context' => $dangerCallContext],
            'system('          => ['re' => '/\bsystem\s*\(/i', 'context' => $dangerCallContext],
            'passthru('        => ['re' => '/passthru\s*\(/i', 'context' => $dangerCallContext],
            'exec('            => ['re' => '/(?<!->)(?<!::)\bexec\s*\(/i', 'context' => $dangerCallContext],
            'proc_open('       => ['re' => '/proc_open\s*\(/i', 'context' => $dangerCallContext],
            'popen('           => ['re' => '/popen\s*\(/i', 'context' => $dangerCallContext],
            'pcntl_exec('      => ['re' => '/pcntl_exec\s*\(/i', 'context' => $dangerCallContext],
            'assert('          => ['re' => '/\bassert\s*\(/i', 'context' => $dangerCallContext],
            'create_function(' => ['re' => '/create_function\s*\(/i', 'context' => $dangerCallContext],
            'preg_replace_e'   => '/preg_replace\s*\(\s*[\'"]\/.*\/e/i',
            'str_rot13('       => ['re' => '/str_rot13\s*\(/i', 'context' => $dangerCallContext],
            'remote_include'   => '/^(?:(?:require|include|require_once|include_once)(?:\s*\(?\s*[\'"])(?:https?:|ftp:))/im',
            'obfuscated_var'   => '/\$\s*\{\s*[\'"]\s*\\\\?\s*\w/i',
            'hex_string'       => ['re' => '/(?:\\\\x[0-9a-fA-F]{2}){8,}/', 'context' => $dangerHexContext],
        ];

        $scannableExtensions = [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'inc',
            'html', 'htm', 'js', 'jsx', 'ts', 'tpl', 'twig', 'txt', 'sql', 'css',
        ];

        $maxScanFileSize = 5 * 1024 * 1024;
        $scanExcludeFiles = [];

        $mail = [
            'enabled'  => false,
            'to'       => '',
            'from'     => 'av@' . gethostname(),
            'subject_prefix' => '[AV] ',
            'smtp'     => null,
        ];

        $mailSettingsFile = $toolDir . '/data/mail_settings.json';
        if (is_file($mailSettingsFile)) {
            $webMail = json_decode((string)@file_get_contents($mailSettingsFile), true);
            if (is_array($webMail)) {
                if (isset($webMail['enabled'])) $mail['enabled'] = (bool)$webMail['enabled'];
                if (isset($webMail['to']))      $mail['to'] = (string)$webMail['to'];
                if (isset($webMail['from']))    $mail['from'] = (string)$webMail['from'];
                if (isset($webMail['smtp']) && is_array($webMail['smtp'])) $mail['smtp'] = $webMail['smtp'];
            }
        }

        $web = [
            'user' => self::env('AV_WEB_USER', ''),
            'pass' => self::env('AV_WEB_PASS', ''),
            'allowed_ips' => self::env('AV_WEB_ALLOWED_IPS', '') !== ''
                ? array_values(array_filter(array_map('trim', explode(',', self::env('AV_WEB_ALLOWED_IPS', '')))))
                : [],
        ];

        // Домен для привязки лицензионного ключа (CLI-запуски не знают HTTP_HOST)
        $licenseDomain = self::env('AV_LICENSE_DOMAIN', '');

        $logs = [
            'dir' => $toolDir . '/logs',
            'file' => 'av.log',
            'max_size' => 10 * 1024 * 1024,
            'max_files' => 5,
        ];

        $quarantineDir = $toolDir . '/quarantine';
        $dataDir       = $toolDir . '/data';
        $minFreeSpace  = 500 * 1024 * 1024;

        return [
            'site_root'            => rtrim($siteRoot, '/\\'),
            'backup_dir'           => rtrim($backupDir, '/\\'),
            'tool_dir'             => rtrim($toolDir, '/\\'),
            'quarantine_dir'       => rtrim($quarantineDir, '/\\'),
            'data_dir'             => rtrim($dataDir, '/\\'),
            'exclude_paths'        => $excludePaths,
            'integrity_exclude_paths' => $integrityExcludePaths,
            'db'                   => $db,
            'keep_daily'           => $keepDaily,
            'keep_weekly'          => $keepWeekly,
            'keep_monthly'         => $keepMonthly,
            'antivirus_enabled'    => $antivirusEnabled,
            'scan_interval_minutes'=> $scanIntervalMinutes,
            'auto_restore'         => $autoRestore,
            'dangerous_patterns'   => $dangerousPatterns,
            'scannable_extensions' => $scannableExtensions,
            'scan_exclude_files'   => $scanExcludeFiles,
            'max_scan_file_size'   => $maxScanFileSize,
            'mail'                 => $mail,
            'web'                  => $web,
            'license_domain'       => $licenseDomain,
            'logs'                 => $logs,
            'min_free_space'       => $minFreeSpace,
        ];
    }
}
