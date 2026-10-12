<?php

namespace App\Support;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public array $extra = [])
    {
        parent::__construct($message);
    }

    public static function badRequest(string $message, array $extra = []): self
    {
        return new self(400, 'VALIDATION_001', $message, $extra);
    }

    public static function unauthorized(string $message = 'Authentication required', string $code = 'AUTH_002'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do this'): self
    {
        return new self(403, 'AUTH_004', $message);
    }

    public static function notFound(string $what, string $code = 'NOT_FOUND'): self
    {
        return new self(404, $code, "$what not found");
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }
}
