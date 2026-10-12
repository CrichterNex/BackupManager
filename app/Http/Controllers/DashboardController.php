<?php

namespace App\Http\Controllers;

use App\Models\BackupJob;
use App\Models\BackupTestExecution;
use App\Models\BackupTestFailure;
use App\Models\Company;
use App\Models\Server;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Scope;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Health score (0-100): half backup success rate, half test pass rate, over the last 30 days.
     * If only one of the two has data, that one carries the whole score. No data returns null,
     * never a misleading 100.
     */
    public static function healthScore(?float $backupRate, ?float $testRate): ?int
    {
        if ($backupRate === null && $testRate === null) {
            return null;
        }
        if ($backupRate === null) {
            return (int) round($testRate * 100);
        }
        if ($testRate === null) {
            return (int) round($backupRate * 100);
        }

        return (int) round(($backupRate * 0.5 + $testRate * 0.5) * 100);
    }

    private function build(Request $request): array
    {
        $me = $request->user();

        $servers = Server::where('active', true);
        Scope::technician($servers, $me, 'servers.id');
        $srv = $servers->selectRaw("COUNT(*) AS total, COALESCE(SUM(CASE WHEN last_backup_status = 'failed' OR last_test_status = 'failed' THEN 1 ELSE 0 END), 0) AS failing")->first();

        $since = now()->subDays(30);
        $jobsQuery = BackupJob::query();
        Scope::technician($jobsQuery, $me, 'backup_jobs.server_id');
        $jobs = $jobsQuery->where('completed_time', '>=', $since)->whereIn('status', ['success', 'warning', 'failed'])
            ->selectRaw("COUNT(*) AS finished, COALESCE(SUM(status = 'success'), 0) AS success, COALESCE(SUM(status = 'warning'), 0) AS warning, COALESCE(SUM(status = 'failed'), 0) AS failed")->first();

        $testQuery = BackupTestExecution::query()->join('backup_jobs as j', 'j.id', '=', 'backup_test_executions.backup_job_id');
        Scope::technician($testQuery, $me, 'j.server_id');
        $windows = ['day' => now()->subDay(), 'week' => now()->subDays(7), 'month' => $since];
        $selects = [];
        foreach ($windows as $key => $_) {
            $selects[] = "COALESCE(SUM(backup_test_executions.test_status IN ('success','failed') AND backup_test_executions.completed_time >= ?), 0) AS {$key}_total";
            $selects[] = "COALESCE(SUM(backup_test_executions.test_status = 'success' AND backup_test_executions.completed_time >= ?), 0) AS {$key}_passed";
        }
        $bindings = [];
        foreach ($windows as $when) {
            array_push($bindings, $when, $when);
        }
        $t = $testQuery->selectRaw(implode(', ', $selects), $bindings)->first();

        $failureQuery = BackupTestFailure::query()
            ->join('backup_test_executions as t', 't.id', '=', 'backup_test_failures.test_execution_id')
            ->join('backup_jobs as j', 'j.id', '=', 't.backup_job_id')->where('backup_test_failures.resolved', false);
        Scope::technician($failureQuery, $me, 'j.server_id');
        $open = $failureQuery->count();

        $recentQuery = BackupJob::query()->join('servers as s', 's.id', '=', 'backup_jobs.server_id')->whereNotNull('backup_jobs.completed_time');
        Scope::technician($recentQuery, $me, 'backup_jobs.server_id');
        $recent = $recentQuery->orderByDesc('backup_jobs.completed_time')->limit(10)
            ->get(['backup_jobs.id', 's.hostname', 'backup_jobs.backup_method', 'backup_jobs.status', 'backup_jobs.size_gb', 'backup_jobs.completed_time']);

        $rate = fn (int $n, int $d): ?float => $d === 0 ? null : $n / $d;
        $finished = (int) $jobs->finished;

        return [
            'company' => ['id' => CompanyContext::id(), 'name' => ($company = Company::find(CompanyContext::id()))->name, 'slug' => $company->slug],
            'servers' => ['total' => (int) $srv->total, 'failing' => (int) $srv->failing],
            'backups_30d' => ['finished' => $finished, 'success' => (int) $jobs->success, 'warning' => (int) $jobs->warning, 'failed' => (int) $jobs->failed],
            'tests' => [
                'last_24h' => ['total' => (int) $t->day_total, 'passed' => (int) $t->day_passed],
                'last_7d' => ['total' => (int) $t->week_total, 'passed' => (int) $t->week_passed],
                'last_30d' => ['total' => (int) $t->month_total, 'passed' => (int) $t->month_passed],
            ],
            'unresolved_failures' => $open,
            'health_score' => self::healthScore($rate((int) $jobs->success + (int) $jobs->warning, $finished), $rate((int) $t->month_passed, (int) $t->month_total)),
            'recent_backups' => $recent,
        ];
    }

    public function show(Request $request)
    {
        return response()->json(['success' => true, 'data' => $this->build($request)]);
    }

    // Spec path: refuses any company other than the caller's own.
    public function forCompany(Request $request, int $id)
    {
        if ($id !== CompanyContext::id()) {
            throw ApiException::forbidden('Access denied');
        }

        return response()->json(['success' => true, 'data' => $this->build($request)]);
    }
}
