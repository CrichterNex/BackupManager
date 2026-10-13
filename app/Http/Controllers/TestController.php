<?php

namespace App\Http\Controllers;

use App\Models\BackupTestExecution;
use App\Models\BackupTestFailure;
use App\Services\Audit;
use App\Support\ApiException;
use App\Support\Constants;
use App\Support\Page;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TestController extends Controller
{
    private function executions()
    {
        return BackupTestExecution::query()
            ->join('backup_jobs as j', 'j.id', '=', 'backup_test_executions.backup_job_id')
            ->join('servers as s', 's.id', '=', 'j.server_id')
            ->leftJoin('users as au', 'au.id', '=', 'backup_test_executions.assigned_to')
            ->leftJoin('users as vb', 'vb.id', '=', 'backup_test_executions.verified_by')
            ->select('backup_test_executions.*', 's.hostname', 'au.username as assigned_username', 'vb.username as verified_username');
    }

    public function index(Request $request)
    {
        $q = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'running', 'success', 'failed', 'skipped', 'cancelled'])],
            'test_type' => ['sometimes', Rule::in(Constants::TEST_TYPES)],
            'backup_job_id' => ['sometimes', 'integer', 'min:1'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ]);
        $query = $this->executions();
        isset($q['status']) && $query->where('backup_test_executions.test_status', $q['status']);
        isset($q['test_type']) && $query->where('backup_test_executions.test_type', $q['test_type']);
        isset($q['backup_job_id']) && $query->where('backup_test_executions.backup_job_id', $q['backup_job_id']);
        isset($q['days']) && $query->where('backup_test_executions.created_at', '>=', now()->subDays((int) $q['days']));
        Scope::technician($query, $request->user(), 'j.server_id');
        [$column, $dir] = Page::order($request, ['created_at' => 'backup_test_executions.created_at', 'duration_seconds' => 'backup_test_executions.duration_seconds', 'status' => 'backup_test_executions.test_status'], 'created_at');
        $query->orderBy($column, $dir)->orderByDesc('backup_test_executions.id');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json(['success' => true, 'data' => $rows, 'pagination' => $pagination]);
    }

    public function show(Request $request, int $id)
    {
        $query = $this->executions()->where('backup_test_executions.id', $id);
        Scope::technician($query, $request->user(), 'j.server_id');
        $test = $query->first() ?? throw ApiException::notFound('Test', 'TEST_001');

        return response()->json(['success' => true, 'data' => [...$test->toArray(), 'failures' => BackupTestFailure::where('test_execution_id', $test->id)->orderBy('id')->get()]]);
    }

    /**
     * Bring a waiting test forward so the engine takes it on its next pass. This is how someone skips the wait before a retry
     * (a failed test is retried after a delay) or a scheduled time. It changes when the test runs, not how it counts: a retry is
     * still the same attempt number.
     */
    public function runNow(Request $request, int $id)
    {
        $test = DB::transaction(function () use ($request, $id) {
            $query = $this->executions()->where('backup_test_executions.id', $id);
            Scope::technician($query, $request->user(), 'j.server_id');
            $found = $query->first() ?? throw ApiException::notFound('Test', 'TEST_001');
            $t = BackupTestExecution::lockForUpdate()->find($found->id);
            if ($t->test_status !== 'pending') {
                throw ApiException::conflict('TEST_004', 'Only a test that is waiting can be run now. This one is '.$t->test_status.'.');
            }
            $was = $t->scheduled_time;
            $t->forceFill(['scheduled_time' => now()])->save();
            Audit::log($request, 'test_run_now', 'test', $t->id, ['was_due' => $was?->toIso8601String(), 'attempt' => $t->retry_count + 1]);

            return $t->refresh();
        });

        return response()->json(['success' => true, 'data' => $test], 202);
    }

    private function failures()
    {
        return BackupTestFailure::query()
            ->join('backup_test_executions as t', 't.id', '=', 'backup_test_failures.test_execution_id')
            ->join('backup_jobs as j', 'j.id', '=', 't.backup_job_id')
            ->join('servers as s', 's.id', '=', 'j.server_id')
            ->select('backup_test_failures.*', 't.test_type', 's.hostname');
    }

    public function failureIndex(Request $request)
    {
        $q = $request->validate([
            'resolved' => ['sometimes', Rule::in(['true', 'false'])],
            'severity' => ['sometimes', Rule::in(['critical', 'high', 'medium', 'low'])],
        ]);
        $query = $this->failures();
        isset($q['resolved']) && $query->where('backup_test_failures.resolved', $q['resolved'] === 'true');
        isset($q['severity']) && $query->where('backup_test_failures.severity', $q['severity']);
        Scope::technician($query, $request->user(), 'j.server_id');
        $query->orderByDesc('backup_test_failures.created_at')->orderByDesc('backup_test_failures.id');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json(['success' => true, 'data' => $rows, 'pagination' => $pagination]);
    }

    public function resolve(Request $request, int $id)
    {
        $b = $request->validate([
            'resolution_type' => ['required', Rule::in(['retest_passed', 'backup_rerun', 'backup_fixed', 'false_positive', 'known_issue'])],
            'backup_usable' => ['required', 'boolean:strict'],
            'notes' => ['required', 'string', 'min:1', 'max:5000'],
        ], ['notes.required' => 'Notes are required']);
        $me = $request->user();
        DB::transaction(function () use ($id, $b, $me, $request) {
            $query = $this->failures()->where('backup_test_failures.id', $id);
            Scope::technician($query, $me, 'j.server_id');
            $found = $query->lockForUpdate()->first() ?? throw ApiException::notFound('Test failure', 'TEST_001');
            if ($found->resolved) {
                throw ApiException::conflict('TEST_002', 'This failure has already been resolved');
            }
            BackupTestFailure::whereKey($id)->update([
                'resolved' => true, 'resolution_type' => $b['resolution_type'], 'resolution_notes' => trim($b['notes']),
                'backup_usable' => $b['backup_usable'], 'resolved_by' => $me->id, 'resolved_at' => now(), 'updated_at' => now(),
            ]);
            Audit::log($request, 'failure_resolved', 'test_failure', $id, $b);
        });

        return response()->json(['success' => true, 'data' => $this->failures()->where('backup_test_failures.id', $id)->first()]);
    }
}
