<?php

namespace App\Support;

/**
 * The company every tenant query is restricted to. Set once per request from the authenticated user
 * (never from request input) or per company by the engine. With no company set, tenant models match nothing.
 */
final class CompanyContext
{
    private static ?int $id = null;

    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    /** Run work as one company, then restore whatever was set before. */
    public static function run(int $companyId, callable $fn): mixed
    {
        $previous = self::$id;
        self::$id = $companyId;
        try {
            return $fn();
        } finally {
            self::$id = $previous;
        }
    }
}
