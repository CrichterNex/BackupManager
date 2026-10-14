<?php

namespace App\Http\Controllers;

use App\Models\BackupFailure;
use App\Services\Audit;
use App\Support\ApiException;
use App\Support\Page;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Backup problems that have to be reviewed: what was found, and what was done about it. */
class BackupFailureController extends Controller
{
    private function base()
    {
        return BackupFailure::query()->join('servers as s', 's.id', '=', 'backup_failures.server_id')
            ->leftJoin('users as u', 'u.id', '=', 'backup_failures.resolved_by')
            ->select('backup_failures.*', 's.hostname', 'u.username as resolved_username');
    }

    public function index(Request $request)
    {
        $q = $request->validate(['resolved' => ['sometimes', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])], 'kind' => ['sometimes', Rule::in(['failed', 'critical', 'overdue', 'warning'])]]);
        $query = $this->base()->where('backup_failures.resolved', filter_var($q['resolved'] ?? false, FILTER_VALIDATE_BOOLEAN));
        isset($q['kind']) && $query->where('backup_failures.kind', $q['kind']);
        Scope::technician($query, $request->user(), 's.id');
        // Worst first, then the longest waiting.
        $query->orderByRaw("FIELD(backup_failures.severity, 'critical', 'high', 'medium', 'low')")->orderBy('backup_failures.first_seen_at');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json(['success' => true, 'data' => $rows, 'pagination' => $pagination]);
    }

    public function resolve(Request $request, int $id)
    {
        $b = $request->validate([
            'resolution_type' => ['required', Rule::in(['rerun_succeeded', 'fixed', 'platform_issue', 'false_positive', 'known_issue'])],
            'notes' => ['required', 'string', 'min:1', 'max:5000'],
        ], ['notes.required' => 'Say what you found and what was done, so the next person can see why this was closed.']);
        DB::transaction(function () use ($id, $b, $request) {
            $query = $this->base()->where('backup_failures.id', $id);
            Scope::technician($query, $request->user(), 's.id');
            $found = $query->lockForUpdate()->first() ?? throw ApiException::notFound('Backup failure', 'FAILURE_001');
            if ($found->resolved) {
                throw ApiException::conflict('FAILURE_002', 'This has already been reviewed.');
            }
            BackupFailure::whereKey($id)->update([
                'resolved' => true, 'resolution_type' => $b['resolution_type'], 'resolution_notes' => trim($b['notes']),
                'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'updated_at' => now(),
            ]);
            Audit::log($request, 'backup_failure_reviewed', 'backup_failure', $id, ['server' => $found->hostname, 'kind' => $found->kind, 'resolution' => $b['resolution_type'], 'still_active' => (bool) $found->active]);
        });

        return response()->json(['success' => true, 'data' => $this->base()->where('backup_failures.id', $id)->first()]);
    }
}
