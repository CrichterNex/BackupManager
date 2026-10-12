<?php

namespace App\Http\Controllers;

use App\Models\BackupJob;
use App\Models\BackupTestExecution;
use App\Models\Server;
use App\Services\Audit;
use App\Services\TestResults;
use App\Support\ApiException;
use App\Support\Constants;
use App\Support\Page;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JobController extends Controller
{
    // Five-field cron: minute hour day-of-month month day-of-week.
    private const CRON_FIELD = '/^(\*|\d+(-\d+)?)(\/\d+)?(,(\*|\d+(-\d+)?)(\/\d+)?)*$/';

    private function cronRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $fields = preg_split('/\s+/', trim((string) $value));
            $ok = count($fields) === 5 && collect($fields)->every(fn ($f) => preg_match(self::CRON_FIELD, $f));
            if (! $ok) {
                $fail('Must be a five-field cron expression, e.g. "0 2 * * *"');
            }
        };
    }

    private function base()
    {
        return BackupJob::query()->join('servers', 'servers.id', '=', 'backup_jobs.server_id')->select('backup_jobs.*', 'servers.hostname');
    }

    private function find(int $id): BackupJob
    {
        return $this->base()->where('backup_jobs.id', $id)->first() ?? throw ApiException::notFound('Backup job', 'BACKUP_001');
    }

    public function index(Request $request)
    {
        $q = $request->validate([
            'status' => ['sometimes', Rule::in(Constants::JOB_STATUSES)],
            'method' => ['sometimes', Rule::in(Constants::BACKUP_METHODS)],
            'server_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = $this->base();
        isset($q['status']) && $query->where('backup_jobs.status', $q['status']);
        isset($q['method']) && $query->where('backup_jobs.backup_method', $q['method']);
        isset($q['server_id']) && $query->where('backup_jobs.server_id', $q['server_id']);
        Scope::technician($query, $request->user(), 'backup_jobs.server_id');
        [$column, $dir] = Page::order($request, ['created_at' => 'backup_jobs.created_at', 'status' => 'backup_jobs.status', 'completed' => 'backup_jobs.completed_time', 'hostname' => 'servers.hostname'], 'created_at');
        $query->orderBy($column, $dir)->orderByDesc('backup_jobs.id');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json(['success' => true, 'data' => $rows, 'pagination' => $pagination]);
    }

    public function show(Request $request, int $id)
    {
        $query = $this->base()->where('backup_jobs.id', $id);
        Scope::technician($query, $request->user(), 'backup_jobs.server_id');

        return response()->json(['success' => true, 'data' => $query->first() ?? throw ApiException::notFound('Backup job', 'BACKUP_001')]);
    }

    public function store(Request $request)
    {
        $b = $request->validate([
            'server_id' => ['required', 'integer:strict', 'min:1'],
            'backup_method' => ['required', Rule::in(Constants::BACKUP_METHODS)],
            'schedule' => ['sometimes', 'string', 'max:100', $this->cronRule()],
            'retention_days' => ['sometimes', 'integer:strict', 'min:1', 'max:36500'],
            'incremental' => ['sometimes', 'boolean:strict'],
        ]);
        $job = DB::transaction(function () use ($b, $request) {
            $server = Server::where('active', true)->find($b['server_id']) ?? throw ApiException::notFound('Server', 'SERVER_001');
            if (! in_array($b['backup_method'], $server->backup_methods ?? [], true)) {
                throw ApiException::badRequest("Server is not configured for {$b['backup_method']}. Enable it on the server first.");
            }
            $j = BackupJob::create([
                'server_id' => $b['server_id'], 'backup_method' => $b['backup_method'], 'schedule' => $b['schedule'] ?? null,
                'retention_days' => $b['retention_days'] ?? null, 'incremental' => $b['incremental'] ?? false, 'created_by' => $request->user()->id,
            ]);
            Audit::log($request, 'backup_job_created', 'backup_job', $j->id, $b);

            return $j;
        });

        return response()->json(['success' => true, 'data' => $this->find($job->id)], 201);
    }

    public function update(Request $request, int $id)
    {
        $b = $request->validate([
            'schedule' => ['sometimes', 'nullable', 'string', 'max:100', $this->cronRule()],
            'retention_days' => ['sometimes', 'nullable', 'integer:strict', 'min:1', 'max:36500'],
            'incremental' => ['sometimes', 'boolean:strict'],
            'status' => ['sometimes', Rule::in(Constants::JOB_STATUSES)],
            'status_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'size_gb' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'files_count' => ['sometimes', 'nullable', 'integer:strict', 'min:0'],
            'recovery_point_date' => ['sometimes', 'nullable', 'date'],
        ]);
        if (! $b) {
            throw ApiException::badRequest('No fields to update');
        }
        DB::transaction(function () use ($id, $b, $request) {
            $job = BackupJob::lockForUpdate()->find($id) ?? throw ApiException::notFound('Backup job', 'BACKUP_001');
            $status = $b['status'] ?? null;
            $finished = in_array($status, ['success', 'warning', 'failed'], true);
            $job->fill($b);
            if ($status === 'running') {
                $job->started_time = now();
            }
            if ($finished) {
                $job->completed_time = now();
            }
            $job->save();
            if ($finished) {
                Server::whereKey($job->server_id)->update(['last_backup_time' => now(), 'last_backup_status' => $status, 'updated_at' => now()]);
                if ($status === 'failed') {
                    TestResults::notifyAdmins('backup_failed', 'Backup failed', $b['status_message'] ?? 'A backup job reported failure.', 'backup_job', $id);
                }
            }
            Audit::log($request, 'backup_job_updated', 'backup_job', $id, $b);
        });

        return response()->json(['success' => true, 'data' => $this->find($id)]);
    }

    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($id, $request) {
            $job = BackupJob::lockForUpdate()->find($id) ?? throw ApiException::notFound('Backup job', 'BACKUP_001');
            $job->delete();
            Audit::log($request, 'backup_job_deleted', 'backup_job', $id);
        });

        return response()->noContent();
    }

    // Queue a manual test. The engine picks pending executions up and records the result.
    public function queueTest(Request $request, int $id)
    {
        $data = $request->validate(['test_type' => ['sometimes', Rule::in(Constants::TEST_TYPES)]]);
        $type = $data['test_type'] ?? 'random_restore';
        $test = DB::transaction(function () use ($id, $type, $request) {
            $query = BackupJob::query()->whereKey($id);
            Scope::technician($query, $request->user(), 'backup_jobs.server_id');
            $query->lockForUpdate()->first() ?? throw ApiException::notFound('Backup job', 'BACKUP_001');
            if (BackupTestExecution::where('backup_job_id', $id)->where('test_type', $type)->whereIn('test_status', ['pending', 'running'])->exists()) {
                throw ApiException::conflict('TEST_003', 'A test of this type is already queued or running for this job');
            }
            $t = BackupTestExecution::create(['backup_job_id' => $id, 'test_type' => $type, 'executed_by' => $request->user()->id]);
            Audit::log($request, 'test_triggered', 'test', $t->id, ['backup_job_id' => $id, 'test_type' => $type]);

            return $t->refresh();
        });

        return response()->json(['success' => true, 'data' => $test], 202);
    }
}
