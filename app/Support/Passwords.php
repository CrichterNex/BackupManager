<?php

namespace App\Support;

final class Passwords
{
    public static function assertStrong(string $password): void
    {
        if (strlen($password) < config('bms.min_password_length')) {
            throw ApiException::badRequest('Password must be at least '.config('bms.min_password_length').' characters');
        }
        if (strlen($password) > 200) {
            throw ApiException::badRequest('Password is too long');
        }
    }
}
