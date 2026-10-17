<?php

declare(strict_types=1);

namespace App\Support;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30s) implemented with hash_hmac; verified against the RFC 4226/6238 vectors in tests. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, int $step): string
    {
        $binary = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binary, self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Returns the matched time step (for replay protection) or null. */
    public static function verify(string $secret, string $code, ?int $now = null, int $window = 1, ?int $lastStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $current = intdiv($now ?? time(), 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $current + $offset;
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($encoded, '='))) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) {
                continue;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
