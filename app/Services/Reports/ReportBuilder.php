<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Report figures come only from what BMS records. Anything the blueprint's mockups show that BMS cannot
 * measure (uptime, restore-time actuals, encryption, service credits, framework attestations) is listed
 * under not_measured instead of being filled in, because a certificate with invented numbers is worse
 * than one with gaps. Every query filters by company_id explicitly, so reports are safe to build outside
 * a request (the engine does).
 */
final class ReportBuilder
{
    public const DEFAULT_TARGETS = ['backup_success_pct' => 99, 'test_coverage_pct' => 95, 'test_success_pct' => 95];

    public const DEFAULT_DR = ['max_backup_age_hours' => 24, 'max_test_age_days' => 30];

    private const NOT_MEASURED_COMMON = [
        'Backup encryption (BMS does not see how a backup tool stores data)',
        'Storage capacity and utilisation',
        'Restore times (the test engine records a test duration, not a full recovery time)',
    ];

    public static function pct(int|float $n, int|float $d): ?float
    {
        return $d == 0 ? null : round($n / $d * 100, 1);
    }

    private static function iso(mixed $v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->toIso8601ZuluString();
    }

    private static function one(string $sql, array $bindings): object
    {
        return DB::selectOne($sql, $bindings);
    }

    private static function company(int $companyId): array
    {
        return (array) self::one('SELECT name, slug, contact_name, contact_email FROM companies WHERE id = ?', [$companyId]);
    }

    private static function backups(int $companyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $where = 'company_id = ? AND deleted_at IS NULL AND completed_time >= ? AND completed_time < ?';
        $b = [$companyId, $from, $to];
        $r = self::one(
            "SELECT COUNT(*) AS finished,
                    COALESCE(SUM(status = 'success'), 0) AS success, COALESCE(SUM(status = 'warning'), 0) AS warning, COALESCE(SUM(status = 'failed'), 0) AS failed,
                    AVG(CASE WHEN started_time IS NOT NULL AND status IN ('success','warning') THEN TIMESTAMPDIFF(SECOND, started_time, completed_time) END) AS avg_seconds,
                    AVG(CASE WHEN status IN ('success','warning') THEN size_gb END) AS avg_size_gb
               FROM backup_jobs WHERE $where AND status IN ('success','warning','failed')", $b);
        $durations = collect(DB::select(
            "SELECT TIMESTAMPDIFF(SECOND, started_time, completed_time) AS s FROM backup_jobs
              WHERE $where AND started_time IS NOT NULL AND status IN ('success','warning') ORDER BY s", $b))->pluck('s')->map(fn ($v) => (float) $v)->all();
        $finished = (int) $r->finished;

        return [
            'finished' => $finished, 'success' => (int) $r->success, 'warning' => (int) $r->warning, 'failed' => (int) $r->failed,
            'success_rate_pct' => self::pct((int) $r->success, $finished),
            'avg_duration_seconds' => $r->avg_seconds === null ? null : (int) round((float) $r->avg_seconds),
            'p95_duration_seconds' => $durations ? (int) round(self::percentile($durations, 0.95)) : null,
            'avg_size_gb' => $r->avg_size_gb === null ? null : round((float) $r->avg_size_gb, 2),
        ];
    }

    /** Linear-interpolated percentile over a sorted list (same definition as SQL percentile_cont). */
    private static function percentile(array $sorted, float $p): float
    {
        $pos = $p * (count($sorted) - 1);
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);

        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
    }

    private static function tests(int $companyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $base = 'FROM backup_test_executions t JOIN backup_jobs j ON j.id = t.backup_job_id WHERE t.company_id = ? AND t.completed_time >= ? AND t.completed_time < ?';
        $b = [$companyId, $from, $to];
        $total = self::one(
            "SELECT COALESCE(SUM(t.test_status IN ('success','failed')), 0) AS run, COALESCE(SUM(t.test_status = 'success'), 0) AS passed,
                    COALESCE(SUM(t.test_status = 'failed'), 0) AS failed, COALESCE(SUM(t.test_status = 'skipped'), 0) AS skipped,
                    COALESCE(SUM(t.hash_match IS NOT NULL), 0) AS hash_checked, COALESCE(SUM(t.hash_match = 0), 0) AS hash_mismatches,
                    COALESCE(SUM(t.source_status = 'verified'), 0) AS source_verified, COALESCE(SUM(t.source_status = 'mismatch'), 0) AS source_mismatch,
                    COALESCE(SUM(t.source_status = 'not_comparable'), 0) AS source_not_comparable $base", $b);
        $byType = collect(DB::select(
            "SELECT t.test_type, COUNT(*) AS run, SUM(t.test_status = 'success') AS passed $base AND t.test_status IN ('success','failed') GROUP BY t.test_type ORDER BY t.test_type", $b))
            ->map(fn ($x) => ['test_type' => $x->test_type, 'run' => (int) $x->run, 'passed' => (int) $x->passed])->all();
        $tested = (int) self::one("SELECT COUNT(DISTINCT j.server_id) AS n $base AND t.test_status IN ('success','failed')", $b)->n;
        $active = (int) self::one('SELECT COUNT(*) AS n FROM servers WHERE company_id = ? AND active = 1', [$companyId])->n;

        return [
            'run' => (int) $total->run, 'passed' => (int) $total->passed, 'failed' => (int) $total->failed, 'skipped' => (int) $total->skipped,
            'pass_rate_pct' => self::pct((int) $total->passed, (int) $total->run),
            'hash_checked' => (int) $total->hash_checked, 'hash_mismatches' => (int) $total->hash_mismatches,
            'source_verified' => (int) $total->source_verified, 'source_mismatch' => (int) $total->source_mismatch, 'source_not_comparable' => (int) $total->source_not_comparable,
            'by_type' => $byType,
            'servers_active' => $active, 'servers_tested' => $tested,
            'coverage_pct' => self::pct($tested, $active),
        ];
    }

    private static function failures(int $companyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $raised = collect(DB::select(
            'SELECT severity, COUNT(*) AS raised, SUM(resolved = 1) AS resolved,
                    AVG(CASE WHEN resolved = 1 THEN TIMESTAMPDIFF(SECOND, created_at, resolved_at) END) / 3600 AS mttr_hours
               FROM backup_test_failures WHERE company_id = ? AND created_at >= ? AND created_at < ? GROUP BY severity', [$companyId, $from, $to]))->keyBy('severity');
        $bySeverity = [];
        $raisedTotal = $resolvedTotal = $mttrCount = 0;
        $mttrWeighted = 0.0;
        foreach (['critical', 'high', 'medium', 'low'] as $sev) {
            $r = $raised->get($sev);
            $n = (int) ($r->raised ?? 0);
            $res = (int) ($r->resolved ?? 0);
            $mttr = $r?->mttr_hours === null ? null : (float) $r->mttr_hours;
            $bySeverity[] = ['severity' => $sev, 'raised' => $n, 'resolved' => $res, 'mttr_hours' => $mttr === null ? null : round($mttr, 1)];
            $raisedTotal += $n;
            $resolvedTotal += $res;
            if ($mttr !== null) {
                $mttrWeighted += $mttr * $res;
                $mttrCount += $res;
            }
        }
        $resolution = collect(DB::select(
            'SELECT resolution_type, COUNT(*) AS n FROM backup_test_failures WHERE company_id = ? AND created_at >= ? AND created_at < ? AND resolved = 1 GROUP BY resolution_type ORDER BY resolution_type',
            [$companyId, $from, $to]))->map(fn ($x) => ['resolution_type' => $x->resolution_type, 'n' => (int) $x->n])->all();
        $open = collect(DB::select('SELECT severity, COUNT(*) AS n FROM backup_test_failures WHERE company_id = ? AND resolved = 0 GROUP BY severity', [$companyId]))->pluck('n', 'severity');

        return [
            'raised' => $raisedTotal, 'resolved' => $resolvedTotal,
            'mttr_hours' => $mttrCount ? round($mttrWeighted / $mttrCount, 1) : null,
            'by_severity' => $bySeverity,
            'resolution_types' => $resolution,
            'open_now' => ['critical' => (int) ($open['critical'] ?? 0), 'high' => (int) ($open['high'] ?? 0), 'medium' => (int) ($open['medium'] ?? 0), 'low' => (int) ($open['low'] ?? 0)],
        ];
    }

    public static function certify(array $backups, array $tests, int $openCriticalOrHigh, int $openAny, array $t): array
    {
        if ($backups['finished'] === 0 || $tests['run'] === 0) {
            return ['level' => 'insufficient_data', 'reasons' => [$backups['finished'] === 0 ? 'No backups finished in the period.' : 'No tests ran in the period.']];
        }
        $misses = [];
        if (($backups['success_rate_pct'] ?? 0) < $t['backup_success_pct']) {
            $misses[] = "Backup success {$backups['success_rate_pct']}% is below the {$t['backup_success_pct']}% target.";
        }
        if (($tests['coverage_pct'] ?? 0) < $t['test_coverage_pct']) {
            $misses[] = "Test coverage {$tests['coverage_pct']}% is below the {$t['test_coverage_pct']}% target.";
        }
        if (($tests['pass_rate_pct'] ?? 0) < $t['test_success_pct']) {
            $misses[] = "Test pass rate {$tests['pass_rate_pct']}% is below the {$t['test_success_pct']}% target.";
        }
        if ($openCriticalOrHigh > 0) {
            $misses[] = "$openCriticalOrHigh critical or high test failure(s) are unresolved.";
        }
        if ($misses) {
            return ['level' => 'non_compliant', 'reasons' => $misses];
        }
        if ($openAny > 0) {
            return ['level' => 'compliant_with_notes', 'reasons' => ["$openAny lower-severity test failure(s) are unresolved."]];
        }

        return ['level' => 'compliant', 'reasons' => ['All targets met and no unresolved test failures.']];
    }

    public static function compliance(int $companyId, CarbonInterface $from, CarbonInterface $to, array $targets = self::DEFAULT_TARGETS): array
    {
        $backups = self::backups($companyId, $from, $to);
        $tests = self::tests($companyId, $from, $to);
        $failures = self::failures($companyId, $from, $to);
        $servers = self::one(
            'SELECT COUNT(*) AS total, COALESCE(SUM(EXISTS (SELECT 1 FROM backup_jobs j WHERE j.server_id = servers.id AND j.location_id IS NOT NULL AND j.deleted_at IS NULL)), 0) AS with_location, COALESCE(SUM(retention_policy_days IS NOT NULL), 0) AS with_retention FROM servers WHERE company_id = ? AND active = 1',
            [$companyId]);
        $audit = (int) self::one('SELECT COUNT(*) AS n FROM audit_trail WHERE company_id = ? AND created_at >= ? AND created_at < ?', [$companyId, $from, $to])->n;
        $o = $failures['open_now'];

        return [
            'company' => self::company($companyId),
            'servers' => ['total' => (int) $servers->total, 'with_location' => (int) $servers->with_location, 'with_retention' => (int) $servers->with_retention],
            'backups' => $backups, 'tests' => $tests, 'failures' => $failures, 'targets' => $targets,
            'audit_entries' => $audit,
            'certification' => self::certify($backups, $tests, $o['critical'] + $o['high'], $o['medium'] + $o['low'], $targets),
            'verification_method' => 'SHA-256 hash comparison of the backup copy and the restored copy',
            'not_measured' => self::NOT_MEASURED_COMMON,
        ];
    }

    public static function sla(int $companyId, CarbonInterface $from, CarbonInterface $to, array $targets = self::DEFAULT_TARGETS): array
    {
        $backups = self::backups($companyId, $from, $to);
        $tests = self::tests($companyId, $from, $to);
        $failures = self::failures($companyId, $from, $to);
        $status = fn (?float $actual, float $target) => $actual === null ? 'no_data' : ($actual >= $target ? 'met' : 'missed');
        $rows = [
            ['metric' => 'Backup execution success', 'target_pct' => $targets['backup_success_pct'], 'actual_pct' => $backups['success_rate_pct'], 'status' => $status($backups['success_rate_pct'], $targets['backup_success_pct'])],
            ['metric' => 'Test coverage (servers tested in period)', 'target_pct' => $targets['test_coverage_pct'], 'actual_pct' => $tests['coverage_pct'], 'status' => $status($tests['coverage_pct'], $targets['test_coverage_pct'])],
            ['metric' => 'Test pass rate', 'target_pct' => $targets['test_success_pct'], 'actual_pct' => $tests['pass_rate_pct'], 'status' => $status($tests['pass_rate_pct'], $targets['test_success_pct'])],
        ];
        $measured = array_values(array_filter($rows, fn ($r) => $r['status'] !== 'no_data'));

        return [
            'company' => self::company($companyId), 'targets' => $targets, 'metrics' => $rows,
            'summary' => [
                'measurable' => count($measured),
                'met' => count(array_filter($measured, fn ($r) => $r['status'] === 'met')),
                'missed' => count(array_filter($measured, fn ($r) => $r['status'] === 'missed')),
            ],
            'backups' => ['finished' => $backups['finished'], 'failed' => $backups['failed'], 'avg_duration_seconds' => $backups['avg_duration_seconds'], 'p95_duration_seconds' => $backups['p95_duration_seconds']],
            'tests' => ['run' => $tests['run'], 'passed' => $tests['passed'], 'failed' => $tests['failed'], 'skipped' => $tests['skipped'], 'servers_tested' => $tests['servers_tested'], 'servers_active' => $tests['servers_active']],
            'incidents' => $failures,
            'not_measured' => ['Scheduling uptime and availability', 'RTO and RPO actuals', 'Service credits and penalties', 'Backup window adherence', ...array_slice(self::NOT_MEASURED_COMMON, 2)],
        ];
    }

    public static function security(int $companyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $users = collect(DB::select(
            'SELECT role, auth_method, COALESCE(SUM(active = 1), 0) AS active, COALESCE(SUM(active = 0), 0) AS disabled FROM users WHERE company_id = ? GROUP BY role, auth_method ORDER BY role, auth_method',
            [$companyId]))->map(fn ($u) => ['role' => $u->role, 'auth_method' => $u->auth_method, 'active' => (int) $u->active, 'disabled' => (int) $u->disabled])->all();
        $dormant = (int) self::one('SELECT COUNT(*) AS n FROM users WHERE company_id = ? AND active = 1 AND (last_login IS NULL OR last_login < ?)', [$companyId, now()->subDays(90)])->n;
        $actions = collect(DB::select(
            'SELECT action, COUNT(*) AS total, COALESCE(SUM(success = 0), 0) AS failed FROM audit_trail WHERE company_id = ? AND created_at >= ? AND created_at < ? GROUP BY action ORDER BY total DESC, action',
            [$companyId, $from, $to]))->map(fn ($a) => ['action' => $a->action, 'total' => (int) $a->total, 'failed' => (int) $a->failed])->all();
        $count = fn (string $a) => collect($actions)->firstWhere('action', $a)['total'] ?? 0;
        $admins = (int) self::one("SELECT COUNT(*) AS n FROM users WHERE company_id = ? AND active = 1 AND role = 'company_admin'", [$companyId])->n;

        return [
            'company' => self::company($companyId),
            'users' => ['by_role' => $users, 'active_admins' => $admins, 'active_without_recent_login' => $dormant],
            'sign_ins' => ['successful' => $count('login'), 'failed_attributed_to_a_user' => $count('login_failed')],
            'password_changes' => $count('password_changed'),
            'audit' => ['entries' => array_sum(array_column($actions, 'total')), 'by_action' => $actions],
            // These describe how BMS itself is built, and the test suite checks them.
            'controls' => [
                ['control' => 'Tenant isolation', 'detail' => 'Every query is filtered by company through a global scope, and tests prove one company cannot read or change another\'s data.'],
                ['control' => 'Audit trail', 'detail' => 'Write-once. Database triggers reject updates and deletes of audit entries.'],
                ['control' => 'Passwords', 'detail' => 'Hashed, minimum '.config('bms.min_password_length').' characters.'],
                ['control' => 'Sessions', 'detail' => config('bms.session_hours').'-hour HttpOnly token cookie, with login rate limiting.'],
                ['control' => 'Access control', 'detail' => 'Three roles. Technicians only see servers assigned to them.'],
            ],
            'not_measured' => ['Compliance with named frameworks (ISO 27001, SOC 2, HIPAA, GDPR, PCI-DSS). BMS records evidence, it does not assess frameworks.', 'Backup encryption', 'Network security of backup transfers', 'Third-party and vendor risk'],
        ];
    }

    /**
     * What a server's jobs are stated to cover: 'Full server', 'Folders: /a, /b', and any exclusions ('(except /a/tmp)'),
     * or null when no job says. Jobs without exclusions are merged, and a job with exclusions keeps its own phrase so an
     * exclusion is never shown against folders it does not belong to.
     */
    public static function scopeSummary(array $jobs): ?string
    {
        $full = false;
        $plain = $special = [];
        foreach ($jobs as $j) {
            $include = json_decode($j->source_paths ?? '[]', true) ?: [];
            $except = json_decode($j->excluded_paths ?? '[]', true) ?: [];
            if ($j->scope_type === 'full_server') {
                $except ? $special[] = 'Full server (except '.implode(', ', $except).')' : $full = true;
            } elseif ($j->scope_type === 'folders') {
                $except ? $special[] = 'Folders: '.implode(', ', $include).' (except '.implode(', ', $except).')' : $plain = array_merge($plain, $include);
            }
        }
        $parts = [];
        $full && $parts[] = 'Full server';
        $plain && $parts[] = 'Folders: '.implode(', ', array_values(array_unique($plain)));
        $parts = array_merge($parts, array_values(array_unique($special)));

        return $parts ? implode('; ', $parts) : null;
    }

    public static function dr(int $companyId, array $params = self::DEFAULT_DR): array
    {
        $now = CarbonImmutable::now('UTC');
        $rows = DB::select(
            "SELECT s.id, s.hostname, s.os_type, s.criticality, s.backup_methods, s.last_backup_time, s.last_backup_status, s.last_test_time, s.last_test_status,
                    (SELECT COUNT(*) FROM backup_test_failures f JOIN backup_test_executions t ON t.id = f.test_execution_id JOIN backup_jobs j ON j.id = t.backup_job_id
                      WHERE j.server_id = s.id AND f.resolved = 0 AND f.severity IN ('critical','high')) AS open_serious
               FROM servers s WHERE s.company_id = ? AND s.active = 1 ORDER BY s.hostname", [$companyId]);
        $servers = [];
        // What each server's jobs say they cover. Admin-entered, so the report labels it as the stated scope.
        $scopes = [];
        foreach (DB::select('SELECT server_id, scope_type, source_paths, excluded_paths FROM backup_jobs WHERE company_id = ? AND deleted_at IS NULL', [$companyId]) as $j) {
            $scopes[$j->server_id][] = $j;
        }
        foreach ($rows as $r) {
            $backupAge = $r->last_backup_time ? CarbonImmutable::parse($r->last_backup_time, 'UTC')->diffInSeconds($now) / 3600 : null;
            $testAge = $r->last_test_time ? CarbonImmutable::parse($r->last_test_time, 'UTC')->diffInSeconds($now) / 86400 : null;
            $reasons = [];
            if ($backupAge === null) {
                $reasons[] = 'No backup recorded';
            } elseif ($r->last_backup_status === 'failed') {
                $reasons[] = 'Latest backup failed';
            } elseif ($backupAge > $params['max_backup_age_hours']) {
                $reasons[] = "Latest backup is older than {$params['max_backup_age_hours']} hours";
            }
            if ($testAge === null) {
                $reasons[] = 'Never tested';
            } elseif ($r->last_test_status === 'failed') {
                $reasons[] = 'Latest test failed';
            } elseif ($testAge > $params['max_test_age_days']) {
                $reasons[] = "Latest test is older than {$params['max_test_age_days']} days";
            }
            if ((int) $r->open_serious > 0) {
                $reasons[] = "{$r->open_serious} unresolved critical or high failure(s)";
            }
            $servers[] = [
                'hostname' => $r->hostname, 'os_type' => $r->os_type, 'criticality' => $r->criticality, 'backup_methods' => json_decode($r->backup_methods, true),
                'last_backup_time' => self::iso($r->last_backup_time), 'last_backup_status' => $r->last_backup_status,
                'last_test_time' => self::iso($r->last_test_time), 'last_test_status' => $r->last_test_status,
                'backup_age_hours' => $backupAge === null ? null : round($backupAge, 1), 'test_age_days' => $testAge === null ? null : round($testAge, 1),
                'scope' => self::scopeSummary($scopes[$r->id] ?? []),
                'ready' => ! $reasons, 'reasons' => $reasons,
            ];
        }
        $full = self::one("SELECT MAX(completed_time) AS last FROM backup_test_executions WHERE company_id = ? AND test_type = 'full_recovery' AND test_status = 'success'", [$companyId])->last;
        $ready = count(array_filter($servers, fn ($s) => $s['ready']));
        $total = count($servers);

        return [
            'company' => self::company($companyId), 'params' => $params, 'servers' => $servers,
            'summary' => ['total' => $total, 'ready' => $ready, 'not_ready' => $total - $ready, 'status' => $total === 0 ? 'no_servers' : ($ready === $total ? 'ready' : 'not_ready')],
            'last_full_recovery_test' => self::iso($full),
            'not_measured' => ['Recovery documentation, procedures and staff training', 'Validated restore times and RTO/RPO', 'Immutability or air-gapping of backup storage', ...($full ? [] : ['No full recovery test has been recorded (the engine does not automate these yet)'])],
        ];
    }

    public static function build(int $companyId, string $type, CarbonInterface $from, CarbonInterface $to, array $opts = []): array
    {
        $head = ['type' => $type, 'generated_at' => now()->toIso8601ZuluString(), 'period' => ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString()]];
        $targets = array_merge(self::DEFAULT_TARGETS, $opts['targets'] ?? []);
        $dr = array_merge(self::DEFAULT_DR, $opts['dr'] ?? []);

        return $head + match ($type) {
            'compliance' => self::compliance($companyId, $from, $to, $targets),
            'sla' => self::sla($companyId, $from, $to, $targets),
            'security' => self::security($companyId, $from, $to),
            'dr_readiness' => self::dr($companyId, $dr),
        };
    }
}
