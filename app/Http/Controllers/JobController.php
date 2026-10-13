<?php

namespace App\Http\Controllers;

use App\Models\BackupJob;
use App\Models\BackupLocation;
use App\Models\BackupTestExecution;
use App\Models\Server;
use App\Services\Audit;
use App\Services\CompanyTests;
use App\Services\TestResults;
use App\Support\ApiException;
use App\Support\BackupPaths;
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
        return BackupJob::query()->join('servers', 'servers.id', '=', 'backup_jobs.server_id')
            ->leftJoin('backup_locations as bl', 'bl.id', '=', 'backup_jobs.location_id')
            ->select('backup_jobs.*', 'servers.hostname', 'bl.name as location_name', 'bl.loc_key as location_key');
    }

    /**
     * What the job covers and where in its location the data lands. Returns only the columns this request changes.
     * The scope is what the admin says the job covers. BMS records and shows it, and does not enforce it on the backup tool.
     */
    private function scopeAndDestination(array $b, ?BackupJob $existing): array
    {
        $out = [];
        if (array_key_exists('scope_type', $b) || array_key_exists('source_paths', $b) || array_key_exists('excluded_paths', $b)) {
            $type = array_key_exists('scope_type', $b) ? $b['scope_type'] : $existing?->scope_type;
            $paths = array_key_exists('source_paths', $b) ? ($b['source_paths'] ?? []) : ($existing?->source_paths ?? []);
            $excluded = array_key_exists('excluded_paths', $b) ? ($b['excluded_paths'] ?? []) : ($existing?->excluded_paths ?? []);
            if ($type === 'folders') {
                $list = BackupPaths::sourceList($paths);
                if (! $list) {
                    throw ApiException::badRequest('List at least one folder, or choose full server.');
                }
                // Checked against the folder list as it will be after this change, so editing either one re-checks both.
                $out += ['scope_type' => 'folders', 'source_paths' => $list, 'excluded_paths' => BackupPaths::exclusionList($excluded, $list) ?: null];
            } else {
                if ($paths) {
                    throw ApiException::badRequest($type === 'full_server' ? 'A full-server backup has no folder list. Choose specific folders to list some.' : 'Choose "specific folders" to give a folder list.');
                }
                if ($excluded && $type === null) {
                    throw ApiException::badRequest('Choose what the job backs up (full server or specific folders) before excluding folders.');
                }
                $out += ['scope_type' => $type, 'source_paths' => null, 'excluded_paths' => BackupPaths::exclusionList($excluded, null) ?: null];
            }
        }
        // How the backup tool lays the source out in the destination. BMS uses it to trace a backed-up file to its source
        // file, so it has to make sense for what the job covers, and is re-checked whenever the scope changes.
        if (array_key_exists('layout', $b) || array_key_exists('scope_type', $out)) {
            $layout = array_key_exists('layout', $b) ? $b['layout'] : $existing?->layout;
            $type = array_key_exists('scope_type', $out) ? $out['scope_type'] : $existing?->scope_type;
            $folders = array_key_exists('source_paths', $out) ? $out['source_paths'] : $existing?->source_paths;
            BackupPaths::assertLayout($layout, $type, $folders ?? []);
            if (array_key_exists('layout', $b)) {
                $out['layout'] = $layout;
            }
        }
        $locationId = array_key_exists('location_id', $b) ? $b['location_id'] : $existing?->location_id;
        if (array_key_exists('destination_path', $b)) {
            $dest = $b['destination_path'] === null ? null : BackupPaths::destination((string) $b['destination_path']);
            if ($dest !== null && $locationId === null) {
                throw ApiException::badRequest('Set a backup location before giving a destination folder.');
            }
            $out['destination_path'] = $dest;
        } elseif (array_key_exists('location_id', $b) && $b['location_id'] === null) {
            $out['destination_path'] = null; // no location, so nothing for the folder to be inside
        }

        return $out;
    }

    /**
     * A location can only be set on a method where BMS-managed copies go to a destination BMS knows about. Platform
     * backups (Acronis, Veeam, ...) use the locations configured on the platform, so BMS locations do not apply.
     */
    private function checkLocation(string $method, ?int $locationId): ?int
    {
        if ($locationId === null) {
            return null;
        }
        if (! in_array($method, Constants::LOCATION_METHODS, true)) {
            throw ApiException::badRequest('Backups made by '.str_replace('_', ' ', $method).' use the locations configured on that platform, so a BMS backup location cannot be set.');
        }
        BackupLocation::find($locationId) ?? throw ApiException::notFound('Backup location', 'LOCATION_001');

        return $locationId;
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
            'location_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'scope_type' => ['sometimes', 'nullable', Rule::in(['full_server', 'folders'])],
            'source_paths' => ['sometimes', 'nullable', 'array', 'max:200'],
            'source_paths.*' => ['string', 'max:512'],
            'excluded_paths' => ['sometimes', 'nullable', 'array', 'max:200'],
            'excluded_paths.*' => ['string', 'max:512'],
            'destination_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'layout' => ['sometimes', 'nullable', Rule::in(['contents', 'by_folder_name', 'full_path'])],
        ]);
        $job = DB::transaction(function () use ($b, $request) {
            $server = Server::where('active', true)->find($b['server_id']) ?? throw ApiException::notFound('Server', 'SERVER_001');
            if (! in_array($b['backup_method'], $server->backup_methods ?? [], true)) {
                throw ApiException::badRequest("Server is not configured for {$b['backup_method']}. Enable it on the server first.");
            }
            $j = BackupJob::create([
                'server_id' => $b['server_id'], 'backup_method' => $b['backup_method'], 'location_id' => $this->checkLocation($b['backup_method'], $b['location_id'] ?? null), 'schedule' => $b['schedule'] ?? null,
                ...$this->scopeAndDestination($b, null),
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
            'location_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'scope_type' => ['sometimes', 'nullable', Rule::in(['full_server', 'folders'])],
            'source_paths' => ['sometimes', 'nullable', 'array', 'max:200'],
            'source_paths.*' => ['string', 'max:512'],
            'excluded_paths' => ['sometimes', 'nullable', 'array', 'max:200'],
            'excluded_paths.*' => ['string', 'max:512'],
            'destination_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'layout' => ['sometimes', 'nullable', Rule::in(['contents', 'by_folder_name', 'full_path'])],
        ]);
        if (! $b) {
            throw ApiException::badRequest('No fields to update');
        }
        DB::transaction(function () use ($id, $b, $request) {
            $job = BackupJob::lockForUpdate()->find($id) ?? throw ApiException::notFound('Backup job', 'BACKUP_001');
            $status = $b['status'] ?? null;
            $finished = in_array($status, ['success', 'warning', 'failed'], true);
            if (array_key_exists('location_id', $b)) {
                $this->checkLocation($job->backup_method, $b['location_id']);
            }
            $columns = $this->scopeAndDestination($b, $job);
            unset($b['scope_type'], $b['source_paths'], $b['excluded_paths'], $b['destination_path'], $b['layout']);
            $job->fill($b + $columns);
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
        CompanyTests::assertActive($type);
        $test = DB::transaction(function () use ($id, $type, $request) {
            $query = BackupJob::query()->whereKey($id);
            Scope::technician($query, $request->user(), 'backup_jobs.server_id');
            $query->lockForUpdate()->first() ?? throw ApiException::notFound('Backup job', 'BACKUP_001');
            if (BackupTestExecution::where('backup_job_id', $id)->where('test_type', $type)->where('manual', false)->whereIn('test_status', ['pending', 'running'])->exists()) {
                throw ApiException::conflict('TEST_003', 'A test of this type is already queued or running for this job');
            }
            $t = BackupTestExecution::create(['backup_job_id' => $id, 'test_type' => $type, 'executed_by' => $request->user()->id]);
            Audit::log($request, 'test_triggered', 'test', $t->id, ['backup_job_id' => $id, 'test_type' => $type]);

            return $t->refresh();
        });

        return response()->json(['success' => true, 'data' => $test], 202);
    }
}
