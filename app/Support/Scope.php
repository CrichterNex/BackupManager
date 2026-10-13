<?php

namespace App\Support;

use App\Models\User;

final class Scope
{
    /** Technicians only see rows for servers assigned to them. Admins and managers see everything. */
    public static function technician($query, User $user, string $serverIdColumn)
    {
        if ($user->effectiveRole() !== 'company_technician') {
            return $query;
        }

        return $query->whereIn($serverIdColumn, fn ($q) => $q->select('server_id')->from('server_assignments')->where('user_id', $user->id));
    }
}
