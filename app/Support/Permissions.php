<?php

namespace App\Support;

/** Permission matrix from SINGLE_COMPANY_PER_USER.md, Part 6. */
final class Permissions
{
    public const ALL = ['company_admin', 'company_manager', 'company_technician'];

    public const MATRIX = [
        'servers:read' => self::ALL,
        'servers:write' => ['company_admin'],
        'jobs:read' => self::ALL,
        'jobs:write' => ['company_admin'],
        'backups:run' => ['company_admin'],
        'integrations:manage' => ['company_admin'],
        'tests:read' => self::ALL,
        'tests:run' => ['company_admin', 'company_technician'],
        'failures:resolve' => ['company_admin', 'company_technician'],
        'users:manage' => ['company_admin'],
        'reports:read' => ['company_admin', 'company_manager'],
        'reports:generate' => ['company_admin', 'company_manager'],
        'audit:read' => ['company_admin'],
        'dashboard:read' => self::ALL,
    ];

    public static function can(string $role, string $permission): bool
    {
        return in_array($role, self::MATRIX[$permission] ?? [], true);
    }
}
