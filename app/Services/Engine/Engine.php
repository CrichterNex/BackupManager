<?php

namespace App\Services\Engine;

use App\Models\BackupFailure;
use App\Models\BackupJob;
use App\Models\BackupTestExecution;
use App\Models\BackupTestFailure;
use App\Models\Company;
use App\Models\Server;
use App\Services\Agents\AgentService;
use App\Services\CompanyTests;
use App\Services\Integrations\IntegrationSync;
use App\Services\PlatformValidation;
use App\Services\Reminders;
use App\Services\Reports\ReportScheduling;
use App\Services\Reports\ReportService;
use App\Services\TestResults;
use App\Services\TestScheduling;
use App\Support\BackupPaths;
use App\Support\CompanyContext;
use App\Support\Constants;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Throwable;

final class Engine
{
    /** Only one engine pass runs at a time across processes. */
    private const LOCK = 'bms_engine';

    /** Queue a random-restore test for every server whose latest successful backup has not been tested recently. */
    public static function scheduleDueTests(): int
    {
        $queued = 0;
        $methods = Constants::FILESYSTEM_METHODS;
        $placeholders = implode(',', array_fill(0, count($methods), '?'));
        foreach (Company::where('is_active', true)->pluck('id') as $companyId) {
            if (! CompanyTests::active('random_restore', $companyId)) {
                continue; // the weekly automatic check is a random restore, which this company has turned off
            }
            $queued += DB::affectingStatement(
                "INSERT INTO backup_test_executions (company_id, backup_job_id, test_type, scheduled_time, created_at, updated_at)
                 SELECT ?, j.id, 'random_restore', ?, ?, ?
                   FROM backup_jobs j JOIN servers s ON s.id = j.server_id
                  WHERE j.company_id = ? AND j.deleted_at IS NULL AND s.active = 1 AND j.status = 'success'
                    AND j.backup_method IN ($placeholders)
                    AND j.id = (SELECT MAX(id) FROM backup_jobs WHERE server_id = j.server_id AND deleted_at IS NULL AND status = 'success' AND backup_method IN ($placeholders))
                    AND NOT EXISTS (
                      SELECT 1 FROM backup_test_executions t
                       WHERE t.backup_job_id = j.id AND t.test_type = 'random_restore' AND t.manual = 0
                         AND (t.test_status IN ('pending', 'running') OR t.created_at > ?))",
                [$companyId, now(), now(), now(), $companyId, ...$methods, ...$methods, now()->subDays(config('bms.random_restore_every_days'))],
            );
        }

        return $queued;
    }

    /** Take the next due test, marking it running in the same transaction. Returns null when nothing is due. */
    private static function claimNext(): ?array
    {
        return DB::transaction(function () {
            $t = BackupTestExecution::where('test_status', 'pending')->where('manual', false)->where('scheduled_time', '<=', now())
                ->orderBy('scheduled_time')->orderBy('id')->lockForUpdate()->first();
            if (! $t) {
                return null;
            }
            $t->forceFill(['test_status' => 'running', 'started_time' => now()])->save();
            $j = DB::table('backup_jobs as j')->join('servers as s', 's.id', '=', 'j.server_id')->leftJoin('backup_locations as bl', 'bl.id', '=', 'j.location_id')
                ->where('j.id', $t->backup_job_id)->first(['j.server_id', 'j.backup_method', 's.hostname', 'bl.loc_key', 'bl.name as location_name', 'bl.path as location_path', 'j.destination_path', 'j.layout', 'j.source_paths', 'j.excluded_paths', 'j.schedule']);

            return ['id' => $t->id, 'test_type' => $t->test_type, 'job_id' => $t->backup_job_id, 'schedule' => $j->schedule, 'server_id' => $j->server_id, 'backup_method' => $j->backup_method, 'hostname' => $j->hostname, 'path' => $j->location_path, 'location_key' => $j->loc_key, 'location_name' => $j->location_name, 'destination' => $j->destination_path,
                // What an agent on the source server needs to trace a backup file to its source file. Only when the job says how it is laid out.
                'source' => $j->layout ? ['layout' => $j->layout, 'folders' => json_decode($j->source_paths ?? '[]', true) ?: [], 'excluded' => json_decode($j->excluded_paths ?? '[]', true) ?: []] : null];
        });
    }

    /** Level 2 diagnostics, run before the final failure is recorded, so the failure says why and not just "failed". */
    private static function diagnose(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false) {
            return 'The backup location could not be reached for a storage check.';
        }

        return ($free / max(1, $total)) < 0.05 ? sprintf('Backup storage is nearly full (%.1f%% free).', $free / $total * 100) : null;
    }

    /** Backups made by a platform are kept in that platform's own locations, so BMS has no copy to restore from. */
    private static function unsupportedReason(array $t): ?string
    {
        if (in_array($t['backup_method'], Constants::LOCATION_METHODS, true)) {
            return null;
        }

        return 'Backups made by '.str_replace('_', ' ', $t['backup_method']).' are stored on that platform, so BMS cannot restore-test them itself. Use the backup verification in the platform.';
    }

    /** @return array<string, mixed> a test result, or ['skip' => reason] */
    private static function execute(array $t): array
    {
        if (! in_array($t['test_type'], Constants::ENGINE_TEST_TYPES, true)) {
            return ['skip' => "The {$t['test_type']} test is not automated yet."];
        }
        if ($reason = self::unsupportedReason($t)) {
            return ['skip' => $reason];
        }
        if (! $t['location_key']) {
            return ['skip' => 'The backup job has no backup location, so there is nothing to restore from. Set one on the job.'];
        }
        if (! $t['path']) {
            return ['skip' => "The location \"{$t['location_name']}\" can only be reached by an agent on the server, and this server has no agent."];
        }

        // The job's own folder inside the location, so a test reads the data this job wrote and not a neighbour's.
        $root = BackupPaths::join($t['path'], $t['destination']);
        if ($t['destination'] && file_exists($root) && ! BackupPaths::isInside($t['path'], $root)) {
            return ['success' => false, 'failure_type' => 'access_denied', 'severity' => 'high', 'failed_item' => $root, 'error_message' => 'The destination folder resolves to somewhere outside the backup location, so it was not read.'];
        }

        return RandomRestoreRunner::run($root, config('bms.restore_staging_dir'));
    }

    /**
     * Is there a recent successful backup? Reads what BMS recorded for the job's server and method, so it works for platform backups too.
     * The allowance is one and a half schedule intervals plus two hours when the job has a schedule, otherwise a fixed number of hours.
     *
     * @return array<string, mixed>
     */
    /** How old the newest recovery point may be: one and a half schedule intervals plus two hours, or a fixed number of hours with no schedule. */
    public static function allowedAgeHours(?string $schedule): int
    {
        $allowed = (int) config('bms.recovery_point_max_age_hours');
        if ($schedule) {
            try {
                $c = CronExpression::factory($schedule);
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $interval = $c->getNextRunDate($now, 1)->getTimestamp() - $c->getNextRunDate($now, 0)->getTimestamp();
                $allowed = (int) ceil($interval * 1.5 / 3600) + 2;
            } catch (Throwable) {
                // a bad expression was reported when the job was saved, so fall back to the fixed allowance
            }
        }

        return $allowed;
    }

    private static function checkRecoveryPoint(array $t): array
    {
        $allowed = self::allowedAgeHours($t['schedule']);
        $last = BackupJob::where('server_id', $t['server_id'])->where('backup_method', $t['backup_method'])->where('status', 'success')->max('completed_time');
        if (! $last) {
            return ['success' => false, 'failure_type' => 'file_missing', 'severity' => 'high', 'failed_item' => $t['hostname'], 'error_message' => 'No successful backup is recorded for this job, so there is no recovery point.',
                'summary' => 'No successful backup recorded'];
        }
        $age = (int) floor(CarbonImmutable::parse($last, 'UTC')->diffInMinutes(now(), false) / 60);
        $summary = "Latest recovery point: {$age} hours old (allowed {$allowed})";
        if ($age > $allowed) {
            return ['success' => false, 'failure_type' => 'file_missing', 'severity' => 'high', 'failed_item' => $t['hostname'],
                'error_message' => "The latest successful backup is {$age} hours old, more than the {$allowed} hours allowed. Last success: ".CarbonImmutable::parse($last, 'UTC')->toDateTimeString().' UTC.',
                'summary' => $summary, 'details' => ['age_hours' => $age, 'allowed_hours' => $allowed]];
        }

        return ['success' => true, 'summary' => $summary, 'details' => ['age_hours' => $age, 'allowed_hours' => $allowed]];
    }

    /** Run every due test for one company. Returns how many were processed. */
    public static function runDueTests(int $companyId): int
    {
        return CompanyContext::run($companyId, function () {
            $n = 0;
            while ($t = self::claimNext()) {
                $n++;
                // Turned off since it was queued: nothing runs, and the reason is recorded.
                if (! CompanyTests::active($t['test_type'])) {
                    TestResults::skip($t['id'], "The {$t['test_type']} test is turned off for this company.");

                    continue;
                }
                // Only tests the engine really performs are run, on the agent or here. Anything else is recorded as skipped, never as a pass:
                // handing it to the agent would run a random restore and report it as a test that never happened.
                if (! in_array($t['test_type'], Constants::ENGINE_TEST_TYPES, true)) {
                    TestResults::skip($t['id'], "The {$t['test_type']} test is not automated yet.");

                    continue;
                }
                // Checks that read BMS's own records of the backup, so they work for every method, platform backups included.
                if ($t['test_type'] === 'recovery_point') {
                    TestResults::record($t['id'], self::checkRecoveryPoint($t));

                    continue;
                }
                // Read from what Acronis says about its own validation, so it works with no agent and no access to the backups.
                if ($t['test_type'] === 'platform_validation') {
                    $out = $t['backup_method'] === 'acronis' ? PlatformValidation::evaluate($t['server_id']) : ['skip' => 'Acronis validation applies to Acronis backups only.'];
                    isset($out['skip']) ? TestResults::skip($t['id'], $out['skip']) : TestResults::record($t['id'], $out);

                    continue;
                }
                if ($reason = self::unsupportedReason($t)) {
                    TestResults::skip($t['id'], $reason);

                    continue;
                }
                // A server with an agent is tested by the agent, on the machine that holds the backup.
                if ($agent = AgentService::forServer($t['server_id'])) {
                    [$queued, $why] = AgentService::queueRestoreTest(BackupTestExecution::findOrFail($t['id']), $agent, $t['location_key'], $t['destination'], $t['source']);
                    if (! $queued) {
                        TestResults::skip($t['id'], $why);
                    }

                    continue;
                }
                // Everything except a random restore reads or writes the backup location, which only the agent on the server is set up to do.
                if ($t['test_type'] !== 'random_restore') {
                    TestResults::skip($t['id'], "The {$t['test_type']} test runs on the server's agent, and this server has none. Enroll one on the Agents page.");

                    continue;
                }
                try {
                    $outcome = self::execute($t);
                } catch (Throwable $e) {
                    $outcome = ['success' => false, 'failure_type' => 'restore_failed', 'severity' => 'high', 'error_message' => 'Test engine error: '.$e->getMessage()];
                }
                if (isset($outcome['skip'])) {
                    TestResults::skip($t['id'], $outcome['skip']);

                    continue;
                }
                if (! $outcome['success'] && ($hint = self::diagnose($t['path']))) {
                    $outcome['error_message'] = ($outcome['error_message'] ?? 'Test failed').' '.$hint;
                }
                TestResults::record($t['id'], $outcome, ['delay_minutes' => config('bms.test_retry_delay_minutes'), 'max_attempts' => config('bms.test_max_attempts')]);
            }

            return $n;
        });
    }

    /**
     * Level 3 escalation: a high or critical failure still unresolved after 4 hours is raised to managers as well
     * as admins, and again after 8 hours. Each level is sent once.
     */
    public static function escalateFailures(int $companyId): int
    {
        return CompanyContext::run($companyId, function () {
            $four = now()->subHours(4);
            $eight = now()->subHours(8);
            $due = BackupTestFailure::where('resolved', false)->whereIn('severity', ['critical', 'high'])
                ->where(fn ($q) => $q->where(fn ($a) => $a->where('escalation_level', '<', 1)->where('created_at', '<=', $four))
                    ->orWhere(fn ($b) => $b->where('escalation_level', '<', 2)->where('created_at', '<=', $eight)))->get();
            foreach ($due as $f) {
                $level = $f->created_at <= $eight ? 2 : 1;
                $f->forceFill(['escalation_level' => $level])->save();
                TestResults::notifyRoles(
                    ['company_admin', 'company_manager'], 'failure_escalated',
                    "Escalation level $level: {$f->severity} backup test failure unresolved",
                    "A {$f->severity} test failure".($f->failed_item ? " on {$f->failed_item}" : '').' has been unresolved for '.($level === 2 ? '8' : '4').' hours.',
                    'test_failure', $f->id,
                );
            }

            // Backup failures nobody has reviewed are chased the same way.
            $backupDue = BackupFailure::where('resolved', false)->whereIn('severity', ['critical', 'high'])
                ->where(fn ($q) => $q->where(fn ($a) => $a->where('escalation_level', '<', 1)->where('first_seen_at', '<=', $four))
                    ->orWhere(fn ($b) => $b->where('escalation_level', '<', 2)->where('first_seen_at', '<=', $eight)))->get();
            foreach ($backupDue as $f) {
                $level = $f->first_seen_at <= $eight ? 2 : 1;
                $f->forceFill(['escalation_level' => $level])->save();
                TestResults::notifyRoles(
                    ['company_admin', 'company_manager'], 'failure_escalated',
                    "Escalation level $level: backup problem not reviewed",
                    (Server::find($f->server_id)?->hostname ?? 'A server').": a {$f->kind} backup problem has not been reviewed for ".($level === 2 ? '8' : '4').' hours.',
                    'backup_failure', $f->id,
                );
            }

            return $due->count() + $backupDue->count();
        });
    }

    /** One full pass: queue due tests, run what is due, escalate stale failures, archive scheduled reports. */
    public static function tick(): ?array
    {
        $got = DB::selectOne('SELECT GET_LOCK(?, 0) AS ok', [self::LOCK])->ok;
        if (! $got) {
            return null; // another process is running the engine
        }
        try {
            $queued = self::scheduleDueTests();
            $ran = $escalated = $expired = $backups = $synced = $reminded = $reportsMade = 0;
            foreach (Company::where('is_active', true)->pluck('id') as $id) {
                $queued += TestScheduling::runDue($id);
                $ran += self::runDueTests($id);
                $escalated += self::escalateFailures($id);
                $expired += AgentService::expireStale($id);
                AgentService::flagUnreachable($id);
                $backups += AgentService::scheduleDueBackups($id);
                $synced += IntegrationSync::runDue($id, config('bms.integration_sync_minutes'));
                $reminded += Reminders::sendDue($id);
                $reportsMade += ReportScheduling::runDue($id);
            }

            return ['queued' => $queued, 'ran' => $ran, 'escalated' => $escalated, 'expired' => $expired, 'backups' => $backups, 'synced' => $synced, 'reminders' => $reminded, 'reports' => ReportService::generateScheduled() + $reportsMade];
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }
}
