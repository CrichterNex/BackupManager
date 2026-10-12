<?php

namespace App\Services;

use App\Models\AuditEntry;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Audit
{
    /** An event with no tenant, e.g. a login attempt for a username that does not exist. Never attributed to a company. */
    public static function logUntenanted(?Request $request, string $action, ?array $details = null, bool $success = true): void
    {
        DB::table('audit_trail')->insert([
            'company_id' => null, 'user_id' => null, 'action' => $action, 'details' => $details === null ? null : json_encode($details),
            'ip_address' => $request?->ip(), 'success' => $success, 'created_at' => now(),
        ]);
    }

    /**
     * Write an audit row. Call inside the same DB::transaction as the change so the log and the change
     * commit together. $actor is for events with no session yet, e.g. a failed login for a known user.
     */
    public static function log(?Request $request, string $action, ?string $resourceType = null, ?int $resourceId = null, ?array $details = null, bool $success = true, ?array $actor = null): void
    {
        $user = $request?->user();
        $companyId = $actor['company_id'] ?? $user?->company_id ?? CompanyContext::id();
        $userId = $actor['user_id'] ?? $user?->id;
        $row = [
            'user_id' => $userId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'details' => $details,
            'ip_address' => $request?->ip(),
            'success' => $success,
        ];
        if ($companyId === null) {
            // No tenant, e.g. a login attempt for a username that does not exist.
            DB::table('audit_trail')->insert([...$row, 'details' => $details === null ? null : json_encode($details), 'created_at' => now()]);

            return;
        }
        CompanyContext::run($companyId, fn () => AuditEntry::create($row + ['created_at' => now()]));
    }
}
