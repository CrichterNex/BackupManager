<?php

namespace App\Http\Controllers;

use App\Models\BackupFailure;
use App\Models\BackupJob;
use App\Models\BackupTestExecution;
use App\Models\BackupTestFailure;
use App\Models\Company;
use App\Models\Integration;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * One page for a platform admin that shows how every company is doing, worst first. Like the rest of the platform area it gives counts and a
 * health score for each company, never a server name, a failure's text or anything else out of a company's records. To look closer, the admin
 * opens the company, which is recorded in that company's audit trail.
 */
class PlatformDashboardController extends Controller
{
    /** Below this health score a company is flagged for attention. */
    private const LOW_HEALTH = 80;

    public function index(Request $request)
    {
        $dashboard = app(DashboardController::class);
        $rows = [];
        foreach (Company::where('is_active', true)->orderBy('name')->get() as $c) {
            $rows[] = CompanyContext::run($c->id, fn () => self::row($c, $dashboard->summary($request)));
        }
        // Companies that need attention first, the least healthy of them leading; then the rest by name.
        usort($rows, fn ($a, $b) => [$b['attention'] ? 1 : 0, $a['health_score'] ?? 101, $a['name']] <=> [$a['attention'] ? 1 : 0, $b['health_score'] ?? 101, $b['name']]);

        $sum = fn (callable $f) => array_sum(array_map($f, $rows));

        return response()->json(['success' => true, 'data' => [
            'companies' => $rows,
            'totals' => [
                'companies' => count($rows), 'needing_attention' => count(array_filter($rows, fn ($r) => $r['attention'])),
                'servers' => $sum(fn ($r) => $r['servers']['total']), 'servers_failing' => $sum(fn ($r) => $r['servers']['failing']),
                'open_backup_problems' => $sum(fn ($r) => $r['open_backup_failures']), 'open_test_failures' => $sum(fn ($r) => $r['open_test_failures']),
                'tests_waiting' => $sum(fn ($r) => $r['tests_waiting']),
            ],
        ]]);
    }

    /** @param  array<string, mixed>  $d  the company's own dashboard figures */
    private static function row(Company $c, array $d): array
    {
        $serious = BackupTestFailure::where('resolved', false)->whereIn('severity', ['critical', 'high'])->count()
            + BackupFailure::where('resolved', false)->where('severity', 'high')->count();
        $notSyncing = Integration::where('active', true)->where('last_sync_status', 'error')->count();
        $waiting = BackupTestExecution::where('manual', true)->whereIn('test_status', ['pending', 'running'])->count();
        $last = BackupJob::whereNotNull('completed_time')->max('completed_time');
        $rate = fn (int $n, int $t) => $t === 0 ? null : round($n / $t * 100, 1);
        $b = $d['backups_30d'];
        $t = $d['tests']['last_30d'];

        $why = [];
        $d['servers']['failing'] > 0 && $why[] = $d['servers']['failing'].' server'.($d['servers']['failing'] === 1 ? '' : 's').' failing';
        $serious > 0 && $why[] = "$serious serious open failure".($serious === 1 ? '' : 's');
        $d['health_score'] !== null && $d['health_score'] < self::LOW_HEALTH && $why[] = 'Health score '.$d['health_score'];
        $d['servers']['total'] > 0 && $b['finished'] === 0 && $why[] = 'No backups recorded in 30 days';
        $notSyncing > 0 && $why[] = "$notSyncing connection".($notSyncing === 1 ? '' : 's').' not syncing';

        return [
            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'subscription_tier' => $c->subscription_tier,
            'servers' => $d['servers'],
            'backups_30d' => $b + ['success_rate_pct' => $rate($b['success'] + $b['warning'], $b['finished'])],
            'tests_30d' => $t + ['pass_rate_pct' => $rate($t['passed'], $t['total'])],
            'open_backup_failures' => $d['unresolved_backup_failures'], 'open_test_failures' => $d['unresolved_failures'], 'serious_open' => $serious,
            'tests_waiting' => $waiting, 'connections_not_syncing' => $notSyncing,
            'last_backup_at' => $last ? CarbonImmutable::parse($last, 'UTC')->toIso8601ZuluString() : null,
            'health_score' => $d['health_score'], 'attention' => $why !== [], 'attention_reasons' => $why,
        ];
    }
}
