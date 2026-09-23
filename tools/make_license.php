<?php
declare(strict_types=1);
/**
 * tools/make_license.php — генератор лицензионных ключей (только для владельца!).
 *
 * ДЕРЖИТЕ ЭТОТ ФАЙЛ И СЕКРЕТНЫЙ КЛЮЧ ТОЛЬКО У СЕБЯ. Никогда не выкладывайте
 * license_secret.key и этот скрипт на сервер клиента — по секретному ключу
 * любой сможет выпустить себе вечную лицензию.
 *
 * Использование:
 *   php tools/make_license.php --issue --customer="Иван" --days=365 --domain=example.com
 *   php tools/make_license.php --issue --customer="Иван" --days=365 --domain=site1.ru,site2.ru
 *   php tools/make_license.php --issue --customer="Иван" --days=365   (без привязки к домену)
 *   php tools/make_license.php --keygen
 *   php tools/make_license.php --check="AV1-XXXX..."   (локальная проверка ключа)
 *
 * Формат ключа:
 *   AV1-<base64url(payload)>.<base64url(Ed25519 signature)>
 *   payload = JSON {"v":1,"iat":...,"exp":...,"customer":"...","domains":["example.com"]}
 *
 * Ключ проверяется офлайн: подпись Ed25519 публичным ключом (embedded в
 * продукте), затем сравнение exp с текущим временем и (если задано поле
 * domains) совпадение домена сайта. Сервер не нужен.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Только CLI\n");
    exit(1);
}

$secretKeyFile = __DIR__ . '/license_secret.key';
$publicKeyFile = __DIR__ . '/license_public.key';

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): string|false
{
    $rem = strlen($s) % 4;
    if ($rem === 1) return false;
    if ($rem > 1) $s .= str_repeat('=', 4 - $rem);
    return base64_decode(strtr($s, '-_', '+/'), true);
}

$args = array_slice($argv, 1);
$cmd = $args[0] ?? '';

if ($cmd === '--keygen') {
    $kp = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($kp);
    $public = sodium_crypto_sign_publickey($kp);
    file_put_contents($secretKeyFile, $secret);
    file_put_contents($publicKeyFile, $public);
    chmod($secretKeyFile, 0600);
    echo "Секретный ключ: $secretKeyFile (" . strlen($secret) . " байт)\n";
    echo "Публичный ключ:  $publicKeyFile (" . strlen($public) . " байт)\n";
    echo "Публичный ключ (hex, для embed в продукт):\n  " . bin2hex($public) . "\n";
    exit(0);
}

if ($cmd === '--issue') {
    $opts = [];
    foreach ($args as $a) {
        if (strpos($a, '--') === 0 && strpos($a, '=') !== false) {
            [$k, $v] = explode('=', substr($a, 2), 2);
            $opts[$k] = $v;
        }
    }
    if (!is_file($secretKeyFile)) {
        fwrite(STDERR, "Нет $secretKeyFile. Сначала: php tools/make_license.php --keygen\n");
        exit(1);
    }
    $secret = file_get_contents($secretKeyFile);
    $days = (int)($opts['days'] ?? 365);
    if ($days < -3650 || $days > 3650 || $days === 0) $days = 365;
    $payload = [
        'v'        => 1,
        'iat'      => time(),
        'exp'      => time() + $days * 86400,
        'customer' => (string)($opts['customer'] ?? 'site'),
    ];
    // Привязка к домену (опционально): список через запятую. Домены
    // нормализуются так же, как в License::normalizeDomains() продукта:
    // срезается схема, www и порт. Отсутствие --domain = ключ без привязки
    // (обратная совместимость уже выпущенных лицензий).
    if (!empty($opts['domain'])) {
        $domains = [];
        foreach (explode(',', (string)$opts['domain']) as $d) {
            $d = mb_strtolower(trim($d), 'UTF-8');
            $d = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $d);
            $d = preg_replace('~[/:].*$~', '', $d);
            $d = preg_replace('~^www\.~', '', $d);
            $d = preg_replace('~[^\p{L}\p{N}.-]~u', '', $d);
            $d = trim($d, '.-');
            if ($d !== '') $domains[] = $d;
        }
        if ($domains) $payload['domains'] = array_values(array_unique($domains));
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $sig = sodium_crypto_sign_detached($json, $secret);
    $key = 'AV1-' . b64url_encode($json) . '.' . b64url_encode($sig);
    echo "Лицензия на {$days} дней (до " . date('d.m.Y', $payload['exp']) . "):"
        . (empty($payload['domains']) ? '' : "\nДомены: " . implode(', ', $payload['domains']))
        . "\n$key\n";
    exit(0);
}

if ($cmd === '--check' && isset($args[1])) {
    $key = $args[1];
    if (!preg_match('/^AV1-([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $key, $m)) {
        fwrite(STDERR, "Некорректный формат ключа\n");
        exit(1);
    }
    $json = b64url_decode($m[1]);
    $sig  = b64url_decode($m[2]);
    if ($json === false || $sig === false) {
        fwrite(STDERR, "Некорректный base64\n");
        exit(1);
    }
    $public = is_file($publicKeyFile) ? file_get_contents($publicKeyFile) : '';
    if ($public === '') {
        fwrite(STDERR, "Нет $publicKeyFile для проверки\n");
        exit(1);
    }
    if (!sodium_crypto_sign_verify_detached($sig, $json, $public)) {
        fwrite(STDERR, "ПОДПИСЬ НЕВЕРНА\n");
        exit(1);
    }
    $p = json_decode($json, true);
    $left = (int)$p['exp'] - time();
    echo "Подпись верна.\nКлиент: {$p['customer']}\nДомены: "
        . (empty($p['domains']) ? '(без привязки — любой сайт)' : implode(', ', $p['domains']))
        . "\nИстекает: " . date('d.m.Y H:i', (int)$p['exp']) .
          ($left > 0 ? " (осталось " . (int)($left / 86400) . " дн)\n" : " (ИСТЕКЛА)\n");
    exit(0);
}

echo "Использование:\n  php tools/make_license.php --keygen\n  php tools/make_license.php --issue --customer=\"Имя\" --days=365 [--domain=example.com|a.com,b.com]\n  php tools/make_license.php --check AV1-...\n";
exit(0);