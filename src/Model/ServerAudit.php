<?php
declare(strict_types=1);
namespace AV\Model;

/**
 * ServerAudit — аудит серверной конфигурации и файловой системы сайта.
 *
 * Все проверки выполняются чистым PHP, без shell-вызовов (shell_exec/system):
 * это не создаёт новых точек входа и работает под тем же пользователем, что и
 * сайт (php-fpm), т.е. отражает реальную картину доступов.
 *
 * Разделы:
 *   - nginxInfo()   — разбор конфигурации nginx: /etc/nginx/* либо ручная
 *                     копия vhost-файла в antivirus/data/nginx.conf;
 *   - envExposure() — HTTP-пробы доступности чувствительных файлов из веба
 *                     (.env, baseline.json, .git/config и т.п.);
 *   - filesystem()  — права (каталоги 755 / PHP-файлы 644, без записи группе
 *                     и миру) и владельцы (root и «чужие» uid помечаются).
 */
class ServerAudit
{
    /** Расширения, которые считаем PHP-кодом основной системы. */
    private const PHP_EXT = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'inc'];

    /** Права на PHP-файлы, не вызывающие вопросов (644 и строже). */
    private const OK_FILE_PERMS = [0644, 0640, 0600, 0550, 0444, 0440, 0400];

    /** Чувствительные файлы: .env* + ключи/дампы. */
    private const SENSITIVE_EXT = ['pem', 'key', 'sql'];

    /** Сколько строк каждой проблемы показывать в отчёте. */
    private const LIST_LIMIT = 200;

    /**
     * Имена файлов, заведомо не являющихся секретами, несмотря на расширение
     * (публичные CA-бандлы curl/guzzle — бывают и больше лимита чтения).
     */
    private const FALSE_POSITIVE_NAMES = [
        'cacert.pem', 'cacerts.pem', 'ca-bundle.pem', 'ca_bundle.pem',
    ];

    /**
     * Сегменты пути, в которых .sql/.pem/.key — штатные шаблоны/фикстуры,
     * а не секреты: схемы БД плагинов (LiteSpeed data_structure),
     * тестовые фикстуры вендоров (google/auth и т.п.).
     */
    private const FALSE_POSITIVE_DIRS = [
        'data_structure', 'tests', 'test', 'fixtures',
    ];

    private array $cfg;
    private Logger $log;

    public function __construct(array $cfg, Logger $log)
    {
        $this->cfg = $cfg;
        $this->log = $log;
    }

    /**
     * Полный аудит. Возвращает структуру для шаблона audit_result.
     */
    public function run(): array
    {
        $nginx = $this->nginxInfo();
        $env = $this->envExposure();
        $fs = $this->filesystem();

        $err = $fs['level_counts']['err'];
        $warn = $fs['level_counts']['warn'];

        foreach ($env['probes'] as $p) {
            if ($p['status'] === 'err') $err++;
            elseif ($p['status'] === 'warn') $warn++;
        }
        foreach ($nginx['servers'] as $s) {
            foreach ($s['checks'] as $c) {
                if ($c['level'] === 'err') $err++;
                elseif ($c['level'] === 'warn') $warn++;
            }
        }

        $this->log->info("Аудит сервера: ошибок $err, предупреждений $warn");

        return [
            'nginx' => $nginx,
            'env' => $env,
            'fs' => $fs,
            'err' => $err,
            'warn' => $warn,
            'ts' => time(),
        ];
    }

    /**
     * Исправить права на чувствительный файл из отчёта: chmod 0640.
     * Путь строго сверяется с site_root (защита от подмены через GET).
     *
     * @return array{ok:bool,msg:string}
     */
    public function fixSensitive(string $relPath): array
    {
        $rel = str_replace('\\', '/', $relPath);
        // только относительные пути без «..» и абсолютных
        if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) {
            return ['ok' => false, 'msg' => 'Некорректный путь.'];
        }
        $abs = $this->cfg['site_root'] . '/' . $rel;
        $real = @realpath($abs);
        $rootReal = @realpath($this->cfg['site_root']);
        if ($real === false || $rootReal === false || strpos($real, $rootReal . DIRECTORY_SEPARATOR) !== 0) {
            return ['ok' => false, 'msg' => 'Файл вне корня сайта.'];
        }

        clearstatcache(true, $real);
        $perms = @fileperms($real);
        if ($perms === false) {
            return ['ok' => false, 'msg' => 'Файл не найден или недоступен.'];
        }

        // повторно убеждаемся, что файл всё ещё «чувствительный» по критериям аудита
        // (единая проверка исключает и ложные срабатывания — cacert.pem и т.п.)
        $base = basename($rel);
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!$this->isRealSensitive($rel, $base, $ext)) {
            return ['ok' => false, 'msg' => 'Файл не относится к чувствительным.'];
        }

        if (!@chmod($real, 0640)) {
            return ['ok' => false, 'msg' => 'chmod не выполнен: у PHP нет прав на смену (файл принадлежит другому пользователю?).'];
        }

        $was = substr(sprintf('%o', $perms), -3);
        $this->log->info("Аудит: права на чувствительный файл исправлены: $rel (было $was → 640)");
        return ['ok' => true, 'msg' => 'Права установлены: ' . $was . ' → 640.'];
    }

    // ------------------------------------------------------------------
    // NGINX
    // ------------------------------------------------------------------

    /**
     * Читает конфиги nginx (или ручную копию в data/nginx.conf) и разбирает
     * server-блоки, относящиеся к этому сайту.
     */
    public function nginxInfo(): array
    {
        $out = ['available' => false, 'sources' => [], 'servers' => [], 'hint' => ''];

        $candidates = [$this->cfg['data_dir'] . '/nginx.conf'];
        foreach (glob('/etc/nginx/sites-enabled/*') ?: [] as $f) $candidates[] = $f;
        foreach (glob('/etc/nginx/conf.d/*.conf') ?: [] as $f) $candidates[] = $f;
        $candidates[] = '/etc/nginx/nginx.conf';

        $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        $root = $this->cfg['site_root'];
        $anyServer = false;

        foreach ($candidates as $file) {
            if (!is_file($file) || !is_readable($file)) continue;
            $raw = @file_get_contents($file);
            if ($raw === false || trim($raw) === '') continue;
            $out['available'] = true;
            $out['sources'][] = $file;

            $clean = preg_replace('/(^|\s)#[^\n]*/', '', $raw) ?? $raw;
            foreach ($this->extractServerBlocks($clean) as $block) {
                $anyServer = true;
                $isMine = $this->serverMatchesSite($block, $host, $root);
                $out['servers'][] = [
                    'path' => $file,
                    'raw' => $block,
                    'match' => $isMine,
                    'checks' => $isMine ? $this->serverChecks($block) : [],
                ];
            }
        }

        if (!$out['available']) {
            $out['hint'] = 'Файлы nginx недоступны для чтения (типично для shared-хостинга). '
                . 'Скопируйте конфиг вашего vhost в antivirus/data/nginx.conf — аудит разберёт его автоматически.';
            return $out;
        }
        if (!$anyServer) {
            $out['hint'] = 'server-блоки в доступных конфигах не найдены. Скопируйте vhost вашего сайта '
                . 'в antivirus/data/nginx.conf — аудит разберёт его автоматически.';
        }
        return $out;
    }

    /**
     * Выделяет все «server { … }» из текста конфига (наивный подсчёт скобок).
     */
    private function extractServerBlocks(string $text): array
    {
        $blocks = [];
        if (!preg_match_all('/\bserver\s*\{/', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $blocks;
        }
        foreach ($m[0] as $hit) {
            $open = (int)strrpos($hit[0], '{') + (int)$hit[1];
            $b = $this->balanced($text, $open);
            if ($b !== null) $blocks[] = $b;
        }
        return $blocks;
    }

    private function balanced(string $text, int $open): ?string
    {
        $depth = 0;
        $len = strlen($text);
        for ($i = $open; $i < $len; $i++) {
            $c = $text[$i];
            if ($c === '{') $depth++;
            elseif ($c === '}') {
                $depth--;
                if ($depth === 0) return substr($text, $open, $i - $open + 1);
            }
        }
        return null;
    }

    private function serverMatchesSite(string $block, string $host, string $siteRoot): bool
    {
        $realRoot = @realpath($siteRoot);
        if (preg_match_all('/^\s*root\s+([^;]+);/m', $block, $m)) {
            foreach ($m[1] as $r) {
                $r = trim($r, " \t\"'");
                if ($r === '') continue;
                $rp = @realpath($r);
                // точное совпадение, либо vhost-корень лежит внутри корня сайта
                if ($r === $siteRoot || ($rp !== false && $rp === $realRoot)) return true;
                if ($rp !== false && $realRoot !== false && strpos($rp, $realRoot . DIRECTORY_SEPARATOR) === 0) return true;
            }
        }
        if ($host !== '' && preg_match('/^\s*server_name\s+([^;]+);/m', $block, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $name) {
                $name = trim($name, " \t\"'");
                if ($name !== '' && strcasecmp($name, $host) === 0) return true;
            }
        }
        return false;
    }

    /**
     * Все location-блоки server-блока: [заголовок с '{', тело от '{'].
     */
    private function extractLocationBlocks(string $block): array
    {
        $out = [];
        if (!preg_match_all('/\blocation\s+[^\{]*\{/', $block, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($m[0] as $hit) {
            $open = (int)strrpos($hit[0], '{') + (int)$hit[1];
            $b = $this->balanced($block, $open);
            if ($b !== null) $out[] = [rtrim($hit[0]), $b];
        }
        return $out;
    }

    /**
     * Набор проверок одного server-блока.
     *
     * @return array<array{level:string,title:string,detail:string}>
     */
    private function serverChecks(string $block): array
    {
        $rows = [];
        $locs = $this->extractLocationBlocks($block);

        $listen = [];
        if (preg_match_all('/^\s*listen\s+([^;]+);/m', $block, $m)) {
            foreach ($m[1] as $l) $listen[] = trim($l);
        }
        $hasSSL = false;
        foreach ($listen as $l) {
            if (stripos($l, 'ssl') !== false) $hasSSL = true;
        }

        // --- ssl_protocols ---
        if (preg_match('/^\s*ssl_protocols\s+([^;]+);/m', $block, $m)) {
            $protos = trim($m[1]);
            if (preg_match_all('/SSLv[23]|TLSv1(?:\.0|\.1)?(?![\d.])/i', $protos, $w)) {
                $weak = implode(', ', array_unique($w[0]));
                $rows[] = ['level' => 'err', 'title' => 'ssl_protocols: ' . $protos, 'detail' => "устаревшие протоколы: $weak — разрешите только TLSv1.2 / TLSv1.3"];
            } else {
                $rows[] = ['level' => 'ok', 'title' => 'ssl_protocols: ' . $protos, 'detail' => 'слабых протоколов нет'];
            }
        } elseif ($hasSSL) {
            $rows[] = ['level' => 'warn', 'title' => 'ssl_protocols', 'detail' => 'не заданы — действуют дефолты nginx, могут включать TLSv1'];
        }

        // --- скрытые файлы (.env, .git) ---
        // Ищем правило, реально закрывающее ЛЮБОЙ скрытый файл: маска вида /\.
        // (например, «location ~ /\.»). Правила вида /\.ht закрывают только
        // Apache-файлы и .env НЕ защищают — их считаем недостаточными.
        $dotRule = '';
        $partialRule = '';
        foreach ($locs as [$header]) {
            $h = trim($header);
            if (strpos($h, '/\\.') === false) continue;
            if (preg_match('#/\\\.(\s|\{|\$)#', $h) || preg_match('#/\\\.\s*\{#', $h)) {
                $dotRule = $h;
                break;
            }
            $partialRule = $partialRule ?: $h;
        }
        if ($dotRule !== '') {
            $rows[] = ['level' => 'ok', 'title' => 'Скрытые файлы закрыты', 'detail' => $dotRule . ' — доступ к /.env, /.git запрещён'];
        } elseif ($partialRule !== '') {
            $rows[] = ['level' => 'warn', 'title' => 'Скрытые файлы закрыты частично', 'detail' => $partialRule . ' — правило закрывает только часть скрытых файлов (например .ht*), а /.env остаётся доступным. Замените на: location ~ /\. { deny all; } (не забудьте до него location для /.well-known)'];
        } else {
            $rows[] = ['level' => 'warn', 'title' => 'Нет правила для скрытых файлов', 'detail' => 'добавьте: location ~ /\. { deny all; } — иначе /.env и /.git могут быть доступны из веба'];
        }

        // --- PHP-location: try_files ---
        $phpLocs = [];
        foreach ($locs as [$header, $body]) {
            if (stripos($header, 'php') !== false || stripos($body, 'fastcgi_pass') !== false) $phpLocs[] = $body;
        }
        if ($phpLocs) {
            $hasTryFiles = false;
            foreach ($phpLocs as $body) {
                if (stripos($body, 'try_files') !== false) $hasTryFiles = true;
            }
            $rows[] = $hasTryFiles
                ? ['level' => 'ok', 'title' => 'PHP-location', 'detail' => 'try_files присутствует — исполнение загруженных файлов через параметры пути заблокировано']
                : ['level' => 'warn', 'title' => 'PHP-location без try_files', 'detail' => 'загруженный в публичную папку файл можно исполнить (path traversal / RCE) — добавьте try_files $uri =404;'];
        } else {
            $rows[] = ['level' => 'info', 'title' => 'PHP-location', 'detail' => 'в этом блоке не найден'];
        }

        // --- служебные каталоги антивируса ---
        // Проверяем, что nginx закрывает /data, /logs, /backup, /quarantine
        // (baseline, логи, бэкапы с дампами БД и малварь не должны отдаваться из веба).
        $blocked = [];
        foreach ($locs as [$header, $body]) {
            if (stripos($body, 'deny all') === false) continue;
            // собираем, какие из служебных имён покрывает регулярка location
            foreach (['data', 'logs', 'backup', 'quarantine'] as $svc) {
                if (isset($blocked[$svc])) continue;
                if (stripos($header, $svc) !== false) $blocked[$svc] = trim($header);
            }
        }
        $missing = array_values(array_diff(['data', 'logs', 'backup', 'quarantine'], array_keys($blocked)));
        if (!$missing) {
            $rows[] = ['level' => 'ok', 'title' => 'Служебные каталоги антивируса', 'detail' => 'data/logs/backup/quarantine закрыты (deny all)'];
        } elseif (count($blocked) > 0) {
            $rows[] = ['level' => 'warn', 'title' => 'Служебные каталоги антивируса закрыты частично', 'detail' => 'закрыты: ' . implode(', ', array_keys($blocked)) . '; не закрыты: ' . implode(', ', $missing) . ' — добавьте: location ~* ^/antivirus/(?:data|logs|backup|quarantine)/ { deny all; }'];
        } else {
            $rows[] = ['level' => 'err', 'title' => 'Служебные каталоги антивируса открыты', 'detail' => '/data (baseline), /logs, /backup (бэкапы с дампами БД!), /quarantine могут быть скачаны из веба. Добавьте: location ~* /(?:data|logs|backup|quarantine|vendor|src|templates)/ { deny all; }'];
        }

        // --- security-заголовки ---
        $headerNames = [];
        if (preg_match_all('/add_header\s+([A-Za-z0-9_-]+)/', $block, $m)) {
            foreach ($m[1] as $h) $headerNames[] = strtolower($h);
        }
        foreach (['x-frame-options', 'x-content-type-options', 'referrer-policy', 'content-security-policy'] as $key) {
            $rows[] = in_array($key, $headerNames, true)
                ? ['level' => 'ok', 'title' => 'Заголовок ' . $key, 'detail' => 'задан']
                : ['level' => 'info', 'title' => 'Заголовок ' . $key, 'detail' => 'не задан (рекомендуется добавить через add_header)'];
        }
        if ($hasSSL) {
            $rows[] = in_array('strict-transport-security', $headerNames, true)
                ? ['level' => 'ok', 'title' => 'Заголовок strict-transport-security', 'detail' => 'HSTS настроен']
                : ['level' => 'warn', 'title' => 'Нет HSTS', 'detail' => 'добавьте add_header Strict-Transport-Security "max-age=31536000" always;'];
        }

        // --- autoindex ---
        if (preg_match('/^\s*autoindex\s+on/im', $block)) {
            $rows[] = ['level' => 'err', 'title' => 'autoindex on', 'detail' => 'листинг каталогов открыт — утекает структура файлов'];
        } else {
            $rows[] = ['level' => 'ok', 'title' => 'autoindex', 'detail' => 'выключен (листинг каталогов закрыт)'];
        }

        // --- client_max_body_size ---
        if (preg_match('/client_max_body_size\s+([^;]+);/', $block, $m)) {
            $val = trim($m[1]);
            $bytes = $this->sizeToBytes($val);
            // 0 = без ограничения; слишком большой лимит — риск исчерпания канала
            // при атаке; дефолт 1m мал для загрузки бэкапов через панель.
            if ($bytes !== null && $bytes > 0 && $bytes > 256 * 1024 * 1024) {
                $rows[] = ['level' => 'warn', 'title' => 'client_max_body_size', 'detail' => "$val — лимит слишком велик (>256m), уменьшите до разумного"];
            } else {
                $rows[] = ['level' => 'ok', 'title' => 'client_max_body_size', 'detail' => $val];
            }
        } else {
            $rows[] = ['level' => 'warn', 'title' => 'client_max_body_size', 'detail' => 'не задан — действует дефолт nginx (1m), загрузка крупных файлов в панель будет падать с 413'];
        }

        return $rows;
    }

    /**
     * Размер nginx («50m», «2G», «1024k», «0») в байты; null — если не разобрали.
     */
    private function sizeToBytes(string $val): ?int
    {
        $val = trim($val);
        if ($val === '') return null;
        if (preg_match('/^(\d+)$/', $val)) return (int)$val;
        if (preg_match('/^(\d+)\s*([kKmMgG])$/', $val, $m)) {
            $mult = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3][strtolower($m[2])];
            return (int)$m[1] * $mult;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // ДОСТУПНОСТЬ ЧУВСТВИТЕЛЬНЫХ ФАЙЛОВ ИЗ ВЕБА
    // ------------------------------------------------------------------

    /**
     * HTTP-пробы: отдаёт ли сервер .env и другие файлы, которые не должны
     * быть доступны из веба. Содержимое файлов в отчёт НЕ попадает.
     */
    public function envExposure(): array
    {
        $out = ['base' => '', 'probes' => [], 'note' => ''];

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            $out['note'] = 'HTTP_HOST недоступен — HTTP-пробы не выполнены (запустите аудит из веб-интерфейса)';
            return $out;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base = ($https ? 'https://' : 'http://') . $host;
        $out['base'] = $base;

        $paths = [
            ['/.env',                        '.env корня сайта (пароли БД)',                        'env'],
            ['/antivirus/.env',              '.env антивируса (логин/пароль панели)',              'env'],
            ['/antivirus/data/baseline.json','baseline.json (хеши всех файлов сайта)',            'json'],
            ['/antivirus/data/nginx.conf',   'ручная копия vhost (конфигурация nginx)',            'nginx'],
            ['/.git/config',                 '.git/config (утечка истории репозитория)',           'git'],
            ['/.env.bak',                    '.env.bak (забытая копия)',                           'env'],
            ['/.env.old',                    '.env.old (забытая копия)',                           'env'],
        ];

        foreach ($paths as [$path, $label, $kind]) {
            $r = $this->httpGet($base . $path);
            if (!$r['ok']) {
                $out['probes'][] = ['url' => $path, 'label' => $label, 'status' => 'info', 'code' => 0, 'note' => 'запрос не выполнен (исходящие соединения запрещены?)'];
                continue;
            }
            $code = $r['code'];
            $body = $r['body'];
            if ($code === 200) {
                $looksReal = false;
                if ($kind === 'env') {
                    $looksReal = (bool)preg_match('/^[A-Z0-9_.-]+\s*=/m', $body) || stripos($body, 'PASS') !== false;
                } elseif ($kind === 'json') {
                    $looksReal = strpos(ltrim($body), '{') === 0;
                } elseif ($kind === 'git') {
                    $looksReal = strpos($body, '[core]') !== false;
                } elseif ($kind === 'nginx') {
                    $looksReal = stripos($body, 'server') !== false && strpos($body, '{') !== false;
                }
                if ($looksReal) {
                    $out['probes'][] = ['url' => $path, 'label' => $label, 'status' => 'err', 'code' => $code, 'note' => 'ФАЙЛ ДОСТУПЕН ИЗ ВЕБА — закройте его: location ~ /\. { deny all; }'];
                } elseif (trim($body) === '') {
                    $out['probes'][] = ['url' => $path, 'label' => $label, 'status' => 'warn', 'code' => $code, 'note' => 'сервер отвечает 200 пустым телом — проверьте вручную'];
                } else {
                    $out['probes'][] = ['url' => $path, 'label' => $label, 'status' => 'info', 'code' => $code, 'note' => 'код 200, но содержимое не похоже на файл (возможно, rewrite-маршрут приложения)'];
                }
            } elseif ($code === 403 || $code === 404) {
                $out['probes'][] = ['url' => $path, 'label' => $label, 'status' => 'ok', 'code' => $code, 'note' => "закрыт (HTTP $code)"];
            } elseif ($code === 0) {
                $out['probes'][] = ['url' => $path, 'label' => $label, 'status' => 'info', 'code' => 0, 'note' => 'нет ответа'];
            } else {
                $out['probes'][] = ['url' => $path, 'label' => $label, 'status' => 'info', 'code' => $code, 'note' => "неожиданный ответ HTTP $code"];
            }
        }
        return $out;
    }

    /**
     * GET-запрос: curl при наличии, иначе streams. Верификацию сертификата
     * отключаем — аудит обращается к самому себе (кейс self-signed/staging).
     *
     * @return array{ok:bool,code:int,body:string}
     */
    private function httpGet(string $url, int $timeout = 3): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER        => false,
                CURLOPT_TIMEOUT       => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT     => 'AV-Tool-Audit',
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $failed = $body === false;
            curl_close($ch);
            if (!$failed) return ['ok' => true, 'code' => $code, 'body' => (string)$body];
            return ['ok' => false, 'code' => 0, 'body' => ''];
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'ignore_errors' => true, 'method' => 'GET', 'user_agent' => 'AV-Tool-Audit'],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return ['ok' => false, 'code' => 0, 'body' => ''];
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $h, $mm)) { $code = (int)$mm[1]; break; }
        }
        return ['ok' => true, 'code' => $code, 'body' => (string)$body];
    }

    // ------------------------------------------------------------------
    // ПРАВА И ВЛАДЕЛЬЦЫ ФАЙЛОВ
    // ------------------------------------------------------------------

    /** Пути, чей владелец (root) не считается проблемой: certbot/акме и т.п. */
    private const OWNER_IGNORE = ['.well-known'];

    /**
     * Обход site_root: права и владельцы.
     *
     * Критерии:
     *   - PHP-файлы: 644 (или строже); запись группе/миру (666/777) — ошибка;
     *   - каталоги с PHP-кодом: 755, мировая запись (777) — ошибка;
     *   - каталоги без PHP (медиа): 777 — допустимо, но помечается;
     *   - .env* / *.pem / *.key / *.sql: запись всем — ошибка, чтение всем — предупреждение;
     *   - владелец root — ошибка, владелец, отличный от владельца корня сайта, — предупреждение.
     */
    public function filesystem(): array
    {
        $root = $this->cfg['site_root'];
        $excludes = $this->cfg['exclude_paths'];
        $expectedUid = @fileowner($root);

        $fs = [
            'stats' => ['files' => 0, 'dirs' => 1, 'php' => 0, 'links' => 0],
            'php_bad' => [],
            'dirs_bad' => [],
            'dirs_world' => [],
            'sensitive_bad' => [],
            'media_world' => [],
            'owners_root' => [],
            'owners_foreign' => [],
            'expected_owner' => ['uid' => $expectedUid, 'name' => $this->uidName($expectedUid)],
        ];

        $rootPerms = @fileperms($root);
        $dirPerms = ['.' => $rootPerms === false ? null : $rootPerms & 0777];
        $dirHasPhp = [];

        $this->walk($root, '', $excludes, $fs, $dirPerms, $dirHasPhp, $expectedUid);

        // Флаг «в каталоге есть PHP» поднимаем на все родительские каталоги
        foreach ($dirHasPhp as $d => $v) {
            if (!$v) continue;
            $parts = explode('/', $d);
            array_pop($parts);
            while ($parts) {
                $dirHasPhp[implode('/', $parts)] = true;
                array_pop($parts);
            }
        }

        foreach ($dirPerms as $d => $m) {
            if ($d === '.' || $m === null) continue;
            $hasPhp = !empty($dirHasPhp[$d]);
            if (($m & 0002) !== 0) {
                if ($hasPhp) {
                    // мировая запись там, где есть PHP — webshell сможет править код
                    $fs['dirs_bad'][] = [
                        'level' => 'err',
                        'path' => $d,
                        'perm' => sprintf('%o', $m),
                        'msg' => 'мировая запись на каталоге с PHP-кодом — должен быть 755',
                    ];
                } else {
                    // кэши превью/медиа без кода — не проблема, показываем отдельно
                    $fs['dirs_world'][] = [
                        'level' => 'info',
                        'path' => $d,
                        'perm' => sprintf('%o', $m),
                        'msg' => 'мировая запись — допустимо для кэшей и публичных папок медиа',
                    ];
                }
            } elseif (($m & 0020) !== 0 && $hasPhp) {
                $fs['dirs_bad'][] = ['level' => 'warn', 'path' => $d, 'perm' => sprintf('%o', $m), 'msg' => 'запись группе на каталоге с PHP-кодом — должен быть 755'];
            }
        }

        // Сортировка: сначала критичные, затем по пути
        $weight = ['err' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($fs['php_bad'], fn($a, $b) => $weight[$a['level']] <=> $weight[$b['level']] ?: strcmp($a['path'], $b['path']));
        usort($fs['dirs_bad'], fn($a, $b) => $weight[$a['level']] <=> $weight[$b['level']] ?: strcmp($a['path'], $b['path']));
        usort($fs['sensitive_bad'], fn($a, $b) => $weight[$a['level']] <=> $weight[$b['level']] ?: strcmp($a['path'], $b['path']));
        usort($fs['owners_root'], fn($a, $b) => strcmp($a['path'], $b['path']));
        usort($fs['owners_foreign'], fn($a, $b) => strcmp($a['path'], $b['path']));
        usort($fs['media_world'], fn($a, $b) => strcmp($a['path'], $b['path']));
        usort($fs['dirs_world'], fn($a, $b) => strcmp($a['path'], $b['path']));

        // Счётчики уровней — до обрезки списков
        $fs['level_counts'] = ['err' => count($fs['owners_root']), 'warn' => count($fs['owners_foreign'])];
        foreach (['php_bad', 'dirs_bad', 'sensitive_bad'] as $k) {
            foreach ($fs[$k] as $row) {
                if ($row['level'] === 'err') $fs['level_counts']['err']++;
                elseif ($row['level'] === 'warn') $fs['level_counts']['warn']++;
            }
        }

        // Обрезка длинных списков для отображения
        $fs['totals'] = [];
        foreach (['php_bad', 'dirs_bad', 'dirs_world', 'sensitive_bad', 'media_world', 'owners_root', 'owners_foreign'] as $k) {
            $fs['totals'][$k] = count($fs[$k]);
            if (count($fs[$k]) > self::LIST_LIMIT) {
                $fs[$k] = array_slice($fs[$k], 0, self::LIST_LIMIT);
            }
        }
        return $fs;
    }

    /**
     * Чувствителен ли файл на самом деле, или это ложное срабатывание
     * (публичные CA-бандлы, тестовые фикстуры, шаблоны схем БД плагинов).
     * Вызывается и из walk(), и из fixSensitive() — критерии едины.
     *
     * Эвристика по содержимому: читаем голову файла (для .sql — если файл
     * небольшой) и ищем маркеры реального секрета. Иначе — только имена/пути.
     */
    private function isRealSensitive(string $rel, string $base, string $ext): bool
    {
        if (stripos($base, '.env') === 0) {
            return true;
        }
        if (!in_array($ext, self::SENSITIVE_EXT, true)) {
            return false;
        }

        $baseN = strtolower($base);

        // публичные CA-бандлы — известные имена в любом каталоге
        if (in_array($baseN, self::FALSE_POSITIVE_NAMES, true)) {
            return false;
        }

        // сегменты пути: tests/fixtures/data_structure — шаблоны и фикстуры,
        // а не секреты (google/auth fixtures, LiteSpeed data_structure и т.п.)
        foreach (explode('/', strtolower($rel)) as $seg) {
            if (in_array($seg, self::FALSE_POSITIVE_DIRS, true)) {
                return false;
            }
        }

        // .pem/.key: секретен только приватный ключ (BEGIN ... PRIVATE KEY),
        // публичные сертификаты, подписи и лицензии (wordfence *.key) — нет
        if ($ext === 'pem' || $ext === 'key') {
            $abs = $this->cfg['site_root'] . '/' . $rel;
            $head = @file_get_contents($abs, false, null, 0, 256);
            if ($head !== false && preg_match('/-----BEGIN\s+(?:RSA\s+|EC\s+|DSA\s+|OPENSSH\s+)?PRIVATE KEY-----/i', $head)) {
                return true;
            }
            return false;
        }

        // .sql: дамп с данными — секрет; шаблон схемы/служебный скрипт — нет.
        // Дамп mysqldump начинается с DDL всех таблиц, INSERT может быть
        // глубже первых килобайт — поэтому небольшие файлы читаем целиком.
        // Крупный файл (>256KB) — почти наверняка реальный дамп.
        if ($ext === 'sql') {
            $abs = $this->cfg['site_root'] . '/' . $rel;
            $size = @filesize($abs);
            if ($size === false) return true; // не смогли прочитать — считаем секретом
            if ($size === 0) return false;    // пустой шаблон — не секрет
            if ($size <= 256 * 1024) {
                $content = @file_get_contents($abs);
                if ($content !== false) {
                    if (stripos($content, 'INSERT INTO') !== false) return true;
                    if (preg_match('/\b(password|secret|api[_-]?key|token)\b/i', $content)) return true;
                    return false; // DDL/скрипт без данных — не секрет
                }
            }
        }
        return true;
    }

    /**
     * Рекурсивный обход каталога (символьные ссылки пропускаются, исключения
     * из exclude_paths не посещаются вовсе).
     */
    private function walk(string $absDir, string $relDir, array $excludes, array &$fs, array &$dirPerms, array &$dirHasPhp, $expectedUid): void
    {
        $entries = @scandir($absDir);
        if ($entries === false) return;

        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $abs = $absDir . '/' . $e;
            $rel = $relDir === '' ? $e : $relDir . '/' . $e;
            if (FileHelper::isExcluded($rel, $excludes)) continue;

            if (@is_link($abs)) {
                $fs['stats']['links']++;
                continue;
            }

            $isDir = @is_dir($abs);

            $uid = @fileowner($abs);
            if ($uid !== false && !$this->isOwnerIgnored($rel)) {
                if ($uid === 0) {
                    $fs['owners_root'][] = ['path' => $rel, 'type' => $isDir ? 'каталог' : 'файл', 'uid' => 0, 'name' => 'root'];
                } elseif ($expectedUid !== false && $uid !== $expectedUid) {
                    $fs['owners_foreign'][] = ['path' => $rel, 'type' => $isDir ? 'каталог' : 'файл', 'uid' => $uid, 'name' => $this->uidName($uid)];
                }
            }

            if ($isDir) {
                $fs['stats']['dirs']++;
                $m = @fileperms($abs);
                if ($m !== false) $dirPerms[$rel] = $m & 0777;
                $this->walk($abs, $rel, $excludes, $fs, $dirPerms, $dirHasPhp, $expectedUid);
                continue;
            }
            if (!@is_file($abs)) continue;

            $fs['stats']['files']++;
            $m = @fileperms($abs);
            if ($m === false) continue;
            $m &= 0777;

            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $base = basename($rel);
            $isPhp = in_array($ext, self::PHP_EXT, true);

            if ($isPhp) {
                $fs['stats']['php']++;
                $parent = dirname($rel);
                $dirHasPhp[$parent] = true;
                if (($m & 0022) !== 0) {
                    $fs['php_bad'][] = ['level' => 'err', 'path' => $rel, 'perm' => sprintf('%o', $m), 'msg' => 'запись группе/всем — webshell сможет править код сайта (нужно 644)'];
                } elseif (!in_array($m, self::OK_FILE_PERMS, true)) {
                    $fs['php_bad'][] = ['level' => 'warn', 'path' => $rel, 'perm' => sprintf('%o', $m), 'msg' => 'рекомендуется 644'];
                }
                continue;
            }

            $isSensitive = $this->isRealSensitive($rel, $base, $ext);
            if ($isSensitive) {
                if (($m & 0022) !== 0) {
                    $fs['sensitive_bad'][] = ['level' => 'err', 'path' => $rel, 'perm' => sprintf('%o', $m), 'msg' => 'чувствительный файл открыт на запись — нужно 640'];
                } elseif (($m & 0004) !== 0) {
                    $fs['sensitive_bad'][] = ['level' => 'warn', 'path' => $rel, 'perm' => sprintf('%o', $m), 'msg' => 'чувствительный файл читается всеми пользователями сервера — рекомендуется 640'];
                }
            } elseif (($m & 0002) !== 0) {
                $fs['media_world'][] = ['path' => $rel, 'perm' => sprintf('%o', $m), 'msg' => 'мировая запись — для публичных медиа не критично'];
            }
        }
    }

    /**
     * Путь (или его родитель) попадает в каталоги-исключения владельца —
     * например, .well-known/acme-challenge, который certbot создаёт от root.
     */
    private function isOwnerIgnored(string $rel): bool
    {
        foreach (self::OWNER_IGNORE as $p) {
            if ($rel === $p || strpos($rel, $p . '/') === 0) return true;
        }
        return false;
    }

    /**
     * Имя пользователя по uid (posix может быть отключён).
     *
     * @param int|false $uid
     */
    private function uidName($uid): string
    {
        if ($uid === false || $uid === null) return 'неизвестно';
        if ($uid === 0) return 'root';
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid((int)$uid);
            if (!empty($pw['name'])) return $pw['name'];
        }
        return 'uid ' . (int)$uid;
    }
}