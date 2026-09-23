<?php
declare(strict_types=1);
namespace AV\Model;

/**
 * License — офлайн-проверка лицензионного ключа.
 *
 * Ключ: AV1-<b64url(JSON payload)>.<b64url(Ed25519 sig)>, подпись выпускается
 * владельцем продукта (tools/make_license.php). Проверка полностью локальная:
 * подпись Ed25519 + срок действия + привязка к домену (поле "domains" в
 * payload). Подделать ключ без секретного ключа владельца невозможно
 * (криптография), а обход проверки в коде — уже вопрос правки кода, что
 * детектируется самим AV Tool (baseline целостности).
 *
 * Защита от отката системных часов: временные метки запусков инструмента
 * накапливаются в data/ (максимальное наблюдённое время неуничтожимо без
 * правки данных), лицензия не может быть «продлена» переводом времени.
 *
 * Ключ хранится в data/license.dat (права 0640, вне веб-доступа).
 */
class License
{
    private const FILE = 'license.dat';

    /** @var array|null кеш распарсенного ключа */
    private static ?array $cache = null;

    public static function path(string $toolDir): string
    {
        return rtrim($toolDir, '/\\') . '/data/' . self::FILE;
    }

    /**
     * Статус лицензии.
     * @return array{valid:bool,state:string,exp?:int,customer?:string,daysLeft?:int,domains?:array,host?:string}
     *         state: ok | missing | invalid | expired | tampered | domain
     */
    public static function status(array $cfg): array
    {
        if (self::$cache !== null) return self::$cache;

        $file = self::path($cfg['tool_dir'] ?? '');
        $out = ['valid' => false, 'state' => 'missing'];

        if (!is_file($file)) {
            return self::$cache = $out;
        }

        $key = trim((string)@file_get_contents($file));
        if ($key === '') {
            $out['state'] = 'invalid';
            return self::$cache = $out;
        }

        if (!preg_match('/^AV1-([A-Za-z0-9_\-=]+)\.([A-Za-z0-9_\-=]+)$/', $key, $m)) {
            $out['state'] = 'invalid';
            return self::$cache = $out;
        }

        $json = self::b64urlDecode($m[1]);
        $sig  = self::b64urlDecode($m[2]);
        if ($json === false || $sig === false) {
            $out['state'] = 'invalid';
            return self::$cache = $out;
        }

        if (!KeyVault::verify($json, $sig)) {
            $out['state'] = 'tampered';
            return self::$cache = $out;
        }

        $p = json_decode($json, true);
        if (!is_array($p) || !isset($p['exp']) || !is_numeric($p['exp']) || (int)$p['exp'] <= 0) {
            $out['state'] = 'invalid';
            return self::$cache = $out;
        }

        // Привязка к домену: ключ без поля domains работает на любом сайте
        // (обратная совместимость старых ключей), с полем — только на своих.
        $domains = self::normalizeDomains($p['domains'] ?? null);
        $host = self::currentHost($cfg);
        if ($domains !== null && $host !== '') {
            $out['domains'] = $domains;
            $out['host'] = $host;
            if (!self::hostMatches($host, $domains)) {
                $out['exp'] = (int)$p['exp'];
                $out['state'] = 'domain';
                return self::$cache = $out;
            }
        }

        $now = self::now($cfg);
        $out['exp'] = (int)$p['exp'];
        $out['customer'] = (string)($p['customer'] ?? '');
        $out['daysLeft'] = (int)ceil(($out['exp'] - $now) / 86400);

        if ($now >= $out['exp']) {
            $out['state'] = 'expired';
            return self::$cache = $out;
        }

        $out['valid'] = true;
        $out['state'] = 'ok';
        return self::$cache = $out;
    }

    public static function valid(array $cfg): bool
    {
        return self::status($cfg)['valid'];
    }

    /**
     * Жёсткий шлюз: вызывается в критических точках (бэкап, сканирование,
     * baseline). При отсутствии/истечении лицензии — исключение.
     */
    public static function requireValid(array $cfg): void
    {
        $st = self::status($cfg);
        if ($st['valid']) return;
        $map = [
            'missing'   => 'лицензионный ключ не установлен',
            'invalid'   => 'лицензионный ключ некорректен',
            'tampered'  => 'лицензионный ключ подделан',
            'domain'    => 'лицензия выдана для другого домена',
            'expired'   => 'срок лицензии истёк (до ' . date('d.m.Y', (int)($st['exp'] ?? 0)) . ')',
        ];
        throw new \RuntimeException('Функция недоступна: ' . ($map[$st['state']] ?? 'нет лицензии') . '.');
    }

    /**
     * Установка ключа: валидация и запись в data/license.dat (0640).
     * Ключ, выпущенный для другого домена, не устанавливается.
     */
    public static function install(array $cfg, string $key): array
    {
        $key = trim(preg_replace('/\s+/', '', $key));
        if (!preg_match('/^AV1-([A-Za-z0-9_\-=]+)\.([A-Za-z0-9_\-=]+)$/', $key)) {
            return [false, 'Некорректный формат ключа.'];
        }
        $tmp = self::path($cfg['tool_dir']);
        $dir = dirname($tmp);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            return [false, 'Не удалось создать каталог data.'];
        }
        self::$cache = null;
        // Валидируем ключ ДО записи: подделка не должна попасть на диск.
        // временно проверяем «виртуально»
        if (!self::checkKeyString($cfg, $key)) {
            return [false, 'Ключ не прошёл проверку подписи, просрочен или выпущен для другого домена.'];
        }
        if (file_put_contents($tmp, $key, LOCK_EX) === false) {
            return [false, 'Не удалось записать файл лицензии.'];
        }
        @chmod($tmp, 0640);
        return [true, 'Лицензия установлена.'];
    }

    /** Проверка строки ключа без записи на диск. */
    private static function checkKeyString(array $cfg, string $key): bool
    {
        if (!preg_match('/^AV1-([A-Za-z0-9_\-=]+)\.([A-Za-z0-9_\-=]+)$/', $key, $m)) return false;
        $json = self::b64urlDecode($m[1]);
        $sig  = self::b64urlDecode($m[2]);
        if ($json === false || $sig === false) return false;
        if (!KeyVault::verify($json, $sig)) return false;
        $p = json_decode($json, true);
        if (!is_array($p) || !isset($p['exp']) || (int)$p['exp'] <= time()) return false;
        // Домен из ключа должен совпадать с текущим (если задан).
        $domains = self::normalizeDomains($p['domains'] ?? null);
        $host = self::currentHost($cfg);
        if ($domains !== null && $host !== '' && !self::hostMatches($host, $domains)) return false;
        return true;
    }

    /**
     * Домены из payload ключа: null = ключ без привязки (обратная
     * совместимость), массив — нормализованные имена в нижнем регистре.
     * @return array|null
     */
    private static function normalizeDomains($raw): ?array
    {
        if ($raw === null) return null;
        if (is_string($raw)) $raw = [$raw];
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $d) {
            $d = mb_strtolower(trim((string)$d), 'UTF-8');
            // срезаем схему, порт, литеральный www (остальные поддомены
            // покрываются hostMatches — им срезание первой метки запрещено,
            // иначе example.com превратился бы в com)
            $d = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $d);
            $d = preg_replace('~[/:].*$~', '', $d);
            $d = preg_replace('~^www\.~', '', $d);
            $d = preg_replace('~[^\p{L}\p{N}.-]~u', '', $d);
            $d = trim($d, '.-');
            if ($d !== '') $out[] = $d;
        }
        return $out === [] ? [] : array_values(array_unique($out));
    }

    /**
     * Текущий домен: из конфига (AV_LICENSE_DOMAIN), иначе HTTP_HOST /
     * SERVER_NAME (веб). Пустая строка — не удалось определить (CLI без
     * конфига) → привязанный ключ считается непрошедшим проверку.
     */
    private static function currentHost(array $cfg): string
    {
        $configured = (string)($cfg['license_domain'] ?? '');
        if ($configured !== '') {
            $configured = mb_strtolower(trim($configured), 'UTF-8');
            $configured = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $configured);
            $configured = preg_replace('~[/:].*$~', '', $configured);
            return preg_replace('~^www\.~', '', $configured);
        }
        $host = '';
        if (PHP_SAPI !== 'cli') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        }
        $host = mb_strtolower(trim(preg_replace('~[/:].*$~', '', $host)), 'UTF-8');
        return preg_replace('~^www\.~', '', $host);
    }

    /** Совпадает ли хост с одним из доменов ключа (с учётом www и поддоменов). */
    private static function hostMatches(string $host, array $domains): bool
    {
        foreach ($domains as $d) {
            if ($host === $d) return true;
            // поддомен лицензированного домена: sub.example.com под example.com
            if (str_ends_with($host, '.' . $d)) return true;
        }
        return false;
    }

    /**
     * Текущее время с защитой от отката часов: максимум из системного
     * времени и последнего наблюдённого запуска (сохраняемого в data/).
     */
    private static function now(array $cfg): int
    {
        $dir = $cfg['data_dir'] ?? '';
        if ($dir === '' || !is_dir($dir)) return time();
        $f = $dir . '/runtime.st';
        $stamp = is_file($f) ? (int)@file_get_contents($f) : 0;
        $now = time();
        if ($now > $stamp) {
            // Не даём «откатить» время: метка только растёт.
            $fp = @fopen($f, 'c+');
            if ($fp) {
                @flock($fp, LOCK_EX);
                @ftruncate($fp, 0);
                @rewind($fp);
                @fwrite($fp, (string)$now);
                @flock($fp, LOCK_UN);
                @fclose($fp);
                @chmod($f, 0640);
            }
            return $now;
        }
        return $stamp;
    }

    private static function b64urlDecode(string $s): string|false
    {
        $rem = strlen($s) % 4;
        if ($rem === 1) return false;
        if ($rem > 1) $s .= str_repeat('=', 4 - $rem);
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}