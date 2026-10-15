<?php

namespace App\Http\Controllers;

use App\Models\BackupJob;
use App\Models\BackupTestExecution;
use App\Models\Server;
use App\Models\TestEvidence;
use App\Services\Audit;
use App\Services\CompanyTests;
use App\Services\Engine\Engine;
use App\Services\PlatformWorkloads;
use App\Services\TestResults;
use App\Support\ApiException;
use App\Support\Constants;
use App\Support\ManualChecklists;
use App\Support\ManualFindings;
use App\Support\ManualSelection;
use App\Support\Page;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Tests a person does by hand: pick a target, follow the checklist, attach evidence (fingerprints, notes, files) and complete it as passed or
 * failed. The result goes through TestResults::record() like every other, so failures, alerts and reports behave the same.
 */
class ManualTestController extends Controller
{
    private const HASH_LENGTHS = ['sha256' => 64, 'sha1' => 40, 'md5' => 32, 'sha512' => 128];

    private const FILE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'pdf', 'txt', 'csv', 'json', 'log'];

    /** What the file's own content must look like for each extension. The name alone proves nothing, so a script renamed to .png is refused. */
    private const MIME_BY_EXTENSION = [
        'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'pdf' => ['application/pdf'],
        'txt' => ['text/plain'], 'log' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'], 'json' => ['application/json', 'text/plain', 'text/json'],
    ];

    private const MAX_FILE_KB = 5120;

    private const MAX_FILES = 20;

    private const MAX_EVIDENCE = 100;

    private const FAILURE_TYPES = ['file_missing', 'hash_mismatch', 'corruption', 'timeout', 'access_denied', 'restore_failed'];

    private function base()
    {
        return BackupTestExecution::query()->where('backup_test_executions.manual', true)
            ->join('backup_jobs as j', 'j.id', '=', 'backup_test_executions.backup_job_id')
            ->join('servers as s', 's.id', '=', 'j.server_id')
            ->leftJoin('users as au', 'au.id', '=', 'backup_test_executions.assigned_to')
            ->leftJoin('users as vb', 'vb.id', '=', 'backup_test_executions.verified_by')
            ->select('backup_test_executions.*', 's.hostname', 's.os_type', 'j.backup_method', 'au.username as assigned_username', 'vb.username as verified_username');
    }

    private function find(Request $request, int $id): BackupTestExecution
    {
        $q = $this->base()->where('backup_test_executions.id', $id);
        Scope::technician($q, $request->user(), 'j.server_id');

        return $q->first() ?? throw ApiException::notFound('Manual test', 'TEST_001');
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()->effectiveRole() === 'company_admin';
    }

    private function canEdit(Request $request, BackupTestExecution $t): bool
    {
        return $this->isAdmin($request) || (int) $t->assigned_to === $request->user()->id;
    }

    /** A screenshot is what the checklist asks for as proof: a PNG or JPEG picture. */
    private function isScreenshot($e): bool
    {
        $name = is_array($e) ? ($e['file_name'] ?? '') : ($e->file_name ?? '');
        $kind = is_array($e) ? ($e['kind'] ?? '') : $e->kind;

        return $kind === 'file' && (bool) preg_match('/\.(png|jpe?g)$/i', (string) $name);
    }

    /** The checklist for this test: the version with a reference item if it was given one. */
    private function template(BackupTestExecution $t): array
    {
        return ManualChecklists::for($t->test_type, ! empty($t->reference));
    }

    /** The test must be started, belong to the caller (or an admin), and not be finished. */
    private function editable(Request $request, BackupTestExecution $t): void
    {
        if ($t->test_status === 'pending') {
            throw ApiException::conflict('TEST_005', 'Start the test first.');
        }
        if ($t->test_status !== 'running') {
            throw ApiException::conflict('TEST_005', 'This test is already '.$t->test_status.'.');
        }
        if (! $this->canEdit($request, $t)) {
            throw ApiException::forbidden('This test is being done by '.($t->assigned_username ?? 'someone else').'.');
        }
    }

    /** The whole test as the tester sees it: where it is, what to do, what is ticked and what evidence is attached. */
    private function present(Request $request, BackupTestExecution $t): array
    {
        $tpl = $this->template($t);
        $done = collect($t->checklist ?? [])->mapWithKeys(fn ($c) => [$c['id'] => (bool) $c['done']]);
        $evidence = TestEvidence::where('test_execution_id', $t->id)->orderBy('id')->get()->map(fn ($e) => $e->toArray() + ['has_file' => $e->file_path !== null]);
        $help = Constants::TEST_HELP[$t->test_type] ?? null;

        return [
            'id' => $t->id, 'test_type' => $t->test_type, 'test_status' => $t->test_status, 'title' => $help['title'] ?? $t->test_type, 'summary_help' => $help['summary'] ?? null,
            'hostname' => $t->hostname, 'os_type' => $t->os_type, 'backup_method' => $t->backup_method, 'backup_job_id' => $t->backup_job_id,
            'target' => $t->target, 'target_label' => $tpl['target'], 'reference' => $t->reference, 'scheduled_time' => $t->scheduled_time, 'started_time' => $t->started_time, 'completed_time' => $t->completed_time,
            'assigned_to' => $t->assigned_to, 'assigned_username' => $t->assigned_username, 'can_edit' => $t->test_status === 'running' && $this->canEdit($request, $t),
            'can_start' => $t->test_status === 'pending' || ($t->test_status === 'running' && $this->isAdmin($request) && (int) $t->assigned_to !== $request->user()->id),
            'result_summary' => $t->summary, 'error_message' => $t->error_message,
            'steps' => array_map(fn ($s) => $s + ['done' => (bool) ($done[$s['id']] ?? false), 'evidence_items' => $evidence->where('step_id', $s['id'])->values(),
                'screenshots_attached' => $evidence->where('step_id', $s['id'])->filter(fn ($e) => $this->isScreenshot($e))->count()], $tpl['steps']),
            'other_evidence' => $evidence->whereNull('step_id')->values(),
            'selection' => $t->selection,
            'fields' => array_map(fn ($f) => $f + ['value' => ($t->findings ?? [])[$f['id']] ?? null], ManualChecklists::fields($t->test_type)),
            'verification' => ['state' => $t->verify_state, 'verified_by' => $t->verified_username, 'verified_at' => $t->verified_at, 'note' => $t->verify_note],
            'can_verify' => $t->verify_state === 'pending' && (int) $t->assigned_to !== $request->user()->id && (int) $t->executed_by !== $request->user()->id,
        ];
    }

    // ---------------------------------------------------------------- reading

    public function index(Request $request)
    {
        $q = $request->validate(['status' => ['sometimes', Rule::in(['open', 'done', 'all', 'verify'])], 'assigned_to_me' => ['sometimes', 'boolean']]);
        $query = $this->base();
        $status = $q['status'] ?? 'open';
        $status === 'open' && $query->whereIn('backup_test_executions.test_status', ['pending', 'running']);
        $status === 'done' && $query->whereNotIn('backup_test_executions.test_status', ['pending', 'running']);
        $status === 'verify' && $query->where('backup_test_executions.verify_state', 'pending');
        ! empty($q['assigned_to_me']) && $query->where('backup_test_executions.assigned_to', $request->user()->id);
        Scope::technician($query, $request->user(), 'j.server_id');
        $query->orderByRaw("backup_test_executions.test_status = 'running' DESC")->orderBy('backup_test_executions.scheduled_time')->orderBy('backup_test_executions.id');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json(['success' => true, 'data' => $rows->map(fn ($t) => [
            'id' => $t->id, 'test_type' => $t->test_type, 'test_status' => $t->test_status, 'hostname' => $t->hostname, 'os_type' => $t->os_type, 'backup_method' => $t->backup_method,
            'target' => $t->target, 'assigned_username' => $t->assigned_username, 'assigned_to' => $t->assigned_to, 'scheduled_time' => $t->scheduled_time,
            'started_time' => $t->started_time, 'completed_time' => $t->completed_time, 'summary' => $t->summary, 'notes' => $t->notes ? 'yes' : null,
            'verify_state' => $t->verify_state, 'verified_username' => $t->verified_username,
            'can_verify' => $t->verify_state === 'pending' && (int) $t->assigned_to !== $request->user()->id && (int) $t->executed_by !== $request->user()->id,
        ]), 'pagination' => $pagination]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))]);
    }

    // ---------------------------------------------------------------- creating and starting

    /** One sample item from the server's list: the one tested least recently, and never the same one twice in a row while others are waiting. */
    public static function pickTarget(Server $server, ?string $testType = null): ?string
    {
        $targets = $server->test_targets ?? [];
        if (! $targets) {
            // No list of the tenant's own: any mailbox, OneDrive or site Acronis has backed up, one not tested lately where there is a choice.
            $recent = $testType ? BackupTestExecution::query()->join('backup_jobs as j', 'j.id', '=', 'backup_test_executions.backup_job_id')->where('j.server_id', $server->id)
                ->where('backup_test_executions.test_type', $testType)->whereNotNull('backup_test_executions.target')->orderByDesc('backup_test_executions.id')->limit(200)->pluck('backup_test_executions.target')->all() : [];

            return $testType ? PlatformWorkloads::pick($server, $testType, $recent)['name'] ?? null : null;
        }
        $last = BackupTestExecution::query()->join('backup_jobs as j', 'j.id', '=', 'backup_test_executions.backup_job_id')
            ->where('j.server_id', $server->id)->where('backup_test_executions.manual', true)->whereIn('backup_test_executions.target', $targets)
            ->selectRaw('backup_test_executions.target as t, MAX(backup_test_executions.created_at) as at')->groupBy('backup_test_executions.target')->pluck('at', 't');
        $oldest = collect($targets)->map(fn ($t) => ['t' => $t, 'at' => $last[$t] ?? '0000'])->sortBy('at')->values();
        $pool = $oldest->where('at', $oldest->first()['at'])->pluck('t')->all();

        return $pool[array_rand($pool)];
    }

    /**
     * A known file from the tenant's list, a different one from last time when there is a choice. The tester restores it and BMS holds its
     * fingerprint, so the original never has to be found. Null when the tenant has none, and the plainer checklist is used.
     *
     * @return array{label: string, algorithm: string, hash: string, where: ?string}|null
     */
    public static function pickReference(Server $server): ?array
    {
        $items = $server->reference_items ?? [];
        if (! $items) {
            return null;
        }
        $last = BackupTestExecution::query()->join('backup_jobs as j', 'j.id', '=', 'backup_test_executions.backup_job_id')
            ->where('j.server_id', $server->id)->where('backup_test_executions.manual', true)->whereNotNull('backup_test_executions.reference')
            ->orderByDesc('backup_test_executions.id')->value('backup_test_executions.reference');
        // The model's array cast hands it back already decoded.
        $lastLabel = is_array($last) ? ($last['label'] ?? null) : ($last ? (json_decode((string) $last, true)['label'] ?? null) : null);
        $pool = count($items) > 1 ? array_values(array_filter($items, fn ($i) => $i['label'] !== $lastLabel)) : $items;

        return $pool[array_rand($pool)];
    }

    /**
     * Start a test by hand for a server, with no backup job needed. It uses the server's newest backup job, and where there is none (a Microsoft 365
     * tenant BMS has not read a backup for, say) a stand-in job that never ran, so the test has somewhere to live without inventing a backup. A job
     * that never ran is not counted in reports or backup statistics.
     */
    public function storeForServer(Request $request, int $serverId)
    {
        $request->validate(['test_type' => ['required', Rule::in(Constants::TEST_TYPES)]]);
        CompanyTests::assertActive($request->input('test_type')); // before any stand-in job is made, so a refused request leaves nothing behind
        $q = Server::query()->where('active', true)->whereKey($serverId);
        Scope::technician($q, $request->user(), 'servers.id');
        $server = $q->first() ?? throw ApiException::notFound('Server', 'SERVER_001');

        return $this->store($request, self::jobFor($server, $request->user()->id)->id);
    }

    /** The server's newest backup job, preferring a Microsoft 365 one, or a stand-in that never ran when it has none. */
    public static function jobFor(Server $server, ?int $userId): BackupJob
    {
        return BackupJob::where('server_id', $server->id)->orderByRaw("backup_method IN ('acronis_m365', 'microsoft_native_365') DESC")->orderByDesc('id')->first()
            ?? BackupJob::create(['is_record' => true, 'server_id' => $server->id, 'status' => 'scheduled', 'created_by' => $userId,
                'backup_method' => $server->os_type === 'microsoft_365' ? (array_values(array_intersect($server->backup_methods ?? [], ['acronis_m365', 'microsoft_native_365']))[0] ?? 'acronis_m365') : ($server->backup_methods[0] ?? 'manual_copy'),
                'status_message' => 'Stand-in for tests done by hand. No backup has been reported for this server.']);
    }

    public function store(Request $request, int $jobId)
    {
        $b = $request->validate(['test_type' => ['required', Rule::in(Constants::TEST_TYPES)], 'target' => ['sometimes', 'nullable', 'string', 'max:500']]);
        CompanyTests::assertActive($b['test_type']);
        $t = DB::transaction(function () use ($request, $jobId, $b) {
            $q = BackupJob::query()->whereKey($jobId);
            Scope::technician($q, $request->user(), 'backup_jobs.server_id');
            $job = $q->lockForUpdate()->first() ?? throw ApiException::notFound('Backup job', 'BACKUP_001');
            if (BackupTestExecution::where('backup_job_id', $jobId)->where('test_type', $b['test_type'])->where('manual', true)->whereIn('test_status', ['pending', 'running'])->exists()) {
                throw ApiException::conflict('TEST_003', 'A manual test of this type is already waiting or in progress for this job.');
            }
            $server = Server::findOrFail($job->server_id);
            $target = isset($b['target']) && trim($b['target']) !== '' ? trim($b['target']) : self::pickTarget($server, $b['test_type']);
            // Only the Microsoft 365 mailbox test uses a reference item, and the item is copied into the test so later edits to the list cannot change it.
            $reference = $b['test_type'] === 'm365_mailbox' ? self::pickReference($server) : null;
            // A mailbox, a OneDrive and a site are all sampled the same way: a day and a position, chosen now so the tester cannot pick an easy one.
            $selection = in_array($b['test_type'], Constants::M365_TEST_TYPES, true) ? ManualSelection::generate($server->timezone ?: 'UTC', $b['test_type']) : null;
            $test = BackupTestExecution::create(['backup_job_id' => $jobId, 'test_type' => $b['test_type'], 'manual' => true, 'target' => $target, 'reference' => $reference, 'selection' => $selection, 'scheduled_time' => now()]);
            Audit::log($request, 'manual_test_created', 'test', $test->id, ['backup_job_id' => $jobId, 'test_type' => $b['test_type'], 'target' => $target]);

            return $test;
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $t->id))], 201);
    }

    public function start(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            if (! in_array($t->test_status, ['pending', 'running'], true)) {
                throw ApiException::conflict('TEST_005', 'This test is already '.$t->test_status.'.');
            }
            $me = $request->user()->id;
            if ($t->test_status === 'running' && (int) $t->assigned_to !== $me && ! $this->isAdmin($request)) {
                throw ApiException::conflict('TEST_006', 'Someone else is already doing this test. An admin can take it over.');
            }
            $took = $t->test_status === 'running' && (int) $t->assigned_to !== $me;
            $t->forceFill(['test_status' => 'running', 'assigned_to' => $me, 'executed_by' => $me, 'started_time' => $t->started_time ?? now()])->save();
            Audit::log($request, $took ? 'manual_test_taken_over' : 'manual_test_started', 'test', $t->id, ['test_type' => $t->test_type]);
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))]);
    }

    // ---------------------------------------------------------------- doing it

    public function save(Request $request, int $id)
    {
        $b = $request->validate(['checklist' => ['required', 'array', 'max:50'], 'checklist.*.id' => ['required', 'string', 'max:40'], 'checklist.*.done' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $id, $b) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            $this->editable($request, $t);
            $valid = array_column($this->template($t)['steps'], 'id');
            $list = [];
            foreach ($b['checklist'] as $c) {
                if (! in_array($c['id'], $valid, true)) {
                    throw ApiException::badRequest('"'.$c['id'].'" is not a step of this checklist.');
                }
                $list[$c['id']] = ['id' => $c['id'], 'done' => (bool) $c['done']];
            }
            $t->forceFill(['checklist' => array_values($list)])->save();
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))]);
    }

    private function evidenceCount(int $testId): int
    {
        return TestEvidence::where('test_execution_id', $testId)->count();
    }

    private function stepOrNull(BackupTestExecution $t, ?string $step): ?string
    {
        if ($step !== null && ! in_array($step, array_column($this->template($t)['steps'], 'id'), true)) {
            throw ApiException::badRequest('"'.$step.'" is not a step of this checklist.');
        }

        return $step;
    }

    /** A fingerprint is hex of the right length for its algorithm. Anything else is a typo, and a typo must not pass for a match. */
    private function cleanHash(string $algorithm, ?string $value, string $which): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = strtolower(preg_replace('/\s+/', '', $value));
        if (! preg_match('/^[0-9a-f]+$/', $v) || strlen($v) !== self::HASH_LENGTHS[$algorithm]) {
            throw ApiException::badRequest("The $which fingerprint is not a valid ".strtoupper($algorithm).' value: it must be '.self::HASH_LENGTHS[$algorithm].' hexadecimal characters.');
        }

        return $v;
    }

    public function addEvidence(Request $request, int $id)
    {
        $b = $request->validate([
            'kind' => ['required', Rule::in(['hash', 'note'])], 'step_id' => ['sometimes', 'nullable', 'string', 'max:40'], 'label' => ['required', 'string', 'min:1', 'max:200'],
            'text' => ['required_if:kind,note', 'nullable', 'string', 'max:5000'],
            'algorithm' => ['sometimes', 'nullable', Rule::in(array_keys(self::HASH_LENGTHS))],
            'original' => ['sometimes', 'nullable', 'string', 'max:300'], 'restored' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        $e = DB::transaction(function () use ($request, $id, $b) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            $this->editable($request, $t);
            if ($this->evidenceCount($t->id) >= self::MAX_EVIDENCE) {
                throw ApiException::conflict('TEST_007', 'This test already has the maximum amount of evidence.');
            }
            $step = $this->stepOrNull($t, $b['step_id'] ?? null);
            $data = ['test_execution_id' => $t->id, 'step_id' => $step, 'kind' => $b['kind'], 'label' => trim($b['label']), 'created_by' => $request->user()->id];
            $refStep = $step !== null && $t->reference && (collect($this->template($t)['steps'])->firstWhere('id', $step)['reference'] ?? false);
            if ($b['kind'] === 'note') {
                $data['value_a'] = trim((string) $b['text']);
            } elseif ($refStep) {
                // The original is the fingerprint BMS holds for the reference item. The tester supplies only the restored copy's, and cannot change the original.
                $ref = $t->reference;
                if (trim((string) ($b['restored'] ?? '')) === '') {
                    throw ApiException::badRequest('Enter the fingerprint of the restored copy. BMS compares it with the expected one.');
                }
                $data += ['algorithm' => $ref['algorithm'], 'value_a' => $ref['hash'], 'value_b' => $this->cleanHash($ref['algorithm'], $b['restored'], 'restored')];
                $data['label'] = $ref['label'];
            } else {
                if (! isset($b['algorithm'])) {
                    throw ApiException::badRequest('Choose the algorithm the fingerprints were made with.');
                }
                $data += ['algorithm' => $b['algorithm'], 'value_a' => $this->cleanHash($b['algorithm'], $b['original'] ?? null, 'original'), 'value_b' => $this->cleanHash($b['algorithm'], $b['restored'] ?? null, 'restored')];
                if ($data['value_a'] === null) {
                    throw ApiException::badRequest('Enter the original\'s fingerprint.');
                }
            }
            $e = TestEvidence::create($data);
            Audit::log($request, 'manual_test_evidence_added', 'test', $t->id, ['kind' => $b['kind'], 'label' => $data['label'], 'step' => $step]);

            return $e;
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))], 201);
    }

    public function addFile(Request $request, int $id)
    {
        $request->validate(['file' => ['required', 'file', 'max:'.self::MAX_FILE_KB], 'step_id' => ['sometimes', 'nullable', 'string', 'max:40'], 'label' => ['sometimes', 'nullable', 'string', 'max:200']]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::MIME_BY_EXTENSION[$ext] ?? [], true)) {
            throw ApiException::badRequest('Attach a screenshot or document: '.implode(', ', self::FILE_EXTENSIONS).' up to '.(self::MAX_FILE_KB / 1024).' MB.');
        }
        $name = mb_substr(preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($file->getClientOriginalName())), 0, 100) ?: "attachment.$ext";
        DB::transaction(function () use ($request, $id, $file, $ext, $name) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            $this->editable($request, $t);
            if (TestEvidence::where('test_execution_id', $t->id)->where('kind', 'file')->count() >= self::MAX_FILES) {
                throw ApiException::conflict('TEST_007', 'This test already has the maximum number of attached files ('.self::MAX_FILES.').');
            }
            $step = $this->stepOrNull($t, $request->input('step_id') ?: null);
            // A random name on private storage: nothing here is reachable except through the download call below, which checks who is asking.
            $path = Storage::disk('local')->putFileAs("evidence/{$t->company_id}/{$t->id}", $file, bin2hex(random_bytes(16)).".$ext");
            TestEvidence::create(['test_execution_id' => $t->id, 'step_id' => $step, 'kind' => 'file', 'label' => trim((string) $request->input('label')) ?: $name,
                'file_path' => $path, 'file_name' => $name, 'file_size' => $file->getSize(), 'created_by' => $request->user()->id]);
            Audit::log($request, 'manual_test_evidence_added', 'test', $t->id, ['kind' => 'file', 'file' => $name, 'step' => $step]);
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))], 201);
    }

    public function downloadFile(Request $request, int $id, int $evidenceId)
    {
        $t = $this->find($request, $id);
        $e = TestEvidence::where('test_execution_id', $t->id)->where('kind', 'file')->find($evidenceId) ?? throw ApiException::notFound('Attachment', 'TEST_008');
        if (! $e->file_path || ! Storage::disk('local')->exists($e->file_path)) {
            throw ApiException::notFound('Attachment', 'TEST_008');
        }

        // A screenshot can be shown to someone reviewing it, but only a PNG or JPEG that the file's own content confirms. Everything else is a download.
        if ($request->boolean('inline') && $this->isScreenshot($e)) {
            $full = Storage::disk('local')->path($e->file_path);
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($full);
            if (in_array($mime, ['image/png', 'image/jpeg'], true)) {
                return response()->file($full, ['Content-Type' => $mime, 'Content-Disposition' => 'inline; filename="'.$e->file_name.'"', 'X-Content-Type-Options' => 'nosniff']);
            }
        }

        // Always a download, never shown in the page, whatever the file claims to be.
        return Storage::disk('local')->download($e->file_path, $e->file_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function deleteEvidence(Request $request, int $id, int $evidenceId)
    {
        DB::transaction(function () use ($request, $id, $evidenceId) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            $this->editable($request, $t);
            $e = TestEvidence::where('test_execution_id', $t->id)->find($evidenceId) ?? throw ApiException::notFound('Evidence', 'TEST_008');
            if ((int) $e->created_by !== $request->user()->id && ! $this->isAdmin($request)) {
                throw ApiException::forbidden('Only the person who added this, or an admin, can remove it.');
            }
            $e->file_path && Storage::disk('local')->delete($e->file_path);
            $e->delete();
            Audit::log($request, 'manual_test_evidence_removed', 'test', $t->id, ['kind' => $e->kind, 'label' => $e->label]);
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))]);
    }

    // ---------------------------------------------------------------- finishing

    public function complete(Request $request, int $id)
    {
        $b = $request->validate([
            'result' => ['required', Rule::in(['passed', 'failed'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'failure_type' => ['sometimes', 'nullable', Rule::in(self::FAILURE_TYPES)],
            'severity' => ['sometimes', 'nullable', Rule::in(['critical', 'high', 'medium', 'low'])],
        ]);
        $passed = $b['result'] === 'passed';
        $row = DB::transaction(function () use ($request, $id, $b, $passed) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            $this->editable($request, $t);
            $tpl = $this->template($t);
            $done = collect($t->checklist ?? [])->mapWithKeys(fn ($c) => [$c['id'] => (bool) $c['done']]);
            $evidence = TestEvidence::where('test_execution_id', $t->id)->get();
            $pairs = $evidence->where('kind', 'hash')->filter(fn ($e) => $e->value_b !== null);
            $mismatch = $pairs->first(fn ($e) => $e->value_a !== $e->value_b);

            if ($passed) {
                foreach ($tpl['steps'] as $i => $s) {
                    if (! ($done[$s['id']] ?? false)) {
                        throw ApiException::badRequest('Step '.($i + 1).' is not ticked: '.$s['text'].' Tick every step to pass the test, or complete it as failed.');
                    }
                    $needShots = (int) ($s['files'] ?? 0);
                    if ($needShots > 0 && $evidence->where('step_id', $s['id'])->filter(fn ($e) => $this->isScreenshot($e))->count() < $needShots) {
                        throw ApiException::badRequest('Step '.($i + 1).' needs '.($needShots === 1 ? 'a screenshot' : "$needShots screenshots").' attached as proof (a PNG or JPEG) before the test can pass: '.$s['text']);
                    }
                    if (($s['reference'] ?? false) && ! $evidence->contains(fn ($e) => $e->step_id === $s['id'] && $e->kind === 'hash' && $e->value_b !== null)) {
                        throw ApiException::badRequest('Step '.($i + 1).' needs the fingerprint of the restored copy entered, so it can be compared with the expected one: '.$s['text']);
                    }
                    if ($s['required'] && $s['evidence'] !== 'none' && ! $evidence->contains(fn ($e) => $e->step_id === $s['id'] && $e->kind === $s['evidence'])) {
                        throw ApiException::badRequest('Step '.($i + 1).' needs '.($s['evidence'] === 'hash' ? 'the fingerprints (hashes)' : 'a note').' attached before the test can pass: '.$s['text']);
                    }
                }
                if ($mismatch) {
                    throw ApiException::badRequest("The fingerprints for \"{$mismatch->label}\" do not match, so this cannot be a pass. Complete it as failed.");
                }
                // The record of the item must hold together: the right item, not later than its recovery point, a recent point, an attachment that opens.
                if (ManualChecklists::fields($t->test_type)) {
                    $job = BackupJob::find($t->backup_job_id);
                    ManualFindings::assertPassable($t->findings ?? [], $t->selection, Server::find($job?->server_id)?->timezone ?: 'UTC', Engine::allowedAgeHours($job?->schedule), null, $t->test_type);
                }
            } elseif (trim((string) ($b['notes'] ?? '')) === '') {
                throw ApiException::badRequest('Say what went wrong in the notes, so the person who reviews the failure knows.');
            }

            $first = $pairs->first();
            $ticked = $done->filter()->count();
            $who = $request->user()->username;
            $summary = "Done by hand by $who: $ticked of ".count($tpl['steps']).' steps ticked, '.$evidence->count().' piece'.($evidence->count() === 1 ? '' : 's').' of evidence'.($t->target ? ", target {$t->target}" : '');
            $result = ['success' => $passed, 'summary' => $summary, 'hash_algorithm' => $first?->algorithm, 'hash_original' => $first?->value_a, 'hash_restored' => $first?->value_b,
                'details' => ['manual' => true, 'performed_by' => $request->user()->id, 'target' => $t->target, 'steps_ticked' => $ticked, 'steps_total' => count($tpl['steps'])]];
            if (! $passed) {
                $result += [
                    'failure_type' => $b['failure_type'] ?? ($mismatch ? 'hash_mismatch' : 'restore_failed'), 'severity' => $b['severity'] ?? 'high',
                    'failed_item' => $t->target ?? $row->hostname, 'error_message' => trim($b['notes']),
                ];
            }
            $t->forceFill(['notes' => $b['notes'] ?? null])->save();
            TestResults::record($t->id, $result);
            Audit::log($request, 'manual_test_completed', 'test', $t->id, ['result' => $b['result'], 'test_type' => $t->test_type, 'target' => $t->target]);
            // A share of passes, chosen at random, are checked by a second person. Nobody knows which when they do the test.
            if ($passed && random_int(1, 100) <= (int) config('bms.manual_verify_percent')) {
                $this->askForVerification($request, $t, 'chosen at random');
            }

            return $row;
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $row->id))]);
    }

    /** Mark a passed test as needing a second person's check, and tell the admins. */
    private function askForVerification(Request $request, BackupTestExecution $t, string $why): void
    {
        $t->forceFill(['verify_state' => 'pending'])->save();
        Audit::log($request, 'manual_test_verification_requested', 'test', $t->id, ['why' => $why]);
        TestResults::notifyAdmins('verification_needed', 'A test by hand needs a second person to check it', "A {$t->test_type} test done by {$request->user()->username} was picked for checking ($why). Another technician should review its evidence on the Tests page.", 'test', $t->id);
    }

    public function saveFindings(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            $this->editable($request, $t);
            if (! ManualChecklists::fields($t->test_type)) {
                throw ApiException::badRequest('This test has no details to fill in.');
            }
            $data = $request->validate(ManualFindings::rules($t->test_type));
            $t->forceFill(['findings' => array_filter(array_merge($t->findings ?? [], $data), fn ($v) => $v !== null && $v !== '')])->save();
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))]);
    }

    /** A second person looks at the evidence on a test picked for checking and confirms it or rejects it. */
    public function verify(Request $request, int $id)
    {
        $b = $request->validate(['decision' => ['required', Rule::in(['confirm', 'reject'])], 'note' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        if ($b['decision'] === 'reject' && trim((string) ($b['note'] ?? '')) === '') {
            throw ApiException::badRequest('Say what is wrong with the evidence, so the person who did the test and the admins can see why.');
        }
        DB::transaction(function () use ($request, $id, $b) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            if ($t->verify_state !== 'pending') {
                throw ApiException::conflict('TEST_010', 'This test is not waiting to be checked.');
            }
            $me = $request->user()->id;
            if ((int) $t->assigned_to === $me || (int) $t->executed_by === $me) {
                throw ApiException::forbidden('A test has to be checked by someone other than the person who did it.');
            }
            $reject = $b['decision'] === 'reject';
            $t->forceFill(['verify_state' => $reject ? 'rejected' : 'confirmed', 'verified_by' => $me, 'verified_at' => now(), 'verify_note' => isset($b['note']) ? trim($b['note']) : null])->save();
            Audit::log($request, $reject ? 'manual_test_rejected' : 'manual_test_confirmed', 'test', $t->id, ['note' => $b['note'] ?? null]);
            $reject && TestResults::overturn($t->id, trim($b['note']), $me);
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))]);
    }

    /** An admin can ask for any completed pass to be checked, not only the ones picked at random. */
    public function requestVerification(Request $request, int $id)
    {
        if (! $this->isAdmin($request)) {
            throw ApiException::forbidden('Only an admin can ask for a test to be checked.');
        }
        DB::transaction(function () use ($request, $id) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            if ($t->test_status !== 'success' || $t->verify_state !== null) {
                throw ApiException::conflict('TEST_010', 'Only a passed test that has not been checked can be sent for checking.');
            }
            $this->askForVerification($request, $t, 'asked for by an admin');
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))]);
    }

    public function cancel(Request $request, int $id)
    {
        $b = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:1000']]);
        DB::transaction(function () use ($request, $id, $b) {
            $row = $this->find($request, $id);
            $t = BackupTestExecution::lockForUpdate()->find($row->id);
            if (! in_array($t->test_status, ['pending', 'running'], true)) {
                throw ApiException::conflict('TEST_005', 'This test is already '.$t->test_status.'.');
            }
            if ($t->assigned_to !== null && ! $this->canEdit($request, $t)) {
                throw ApiException::forbidden('This test is being done by '.($row->assigned_username ?? 'someone else').', so only they or an admin can cancel it.');
            }
            $t->forceFill(['test_status' => 'cancelled', 'completed_time' => now(), 'error_message' => 'Cancelled: '.trim($b['reason'])])->save();
            Audit::log($request, 'manual_test_cancelled', 'test', $t->id, ['reason' => $b['reason']]);
        });

        return response()->json(['success' => true, 'data' => $this->present($request, $this->find($request, $id))]);
    }
}
