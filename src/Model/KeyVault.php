<?php
declare(strict_types=1);
namespace AV\Model;

/**
 * KeyVault — хранение верификационного материала продукта.
 *
 * Ключ верификации не хранится в открытом виде: он собирается из фрагментов
 * во время выполнения и проходит через XOR-маску. Дополнительно поддержан
 * fallback через OpenSSL (OPENSSL_ALGO_ED25519), если расширение sodium
 * недоступно на хостинге.
 */
class KeyVault
{
    /** Фрагмент A (16 байт, маскирован). */
    private static function segA(): string
    {
        return (string)base64_decode('2MaPZPTbYAFw8GpSlk9PDg==', true);
    }

    /** Фрагмент B (16 байт). */
    private static function segB(): string
    {
        return (string)base64_decode('+yjVOFJu8gLxZbtuzhlvFA==', true);
    }

    private static function demask(string $s): string
    {
        $m = [0x4d, 0x33, 0x21, 0x7c, 0x5a, 0x11, 0x9e, 0x08, 0x62, 0x4f, 0x3a, 0xd7, 0xc1, 0x70, 0x25, 0x6b];
        $o = '';
        for ($i = 0; $i < 16; $i++) {
            $o .= chr(ord($s[$i]) ^ $m[$i]);
        }
        return $o . substr($s, 16);
    }

    public static function public(): string
    {
        return self::demask(self::segA() . self::segB());
    }

    /** Ed25519-подпись detached: sodium, при отсутствии — OpenSSL. */
    public static function verify(string $msg, string $sig): bool
    {
        $pk = self::public();
        if (strlen($pk) !== 32 || strlen($sig) !== 64 || $msg === '') {
            return false;
        }
        if (function_exists('sodium_crypto_sign_verify_detached')) {
            try {
                return sodium_crypto_sign_verify_detached($sig, $msg, $pk);
            } catch (\Throwable $e) {
                return false;
            }
        }
        if (function_exists('openssl_verify')) {
            $b64 = chunk_split(base64_encode(hex2bin('302a300506032b6570032100') . $pk), 64, "\n");
            $pem = "-----BEGIN PUBLIC KEY-----\n" . $b64 . "-----END PUBLIC KEY-----\n";
            try {
                // 0 = NULL-digest: единственный корректный способ Ed25519 в openssl_verify
                return openssl_verify($msg, $sig, $pem, 0) === 1;
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false;
    }
}