<?php

namespace App\Http\Controllers;

use App\Models\ReportSchedule;
use App\Services\Audit;
use App\Services\Reports\ReportScheduling;
use App\Support\ApiException;
use App\Support\Constants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Reports that make themselves on a timetable. */
class ReportScheduleController extends Controller
{
    private function present(ReportSchedule $s): array
    {
        return $s->toArray() + ['describe' => ReportScheduling::describe($s), 'period_text' => $s->report_type === 'dr_readiness' ? 'A snapshot of right now' : (ReportScheduling::PERIODS[$s->period] ?? '')];
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => ReportSchedule::orderBy('name')->get()->map(fn ($s) => $this->present($s))->values(), 'periods' => ReportScheduling::PERIODS]);
    }

    private function fields(Request $request, ?ReportSchedule $existing = null): array
    {
        $r = $existing ? 'sometimes' : 'required';
        $b = $request->validate([
            'name' => [$r, 'string', 'min:1', 'max:100'],
            'report_type' => [$r, Rule::in(Constants::REPORT_TYPES)],
            'period' => ['sometimes', 'nullable', Rule::in(array_keys(ReportScheduling::PERIODS))],
            'frequency' => [$r, Rule::in(['weekly', 'monthly', 'quarterly', 'custom'])],
            'run_time' => ['sometimes', 'nullable', 'string', 'max:5'],
            'run_day' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:28'],
            'cron' => ['sometimes', 'nullable', 'string', 'max:100'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $m = $b + ($existing ? $existing->only(['name', 'report_type', 'period', 'frequency', 'run_time', 'run_day', 'cron', 'timezone', 'enabled']) : ['timezone' => 'UTC', 'enabled' => true, 'period' => null, 'run_time' => null, 'run_day' => null]);
        if (! in_array($m['timezone'], timezone_identifiers_list(), true)) {
            throw ApiException::badRequest('Unknown time zone. Use a name such as Africa/Johannesburg or UTC.');
        }
        // A readiness report is a snapshot of now and has no period. Every other one needs to say what it covers.
        if ($m['report_type'] === 'dr_readiness') {
            $m['period'] = null;
        } elseif (empty($m['period'])) {
            throw ApiException::badRequest('Choose the period the report covers.');
        }
        $custom = $m['frequency'] === 'custom';
        $m['run_time'] = $custom ? null : ($m['run_time'] ?? null);
        $m['run_day'] = $custom ? null : ($m['run_day'] ?? null);
        if ($m['frequency'] === 'weekly' && ($m['run_day'] === null || $m['run_day'] > 6)) {
            throw ApiException::badRequest('Choose the day of the week.');
        }
        $m['cron'] = ReportScheduling::cronFor($m['frequency'], $m['run_time'], $m['run_day'], $custom ? ($b['cron'] ?? $existing?->cron) : null);
        $m['next_run_at'] = $m['enabled'] ? ReportScheduling::nextRun($m['cron'], $m['timezone']) : null;

        return $m;
    }

    public function store(Request $request)
    {
        $m = $this->fields($request);
        $s = DB::transaction(function () use ($m, $request) {
            $s = ReportSchedule::create($m + ['created_by' => $request->user()->id]);
            Audit::log($request, 'report_schedule_created', 'report_schedule', $s->id, ['name' => $m['name'], 'report_type' => $m['report_type'], 'cron' => $m['cron'], 'timezone' => $m['timezone']]);

            return $s->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->present($s)], 201);
    }

    public function update(Request $request, int $id)
    {
        $s = DB::transaction(function () use ($request, $id) {
            $s = ReportSchedule::lockForUpdate()->find($id) ?? throw ApiException::notFound('Report schedule', 'SCHEDULE_002');
            $m = $this->fields($request, $s);
            $s->forceFill($m)->save();
            Audit::log($request, 'report_schedule_updated', 'report_schedule', $s->id, ['name' => $m['name'], 'enabled' => $m['enabled'], 'cron' => $m['cron'], 'timezone' => $m['timezone']]);

            return $s->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->present($s)]);
    }

    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $s = ReportSchedule::lockForUpdate()->find($id) ?? throw ApiException::notFound('Report schedule', 'SCHEDULE_002');
            $s->delete();
            Audit::log($request, 'report_schedule_deleted', 'report_schedule', $id, ['name' => $s->name]);
        });

        return response()->noContent();
    }

    /** Make the report now, without changing when it is next due. */
    public function run(Request $request, int $id)
    {
        $reportId = DB::transaction(function () use ($request, $id) {
            $s = ReportSchedule::lockForUpdate()->find($id) ?? throw ApiException::notFound('Report schedule', 'SCHEDULE_002');
            $rid = ReportScheduling::make($s, $request->user()->id);
            $s->forceFill(['last_run_at' => now(), 'last_report_id' => $rid, 'last_error' => null])->save();
            Audit::log($request, 'report_schedule_run_now', 'report_schedule', $id, ['name' => $s->name, 'report_id' => $rid]);

            return $rid;
        });

        return response()->json(['success' => true, 'data' => ['report_id' => $reportId]], 201);
    }
}
