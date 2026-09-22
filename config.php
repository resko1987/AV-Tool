<?php
/**
 * config.php — конфигурация инструмента резервного копирования и антивируса.
 *
 * Все скрипты (av.php, restore.php, scan_recent.sh) читают настройки отсюда.
 * Функция av_config() возвращает массив параметров. Это сделано специально,
 * чтобы bash-скрипт мог извлекать значения через `php -r`.
 *
 * Пример извлечения значения в bash:
 *   php -r "require '/path/config.php'; \$c=av_config(); echo \$c['scan_interval_minutes'];"
 */

if (!function_exists('av_env')) {
    /**
     * av_env('KEY', 'default') — секретные значения из окружения или файла .env
     * рядом с config.php (строки вида KEY=VALUE, # — комментарий).
     * Сам файл .env не коммитится в git (см. .gitignore).
     */
    function av_env(string $key, string $default = ''): string
    {
        static $vars = null;
        if ($vars === null) {
            $vars = [];
            $envFile = __DIR__ . '/.env';
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
}

if (!function_exists('av_config')) {
    function av_config(): array
    {
        // -----------------------------------------------------------------
        // ПУТИ
        // -----------------------------------------------------------------
        // Корень сайта. По умолчанию — папка на уровень выше antivirus/
        // (предполагается, что antivirus/ лежит внутри корня сайта).
        $siteRoot = dirname(__DIR__); // .../site  (или измените вручную)

        // Папка с бэкапами (абсолютный путь или относительно корня сайта).
        $backupDir = $siteRoot . '/antivirus/backup';

        // Папка самого инструмента (здесь лежат av.php, config.php и т.д.).
        $toolDir = __DIR__;

        // -----------------------------------------------------------------
        // ИСКЛЮЧЕНИЯ
        // Пути ОТНОСИТЕЛЬНО КОРНЯ САЙТА, которые НЕ копируются в бэкап и НЕ
        // сканируются (в т.ч. не участвуют в baseline целостности).
        // Внимание: папки backup, antivirus и сам антивирус должны быть исключены
        // обязательно, иначе вы будете бэкапить бэкапы и сканировать себя.
        // -----------------------------------------------------------------
        $excludePaths = [
            'backup',
            'antivirus',
            'cache',
            'tmp',
            'temp',
            'logs',
            'vendor',        // раскомментируйте, если используете Composer
            '.git',          // раскомментируйте, если в корне есть .git
        ];

        // -----------------------------------------------------------------
        // ИСКЛЮЧЕНИЯ ТОЛЬКО ИЗ КОНТРОЛЯ ЦЕЛОСТНОСТИ (baseline), НЕ ИЗ БЭКАПА
        // И НЕ ИЗ СКАНИРОВАНИЯ. Сюда: шаблоны/контент, редактируемые через CMS
        // (иначе каждое редактирование = «ИЗМЕНЁН» и письма). Файлы по-прежнему
        // сканируются антивирусом и попадают в бэкап.
        // -----------------------------------------------------------------
        $integrityExcludePaths = [
            'templates',
        ];

        // -----------------------------------------------------------------
        // БАЗА ДАННЫХ (для дампа и для хранения хешей целостности)
        // Секреты задаются в antivirus/.env:
        //   AV_DB_HOST=localhost
        //   AV_DB_PORT=3306
        //   AV_DB_USER=...
        //   AV_DB_PASSWORD=...
        //   AV_DB_NAME=...
        // -----------------------------------------------------------------
        $db = [
            'driver'   => 'mysql',      // поддерживается только mysql
            'host'     => av_env('AV_DB_HOST', 'localhost'),
            'port'     => (int)av_env('AV_DB_PORT', '3306'),
            'user'     => av_env('AV_DB_USER', ''),
            'password' => av_env('AV_DB_PASSWORD', ''),
            'name'     => av_env('AV_DB_NAME', ''),
            // Если mysqldump недоступен (shared hosting), дамп делается через PDO.
            'mysqldump' => '/usr/bin/mysqldump', // путь к бинарю; если нет — оставьте '', будет PDO
            // Клиент mysql для восстановления БД из дампа (restore.php).
            'mysql'     => '/usr/bin/mysql',     // путь к бинарю; если нет — оставьте '', будет PDO
        ];

        // -----------------------------------------------------------------
        // РОТАЦИЯ БЭКАПОВ
        // -----------------------------------------------------------------
        $keepDaily   = 7;  // хранить последние N ежедневных копий (всегда)
        $keepWeekly  = 4;  // хранить последние N воскресных копий (помечены как weekly)
        $keepMonthly = 3;  // хранить последние N копий за 1-е число месяца (monthly)

        // -----------------------------------------------------------------
        // АНТИВИРУС
        // -----------------------------------------------------------------
        $antivirusEnabled = true;

        // Интервал (в минутах) для режима "сканировать изменённые за N минут"
        // при обычном запуске без аргументов.
        $scanIntervalMinutes = 60;

        // Автоматически восстанавливать заражённый файл из последнего бэкапа.
        $autoRestore = true;

        // Опасные паттерны (регулярные выражения PCRE). Поиск ведётся по
        // содержимому файла. Будьте осторожны: некоторые паттерны могут
        // срабатывать на легитимный код (false positives). Настраивайте под проект.
        // Контекст для «контекстных» паттернов (Wordfence-подход): вызов опасной
        // функции становится угрозой ТОЛЬКО если в аргументе есть суперглобал
        // ($_GET/$_POST/...) или кодировщик (base64_decode/gzinflate/\xNN и т.п.).
        $dangerCallContext = '/(?:base64_decode|gzinflate|gzuncompress|str_rot13|rawurldecode|urldecode|hex2bin|pack|chr)\s*\(|\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)\b|\$\{?\s*[\'"]?_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)\b|\\\\x[0-9a-fA-F]{2}/i';

        // Для hex_string \xNN сам по себе не доказательство (легитимные hex-таблицы
        // криптолибраций с pack()/chr() для ASN.1) — нужен явный кодировщик payload
        // или суперглобал рядом (без pack/chr/urldecode, слишком частых в либах).
        $dangerHexContext = '/(?:base64_decode|gzinflate|gzuncompress|str_rot13|hex2bin)\s*\(|\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)\b|\$\{?\s*[\'"]?_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)\b/i';

        $dangerousPatterns = [
            // eval/gzinflate/str_rot13: только если сразу следует base64_decode
            // или суперглобал — иначе это легитимное использование (zlib, архивы).
            'eval('            => ['re' => '/\beval\s*\(\s*(?:base64_decode|gzinflate|str_rot13|\$_)/i'],
            'gzinflate('       => ['re' => '/gzinflate\s*\(\s*(?:base64_decode|gzuncompress|\$_)/i'],
            'shell_exec('      => ['re' => '/\bshell_exec\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|base64_decode|gzinflate)/i'],
            'system('          => ['re' => '/\bsystem\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|base64_decode|gzinflate|hex2bin|chr\s*\()/i'],
            'passthru('        => ['re' => '/\bpassthru\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|base64_decode|gzinflate|hex2bin|chr\s*\()/i'],
            // exec(): исключаем вызовы методов ->exec() и ::exec(); ищем только
            // с аргументом-переменной или суперглобалом (детерминированные строки
            // типовой код WP/Composer не берут).
            'exec('            => ['re' => '/(?<!->)(?<!::)\bexec\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|base64_decode|gzinflate|hex2bin|chr\s*\()/i'],
            'proc_open('       => ['re' => '/\bproc_open\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|["\']sh["\']|["\']bash["\']|["\']\/bin\/)/i'],
            'popen('           => ['re' => '/\bpopen\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|["\']sh["\']|["\']bash["\']|["\']\/bin\/)/i'],
            'pcntl_exec('      => ['re' => '/\bpcntl_exec\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|["\']\/bin\/)/i'],
            'assert('          => ['re' => '/\bassert\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE)|base64_decode|gzinflate|str_rot13|\$\w+)\s*\)/i'],
            'create_function(' => ['re' => '/\bcreate_function\s*\(\s*["\']["\']\s*,\s*["\'](?:[\x20-\x7E]{30,})["\']\s*\)/i'],
            'preg_replace_e'   => '/preg_replace\s*\(\s*[\'"]\s*\/[^\/]*\/e/i',
            'str_rot13('       => ['re' => '/str_rot13\s*\(\s*(?:base64_decode|\$_)/i'],
            // remote_include: только протоколы http/ftp:// сразу после require/include
            'remote_include'   => '/\b(?:require|include|require_once|include_once)\s*\(?\s*[\'"](?:https?:|ftp:)/i',
            'obfuscated_var'   => '/\$\s*\{\s*[\'"]\s*\\\\?\s*\w/i',
            // hex_string: много \xNN подряд И сразу после — присваивание в кавычках
            // или eval/base64/gzinflate — иначе это hex-таблицы криптолибраций.
            'hex_string'       => ['re' => '/(?:\\\\x[0-9a-fA-F]{2}){12,}', 'context' => $dangerHexContext],
        ];

        // Типы файлов, которые сканируются на наличие вредоносного кода.
        // Сканирование бинарных файлов (изображения, архивы) бессмысленно и
        // может давать ложные срабатывания — они пропускаются.
        $scannableExtensions = [
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'inc',
            'html', 'htm', 'js', 'jsx', 'ts', 'tpl', 'twig', 'sql', 'css',
        ];

        // Максимальный размер файла (в байтах) для сканирования содержимого.
        // Очень большие файлы пропускаются (только проверка целостности по хешу).
        $maxScanFileSize = 5 * 1024 * 1024; // 5 МБ

        // Размер «хвоста» JS-файла (в байтах), который сканируется на
        // вредоносные инъекции. Вирусный JS, как правило, дописывают в конец
        // легитимного бандла (webpack/jquery/React), поэтому весь файл
        // не проверяем — иначе слишком много ложных срабатываний на
        // нормальной минификации/обфускации бандлов.
        $jsTailSize = 32768; // 32 КБ

        // Белый список сканирования: файлы (пути ОТНОСИТЕЛЬНО КОРНЯ САЙТА),
        // которые НЕ проверяются на вредоносный код. Используйте для легитимных
        // файлов, дающих ложные срабатывания (библиотеки, отладчики и т.п.).
        // Поддерживаются маски с * и ?. Управление списком — через веб
        // (whitelist.php, вкладка «Исключения»); здесь можно оставить пустым.
        // Бэкап и контроль целостности на исключённые файлы продолжают действовать.
        $scanExcludeFiles = [];

        // -----------------------------------------------------------------
        // EMAIL / УВЕДОМЛЕНИЯ
        // Базовые значения. Переопределяются через веб (вкладка «Настройки»),
        // там же включается отправка и задаётся адрес получателя.
        // -----------------------------------------------------------------
        $mail = [
            'enabled'  => false,                 // включить отправку уведомлений
            'to'       => '',                    // куда слать (пусто = не слать)
            'from'     => 'av@' . gethostname(),
            'subject_prefix' => '[AV] ',
            // Если smtp === null — используется встроенная mail().
            // Иначе отправка через SMTP (fsockopen).
            'smtp'     => null,
            // 'smtp' => [
            //     'host' => 'smtp.example.com',
            //     'port' => 587,
            //     'user' => 'user@example.com',
            //     'pass' => 'password',
            //     'secure' => 'tls', // tls | ssl | ''
            // ],
        ];

        // Веб-настройки (вкладка «Настройки») хранятся в data/mail_settings.json
        // и имеют приоритет над значениями выше.
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

        // -----------------------------------------------------------------
        // WEB-ДОСТУП (авторизация)
        // Логин/пароль задаются в antivirus/.env (вне git, вне веб-доступа):
        //   AV_WEB_USER=REMOVED_EMAIL
        //   AV_WEB_PASS=пароль
        // Пустой user = web-доступ ЗАПРЕЩЁН (работает только CLI).
        // -----------------------------------------------------------------
        $web = [
            'user' => av_env('AV_WEB_USER', ''),
            'pass' => av_env('AV_WEB_PASS', ''),
            'allowed_ips' => av_env('AV_WEB_ALLOWED_IPS', '') !== ''
                ? array_values(array_filter(array_map('trim', explode(',', av_env('AV_WEB_ALLOWED_IPS', '')))))
                : [],
        ];

        // -----------------------------------------------------------------
        // ЛОГИ
        // -----------------------------------------------------------------
        $logs = [
            'dir' => $toolDir . '/logs',
            'file' => 'av.log',
            'max_size' => 10 * 1024 * 1024, // ротация при достижении 10 МБ
            'max_files' => 5,               // сколько старых логов хранить
        ];

        // -----------------------------------------------------------------
        // ПРОЧЕЕ
        // -----------------------------------------------------------------
        $quarantineDir = $toolDir . '/quarantine';
        $dataDir       = $toolDir . '/data'; // базовая линия хешей целостности

        // Минимально свободного места (в байтах) на диске бэкапов,
        // иначе бэкап не создаётся (предупреждение по email).
        $minFreeSpace = 500 * 1024 * 1024; // 500 МБ

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
            'js_tail_size'         => $jsTailSize,
            'mail'                 => $mail,
            'web'                  => $web,
            'logs'                 => $logs,
            'min_free_space'       => $minFreeSpace,
        ];
    }
}
