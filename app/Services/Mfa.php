<?php

namespace App\Services;

use App\Models\User;
use App\Support\ApiException;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Two-step sign-in with an authenticator app. A user is either enrolled (a secret has been proven with a code) or not.
 * Sign-in is two steps: the password gives a short-lived challenge, and a current code (or a one-time recovery code)
 * turns it into a session. When MFA is required, a user who is not yet enrolled can reach nothing but the setup calls.
 */
final class Mfa
{
    public const ISSUER = 'BMS';

    public const RECOVERY_CODES = 10;

    private const CHALLENGE_SECONDS = 300;

    private const MAX_ATTEMPTS_PER_CHALLENGE = 5;

    private const MAX_FAILURES_PER_USER = 6;

    private const FAILURE_WINDOW_SECONDS = 900;

    public static function required(): bool
    {
        return (bool) config('bms.mfa_required');
    }

    public static function enabled(User $u): bool
    {
        return $u->mfa_enabled_at !== null && $u->mfa_secret !== null;
    }

    public static function setupRequired(User $u): bool
    {
        return self::required() && ! self::enabled($u);
    }

    public static function recoveryCodesLeft(User $u): int
    {
        return count(json_decode($u->mfa_recovery_codes ?? '[]', true) ?: []);
    }

    // --- setting up ---

    /** Start (or restart) setup: a new secret that does nothing until a code from it is proven. */
    public static function startSetup(User $u): array
    {
        $secret = Totp::generateSecret();
        $u->forceFill(['mfa_pending_secret' => $secret])->save();
        $uri = Totp::uri($secret, $u->username, self::ISSUER);

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => self::qrSvg($uri)];
    }

    public static function qrSvg(string $text): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($text);
    }

    /**
     * Finish setup by proving the pending secret with a code. From now on the pending secret is the active one.
     *
     * @return list<string> the recovery codes, in plain text. This is the only time they can be seen.
     */
    public static function finishSetup(User $u, string $code): array
    {
        if ($u->mfa_pending_secret === null) {
            throw ApiException::badRequest('Start setup first.');
        }
        $step = Totp::verify($u->mfa_pending_secret, $code);
        if ($step === null) {
            throw new ApiException(400, 'MFA_003', 'That code is not right. Check the time on your phone and try the current code.');
        }
        $codes = self::makeRecoveryCodes();
        $u->forceFill([
            'mfa_secret' => $u->mfa_pending_secret, 'mfa_pending_secret' => null, 'mfa_enabled_at' => now(),
            'mfa_last_step' => $step, 'mfa_recovery_codes' => json_encode(array_map([self::class, 'hashCode'], $codes)),
        ])->save();

        return $codes;
    }

    /** @return list<string> */
    private static function makeRecoveryCodes(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return $codes;
    }

    private static function normaliseRecovery(string $code): string
    {
        return strtolower(preg_replace('/[\s-]/', '', $code));
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', self::normaliseRecovery($code), (string) config('app.key'));
    }

    /** @return list<string> */
    public static function regenerateRecoveryCodes(User $u): array
    {
        $codes = self::makeRecoveryCodes();
        $u->forceFill(['mfa_recovery_codes' => json_encode(array_map([self::class, 'hashCode'], $codes))])->save();

        return $codes;
    }

    // --- checking ---

    /** A current code from the authenticator app. A code that was already used is refused. */
    public static function checkCode(User $u, string $code): bool
    {
        if (! self::enabled($u)) {
            return false;
        }
        $step = Totp::verify($u->mfa_secret, $code, (int) $u->mfa_last_step);
        if ($step === null) {
            return false;
        }
        $u->forceFill(['mfa_last_step' => $step])->save();

        return true;
    }

    /** A code check for someone already signed in, with the same per-user limit on wrong tries as sign-in. */
    public static function guardedCheck(User $u, string $code): bool
    {
        if (RateLimiter::tooManyAttempts('mfa-user:'.$u->id, self::MAX_FAILURES_PER_USER)) {
            throw new ApiException(429, 'MFA_LOCKED', 'Too many wrong codes. Wait a few minutes and try again.');
        }
        if (self::checkCode($u, $code)) {
            RateLimiter::clear('mfa-user:'.$u->id);

            return true;
        }
        RateLimiter::hit('mfa-user:'.$u->id, self::FAILURE_WINDOW_SECONDS);

        return false;
    }

    /** A one-time recovery code. It is used up when it works. */
    public static function useRecoveryCode(User $u, string $code): bool
    {
        $hash = self::hashCode($code);
        $left = json_decode($u->mfa_recovery_codes ?? '[]', true) ?: [];
        $found = null;
        foreach ($left as $i => $h) {
            if (hash_equals($h, $hash)) {
                $found = $i;
            }
        }
        if ($found === null) {
            return false;
        }
        unset($left[$found]);
        $u->forceFill(['mfa_recovery_codes' => json_encode(array_values($left))])->save();

        return true;
    }

    // --- the step between the password and the code ---

    /** A short-lived, tamper-proof note that this user has already given the right password. It is not a session. */
    public static function challenge(User $u): string
    {
        return Crypt::encryptString(json_encode(['u' => $u->id, 'e' => now()->timestamp + self::CHALLENGE_SECONDS, 'n' => Str::random(16)]));
    }

    /** @return array{0: int, 1: string} the user id and the challenge's own id. Refuses a forged, expired or used-up challenge. */
    public static function readChallenge(string $token): array
    {
        try {
            $c = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            $c = null;
        }
        if (! is_array($c) || ! isset($c['u'], $c['e'], $c['n']) || $c['e'] < now()->timestamp) {
            throw ApiException::unauthorized('That sign-in has expired. Enter your password again.', 'MFA_001');
        }
        if (Cache::get('mfa-used:'.$c['n'])) {
            throw ApiException::unauthorized('That sign-in has expired. Enter your password again.', 'MFA_001');
        }

        return [(int) $c['u'], (string) $c['n']];
    }

    public static function consumeChallenge(string $nonce): void
    {
        Cache::put('mfa-used:'.$nonce, true, self::CHALLENGE_SECONDS + 60);
    }

    /** Count a wrong code against the challenge and the user. Returns false when either has run out of tries. */
    public static function allowAttempt(User $u, string $nonce): bool
    {
        $per = 'mfa-ch:'.$nonce;
        $tries = (int) Cache::get($per, 0);
        if ($tries >= self::MAX_ATTEMPTS_PER_CHALLENGE || RateLimiter::tooManyAttempts('mfa-user:'.$u->id, self::MAX_FAILURES_PER_USER)) {
            return false;
        }

        return true;
    }

    public static function recordFailure(User $u, string $nonce): void
    {
        Cache::put('mfa-ch:'.$nonce, (int) Cache::get('mfa-ch:'.$nonce, 0) + 1, self::CHALLENGE_SECONDS + 60);
        RateLimiter::hit('mfa-user:'.$u->id, self::FAILURE_WINDOW_SECONDS);
    }

    public static function clearFailures(User $u): void
    {
        RateLimiter::clear('mfa-user:'.$u->id);
    }

    // --- resetting ---

    /** Remove a user's MFA (lost phone) and end every session they have. They must set it up again to sign in. */
    public static function reset(User $u): void
    {
        DB::transaction(function () use ($u) {
            $u->forceFill([
                'mfa_secret' => null, 'mfa_pending_secret' => null, 'mfa_enabled_at' => null, 'mfa_last_step' => 0, 'mfa_recovery_codes' => null,
            ])->save();
            $u->tokens()->delete();
        });
        self::clearFailures($u);
    }
}
