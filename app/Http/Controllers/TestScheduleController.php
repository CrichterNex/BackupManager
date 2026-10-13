<?php

namespace App\Http\Controllers;

use App\Models\BackupJob;
use App\Models\Server;
use App\Models\TestSchedule;
use App\Services\Audit;
use App\Services\CompanyTests;
use App\Services\TestScheduling;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Constants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** What each test does, and the recurring schedules that run them. */
class TestScheduleController extends Controller
{
    /** Every test type with a plain description, and whether the engine really runs it today. */
    public function types()
    {
        $out = [];
        $states = CompanyTests::states((int) CompanyContext::id());
        foreach (Constants::TEST_HELP as $type => $h) {
            $out[] = ['type' => $type, 'automated' => in_array($type, Constants::ENGINE_TEST_TYPES, true)] + $states[$type] + ['active' => $states[$type]['available'] && $states[$type]['enabled']] + $h;
        }

        return response()->json(['success' => true, 'data' => $out]);
    }

    /** "web-01, db-01 and 3 more" for a list of chosen servers. */
    private static function serverNames(array $ids): string
    {
        $names = Server::whereIn('id', $ids)->orderBy('hostname')->pluck('hostname')->all();
        if (! $names) {
            return 'Selected servers (removed)';
        }
        $shown = array_slice($names, 0, 3);
        $more = count($names) - count($shown);

        return (count($names) === 1 ? 'Server ' : count($names).' servers: ').implode(', ', $shown).($more > 0 ? " and $more more" : '');
    }

    private function present(TestSchedule $s): array
    {
        $target = match ($s->target_type) {
            'server' => 'Server '.(Server::find($s->server_id)?->hostname ?? '(removed)'),
            'servers' => self::serverNames($s->server_ids ?? []),
            'job' => ($j = BackupJob::join('servers as sv', 'sv.id', '=', 'backup_jobs.server_id')->where('backup_jobs.id', $s->backup_job_id)->first(['backup_jobs.id', 'backup_jobs.backup_method', 'sv.hostname']))
                ? "{$j->hostname}: ".str_replace('_', ' ', $j->backup_method).' backup' : 'Backup job (removed)',
            default => 'Every server with a backup BMS can test',
        };

        return $s->toArray() + ['describe' => TestScheduling::describe($s), 'target_name' => $target, 'methods' => TestScheduling::methodsFor($s)];
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => TestSchedule::orderBy('name')->get()->map(fn ($s) => $this->present($s))->values()]);
    }

    private function fields(Request $request, ?TestSchedule $existing = null): array
    {
        $r = $existing ? 'sometimes' : 'required';
        $b = $request->validate([
            'name' => [$r, 'string', 'min:1', 'max:100'],
            'test_type' => [$r, Rule::in(Constants::TEST_TYPES)],
            'manual' => ['sometimes', 'boolean'],
            'target_type' => [$r, Rule::in(['all', 'server', 'servers', 'job'])],
            'server_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'server_ids.*' => ['integer', 'min:1'],
            'server_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'backup_job_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'frequency' => [$r, Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'run_time' => ['sometimes', 'nullable', 'string', 'max:5'],
            'run_day' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:28'],
            'cron' => ['sometimes', 'nullable', 'string', 'max:100'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $m = $b + ($existing ? $existing->only(['name', 'test_type', 'target_type', 'server_id', 'server_ids', 'backup_job_id', 'frequency', 'run_time', 'run_day', 'cron', 'timezone', 'enabled', 'manual']) : ['timezone' => 'UTC', 'enabled' => true, 'manual' => false]);
        // A test the company does not use cannot be scheduled. An existing schedule can still be paused, renamed or deleted.
        if (! $existing || $m['test_type'] !== $existing->test_type) {
            CompanyTests::assertActive($m['test_type']);
        }
        // BMS can only run the tests it has built. A person can do any of them by hand.
        if (! $m['manual'] && ! in_array($m['test_type'], Constants::ENGINE_TEST_TYPES, true)) {
            throw ApiException::badRequest('BMS cannot run the '.str_replace('_', ' ', $m['test_type']).' test itself yet. Schedule it as a test performed by a person instead.');
        }
        if (! in_array($m['timezone'], timezone_identifiers_list(), true)) {
            throw ApiException::badRequest('Unknown time zone. Use a name such as Africa/Johannesburg or UTC.');
        }
        // Only the fields that belong to the chosen target are kept.
        $m['server_id'] = $m['target_type'] === 'server' ? ($m['server_id'] ?? null) : null;
        $m['backup_job_id'] = $m['target_type'] === 'job' ? ($m['backup_job_id'] ?? null) : null;
        $ids = $m['target_type'] === 'servers' ? array_values(array_unique(array_map('intval', $m['server_ids'] ?? []))) : [];
        $m['server_ids'] = $ids ?: null;
        if ($m['target_type'] === 'servers' && (! $ids || Server::where('active', true)->whereIn('id', $ids)->count() !== count($ids))) {
            throw ApiException::badRequest($ids ? 'One of the chosen servers is not one of yours.' : 'Choose at least one server.');
        }
        if ($m['target_type'] === 'server' && ! (Server::where('active', true)->whereKey($m['server_id'])->exists())) {
            throw ApiException::badRequest('Choose one of your servers.');
        }
        if ($m['target_type'] === 'job' && ! BackupJob::whereKey($m['backup_job_id'])->exists()) {
            throw ApiException::badRequest('Choose one of your backup jobs.');
        }
        $custom = $m['frequency'] === 'custom';
        $m['run_time'] = $custom ? null : ($m['run_time'] ?? null);
        $m['run_day'] = in_array($m['frequency'], ['weekly', 'monthly'], true) ? ($m['run_day'] ?? null) : null;
        $m['cron'] = TestScheduling::cronFor($m['frequency'], $m['run_time'], $m['run_day'], $custom ? ($b['cron'] ?? $existing?->cron) : null);
        TestScheduling::assertNotTooFrequent($m['cron'], $m['timezone']);
        $m['next_run_at'] = $m['enabled'] ? TestScheduling::nextRun($m['cron'], $m['timezone']) : null;

        return $m;
    }

    public function store(Request $request)
    {
        $m = $this->fields($request);
        $s = DB::transaction(function () use ($m, $request) {
            $s = TestSchedule::create($m + ['created_by' => $request->user()->id]);
            Audit::log($request, 'test_schedule_created', 'test_schedule', $s->id, ['name' => $m['name'], 'target' => $m['target_type'], 'test_type' => $m['test_type'], 'cron' => $m['cron'], 'timezone' => $m['timezone']]);

            return $s->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->present($s)], 201);
    }

    public function update(Request $request, int $id)
    {
        $s = DB::transaction(function () use ($request, $id) {
            $s = TestSchedule::lockForUpdate()->find($id) ?? throw ApiException::notFound('Test schedule', 'SCHEDULE_001');
            $m = $this->fields($request, $s);
            $s->forceFill($m)->save();
            Audit::log($request, 'test_schedule_updated', 'test_schedule', $s->id, ['name' => $m['name'], 'enabled' => $m['enabled'], 'cron' => $m['cron'], 'timezone' => $m['timezone']]);

            return $s->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->present($s)]);
    }

    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $s = TestSchedule::lockForUpdate()->find($id) ?? throw ApiException::notFound('Test schedule', 'SCHEDULE_001');
            $s->delete();
            Audit::log($request, 'test_schedule_deleted', 'test_schedule', $id, ['name' => $s->name]);
        });

        return response()->noContent();
    }

    /** Queue the schedule's tests now, without changing when it next runs. */
    public function run(Request $request, int $id)
    {
        $queued = DB::transaction(function () use ($request, $id) {
            $s = TestSchedule::lockForUpdate()->find($id) ?? throw ApiException::notFound('Test schedule', 'SCHEDULE_001');
            CompanyTests::assertActive($s->test_type);
            $n = TestScheduling::queue($s, $request->user()->id);
            Audit::log($request, 'test_schedule_run_now', 'test_schedule', $id, ['name' => $s->name, 'queued' => $n]);

            return $n;
        });

        return response()->json(['success' => true, 'data' => ['queued' => $queued]], $queued ? 202 : 200);
    }
}
