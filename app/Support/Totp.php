<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Time-based one-time passwords as authenticator apps compute them (RFC 6238, built on RFC 4226): HMAC-SHA1 over the
 * count of 30-second steps since 1970, cut down to 6 digits. The secret is shared as base32 text (RFC 4648).
 */
final class Totp
{
    public const STEP_SECONDS = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random 160-bit secret, as base32 text. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                throw new \InvalidArgumentException('Not a base32 secret');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    public static function step(?int $time = null): int
    {
        // Carbon's clock, so tests can move time forward. In normal running it is the real time.
        return intdiv($time ?? Carbon::now()->timestamp, self::STEP_SECONDS);
    }

    /** The code for one 30-second step. */
    public static function codeAt(string $secret, int $step): string
    {
        $mac = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($mac[19]) & 0x0F;
        $n = ((ord($mac[$offset]) & 0x7F) << 24) | (ord($mac[$offset + 1]) << 16) | (ord($mac[$offset + 2]) << 8) | ord($mac[$offset + 3]);

        return str_pad((string) ($n % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Check a code, allowing one step either side for clock drift. Returns the step the code belongs to, or null.
     * A step at or below $lastUsedStep is refused, so a code that was already accepted cannot be used again.
     */
    public static function verify(string $secret, string $code, int $lastUsedStep = 0, ?int $time = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }
        $now = self::step($time);
        $match = null;
        // Every step in the window is compared, without stopping early, so timing does not reveal which one matched.
        for ($s = $now - $window; $s <= $now + $window; $s++) {
            if (hash_equals(self::codeAt($secret, $s), $code) && $s > $lastUsedStep) {
                $match = $s;
            }
        }

        return $match;
    }

    /** The address an authenticator app reads from the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer), self::DIGITS, self::STEP_SECONDS);
    }
}
