<?php
declare(strict_types=1);
namespace AV\Model;

class Signatures
{
    public static function all(): array
    {
        return [
            [
                'name'     => 'Generic PHP webshell (eval + $_POST/$_REQUEST)',
                'pattern' => '/@?eval\s*\(\s*(?:base64_decode|gzinflate|str_rot13|gzuncompress)\s*\(/i',
                'severity' => 'high',
            ],
            [
                // C99/R57: срабатываем только на PHP-файлы, т.к. в .txt/.md
                // этих строк много в legit readme (Wordfence, SimplePie), а
                // реальные шеллы в .txt кладут, но PHP их не исполнит.
                'name'      => 'C99/R57 shell fingerprint',
                'pattern'   => '/\b(?:b374k|localhostsec|psybnc|c99shell|r57shell)\b/i',
                'severity'  => 'high',
                'extensions'=> ['php', 'phtml', 'phps', 'inc'],
            ],
            [
                // Hidden iframe: исключаем localhost/wpseeds.com и файлы-шаблоны
                // документации в plugin'ах (help/readme), иначе постоянные FP.
                'name'      => 'Hidden iframe / drive-by',
                'pattern'   => '/<iframe[^>]+src\s*=\s*["\']?\s*(?:https?:\/\/|data:)/i',
                'severity'  => 'medium',
                'extensions'=> ['php', 'phtml', 'phps', 'inc', 'html', 'htm'],
                // Если iframe ведёт на youtube/vimeo/wordpress.org/годплагины — ок.
                'not_pattern'=> '/wpseeds\.com|youtube\.com|vimeo\.com|google\.com\/maps|wordpress\.org/i',
            ],
            [
                'name'     => 'Obfuscated base64 + eval chain',
                'pattern' => '/base64_decode\s*\(\s*["\'][A-Za-z0-9+\/=]{80,}/i',
                'severity' => 'high',
            ],
            [
                'name'     => 'Gzinflate + base64 payload',
                'pattern' => '/gzinflate\s*\(\s*base64_decode\s*\(/i',
                'severity' => 'high',
            ],
            [
                'name'     => 'str_rot13 + base64 obfuscation',
                'pattern' => '/str_rot13\s*\(\s*base64_decode\s*\(/i',
                'severity' => 'high',
            ],
            [
                'name'     => 'PHP eval via preg_replace /e',
                'pattern' => '/preg_replace\s*\(\s*[\'"]\s*\/[^\/]*\/e/i',
                'severity' => 'high',
            ],
            [
                'name'     => 'Remote command execution wrapper',
                'pattern' => '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[[^\]]*\]\s*\(\s*\$_/i',
                'severity' => 'high',
            ],
            [
                'name'     => 'Dynamic function call from user input',
                'pattern' => '/(?:eval|assert)\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE)/i',
                'severity' => 'high',
            ],
            [
                // document.write(unescape(...)) — классическая drive-by инъекция.
                // Срабатываем при наличии конструкции + в файле есть URL-encoded
                // строка (плотность %XX), иначе это легитимные упоминания в доке.
                'name'      => 'Known JS malware: document.write(unescape(',
                'pattern'   => '/document\.write\s*\(\s*unescape\s*\(/i',
                'severity'  => 'medium',
                'extensions'=> ['js', 'html', 'htm', 'php', 'phtml'],
                // Требуем, чтобы в файле была URL-encoded строка (>20 символов %XX-формата)
                // или hex/base64 аргумент — это уберёт срабатывания на документацию.
                'require_pattern' => '/(?:%[0-9a-fA-F]{2}){10,}|\\\\x[0-9a-fA-F]{2}.*\\\\x[0-9a-fA-F]{2}.*\\\\x[0-9a-fA-F]{2}/i',
            ],
            [
                // JS инъекция: eval(atob( — типичная схема подгрузки payload
                'name'      => 'JS eval(atob()) injection',
                'pattern'   => '/\beval\s*\(\s*(?:atob|unescape|decodeURIComponent|String\.fromCharCode)\s*\(/i',
                'severity'  => 'high',
                'extensions'=> ['js', 'jsx', 'ts', 'html', 'htm'],
            ],
            [
                // Function("...") с длинным телом — обход Content Security Policy
                'name'      => 'JS new Function(payload)',
                'pattern'   => '/\bnew\s+Function\s*\(\s*["\'][^"\']{120,}["\']\s*\)/i',
                'severity'  => 'medium',
                'extensions'=> ['js', 'jsx', 'ts'],
            ],
            [
                // Массив из hex-encoded строк (obfuscator.io style):
                // var _0xNNNN=['\x61\x62','\x63\x64',...];  — каждый элемент = одна или
                // более \xNN-последовательностей, и таких элементов >=4.
                'name'      => 'JS hex-array obfuscator (_0x… with \\xNN)',
                'pattern'   => '/\bvar\s+_0x[0-9a-fA-F]+\s*=\s*\[\s*[\'"](?:\\\\x[0-9a-fA-F]{2})+[\'"]\s*(?:,\s*[\'"](?:\\\\x[0-9a-fA-F]{2})+[\'"]\s*){3,}/i',
                'severity'  => 'high',
                'extensions'=> ['js', 'jsx', 'ts'],
            ],
            [
                // String.fromCharCode(104,116,116,112,...) — сборка URL по charcodes
                'name'      => 'JS String.fromCharCode payload',
                'pattern'   => '/String\.fromCharCode\s*\(\s*(?:\d{1,3}\s*,\s*){20,}/i',
                'severity'  => 'medium',
                'extensions'=> ['js', 'jsx', 'ts', 'html', 'htm'],
            ],
            [
                // document["cookie"] / document['location'] через строковые ключи — угон cookie/редирект
                'name'      => 'JS cookie/location steal via bracket string',
                'pattern'   => '/\bdocument\s*\[\s*["\'](?:cookie|location|domain)["\']\s*\]\s*=[^=]/i',
                'severity'  => 'medium',
                'extensions'=> ['js', 'jsx', 'ts'],
            ],
            [
                // setTimeout/setInterval с длинной строкой (eval via timer)
                'name'      => 'JS eval via setTimeout string',
                'pattern'   => '/\b(?:setTimeout|setInterval)\s*\(\s*["\'][^"\']{80,}["\']/i',
                'severity'  => 'low',
                'extensions'=> ['js', 'jsx', 'ts'],
            ],
            [
                'name'     => 'Suspicious wp- / wp_ eval loader (WP-specific)',
                'pattern' => '/eval\s*\(base64_decode/i',
                'severity' => 'low',
            ],
            [
                // Mailer spam: срабатываем ТОЛЬКО когда аргумент mail() начинается
                // с суперглобала ($_POST, $_REQUEST) или с base64/gzinflate.
                // Это убирает все легитимные wp_mail()/is_email()/sanitize_email().
                'name'     => 'Mailer spam injection',
                'pattern'  => '/\bmail\s*\(\s*(?:\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)|base64_decode|gzinflate|str_rot13|hex2bin)\b/i',
                'severity' => 'medium',
            ],
            [
                // Reverse shell: оригинальный шаблон срабатывал на HTTP-клиент WP.
                // Теперь требуем, чтобы рядом был /bin/sh, bash, nc, exec/system,
                // или порт стандартный для шеллов (4444/5555/6666/1337/31337).
                'name'     => 'Reverse shell / bind shell',
                'pattern'  => '/(?:fsockopen|pfsockopen|stream_socket_client)\s*\(\s*["\'](?:tcp|udp):\/\/(?!127\.0\.0\.1|localhost)[^"\']*(?:\/bin\/|bash|nc\s|nc\.|sh\s|:4444|:5555|:6666|:1337|:31337)/i',
                'severity' => 'high',
            ],
            [
                'name'     => 'Disable functions bypass (putenv + mail)',
                'pattern'  => '/putenv\s*\([^)]*mail\s*\(\s*\$_/i',
                'severity' => 'medium',
            ],
            [
                'name'      => 'Encoded variable variable execution',
                'pattern'   => '/\$\$\s*[_a-zA-Z][_a-zA-Z0-9]*\s*\(/i',
                'severity'  => 'low',
                // В JS эта конструкция бесполезна (нет $$var()), отсекаем FP
                'extensions'=> ['php', 'phtml', 'phps', 'inc'],
            ],
        ];
    }
}
