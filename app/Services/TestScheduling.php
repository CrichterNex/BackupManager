<?php

namespace App\Services;

use App\Http\Controllers\ManualTestController;
use App\Models\BackupJob;
use App\Models\BackupTestExecution;
use App\Models\Server;
use App\Models\TestSchedule;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Constants;
use App\Support\ManualSelection;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;

/** Recurring tests: turning an admin's choices into a schedule, working out when it next runs, and queueing the tests when it does. */
final class TestScheduling
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** The cron expression for the friendly choices, or the custom one after checking it. */
    public static function cronFor(string $frequency, ?string $time, ?int $day, ?string $custom): string
    {
        if ($frequency === 'custom') {
            $cron = trim((string) $custom);
            if ($cron === '' || ! CronExpression::isValidExpression($cron)) {
                throw ApiException::badRequest('That is not a valid cron expression. Use five fields such as "0 2 * * 1" (02:00 every Monday).');
            }

            return $cron;
        }
        if (! $time || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            throw ApiException::badRequest('Give the time of day as HH:MM, for example 02:00.');
        }
        [$h, $min] = [(int) $m[1], (int) $m[2]];

        return match ($frequency) {
            'daily' => "$min $h * * *",
            'weekly' => ($day !== null && $day >= 0 && $day <= 6) ? "$min $h * * $day" : throw ApiException::badRequest('Choose the day of the week.'),
            'monthly' => ($day !== null && $day >= 1 && $day <= 28) ? "$min $h $day * *" : throw ApiException::badRequest('Choose a day of the month from 1 to 28, so it exists in every month.'),
            default => throw ApiException::badRequest('Unknown frequency.'),
        };
    }

    /** Refuses a schedule that runs more often than hourly: a test copies real data, so a typo should not start one every minute. */
    public static function assertNotTooFrequent(string $cron, string $timezone): void
    {
        $c = new CronExpression($cron);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $a = $c->getNextRunDate($now, 0, false, $timezone);
        $b = $c->getNextRunDate($now, 1, false, $timezone);
        if ($b->getTimestamp() - $a->getTimestamp() < 3600) {
            throw ApiException::badRequest('Tests can run at most once an hour. Choose a less frequent schedule.');
        }
    }

    public static function nextRun(string $cron, string $timezone, ?\DateTimeInterface $from = null): CarbonImmutable
    {
        $next = (new CronExpression($cron))->getNextRunDate($from ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 0, false, $timezone);

        return CarbonImmutable::instance($next)->setTimezone('UTC');
    }

    /** A sentence for people: "Every Monday at 02:00 (Africa/Johannesburg)". */
    public static function describe(TestSchedule $s): string
    {
        $when = match ($s->frequency) {
            'daily' => "Every day at {$s->run_time}",
            'weekly' => 'Every '.(self::DAYS[$s->run_day] ?? '?')." at {$s->run_time}",
            'monthly' => "On day {$s->run_day} of every month at {$s->run_time}",
            default => "Custom schedule ({$s->cron})",
        };

        return "$when, {$s->timezone}";
    }

    /**
     * Queue the tests a schedule covers. A job-level schedule tests that job. A server or company-wide one tests each server's newest
     * successful backup, which is the one a restore would use. Returns how many were queued. Anything already queued or running for the
     * same job and test is left alone, so a slow test is never stacked up.
     */
    public static function queue(TestSchedule $s, ?int $userId): int
    {
        // A recovery point check reads BMS's own records, so it covers every method, and so does a test a person does by hand. The rest read
        // the backup location, which BMS can only do for file-copy methods.
        $perMethod = $s->test_type === 'recovery_point' || $s->manual;
        $methods = $perMethod ? Constants::BACKUP_METHODS : Constants::FILESYSTEM_METHODS;
        $jobs = BackupJob::query()->join('servers as s', 's.id', '=', 'backup_jobs.server_id')
            ->where('s.active', true)->whereIn('backup_jobs.backup_method', $methods)->select('backup_jobs.id');
        if ($s->target_type === 'job') {
            $jobs->where('backup_jobs.id', $s->backup_job_id);
        } else {
            $s->target_type === 'server' && $jobs->where('backup_jobs.server_id', $s->server_id);
            // The newest successful backup of the kind being tested, so a later backup of another kind (a platform backup, say) never hides it.
            // A recovery point check looks at each method separately, because each one has its own freshness. The others pick one per server.
            $methods = implode(',', array_map(fn ($m) => "'$m'", $methods));
            $sameMethod = $perMethod ? 'AND b2.backup_method = backup_jobs.backup_method' : '';
            $jobs->where('backup_jobs.status', 'success')->whereRaw(
                "backup_jobs.id = (SELECT MAX(b2.id) FROM backup_jobs b2 WHERE b2.server_id = backup_jobs.server_id AND b2.deleted_at IS NULL AND b2.status = 'success' AND b2.backup_method IN ($methods) $sameMethod)"
            );
        }
        $queued = 0;
        foreach ($jobs->pluck('backup_jobs.id') as $jobId) {
            $busy = BackupTestExecution::where('backup_job_id', $jobId)->where('test_type', $s->test_type)->where('manual', (bool) $s->manual)->whereIn('test_status', ['pending', 'running'])->exists();
            if ($busy) {
                continue;
            }
            if ($s->manual) {
                // Nobody has started it yet, so nobody is recorded as having done it. The sample item (a mailbox, say) is picked now so the tester sees it.
                $server = Server::find(BackupJob::whereKey($jobId)->value('server_id'));
                BackupTestExecution::create(['backup_job_id' => $jobId, 'test_type' => $s->test_type, 'manual' => true, 'target' => $server ? ManualTestController::pickTarget($server) : null,
                    'reference' => $server && $s->test_type === 'm365_mailbox' ? ManualTestController::pickReference($server) : null,
                    'selection' => $server && $s->test_type === 'm365_mailbox' ? ManualSelection::generate($server->timezone ?: 'UTC') : null, 'notes' => 'Scheduled: '.$s->name]);
            } else {
                BackupTestExecution::create(['backup_job_id' => $jobId, 'test_type' => $s->test_type, 'executed_by' => $userId, 'notes' => 'Scheduled: '.$s->name]);
            }
            $queued++;
        }

        return $queued;
    }

    /** Run every schedule that has fallen due for one company. A schedule the engine was too late for runs once, not once per missed time. */
    public static function runDue(int $companyId): int
    {
        return CompanyContext::run($companyId, function () {
            return DB::transaction(function () {
                $n = 0;
                foreach (TestSchedule::where('enabled', true)->where('next_run_at', '<=', now())->lockForUpdate()->get() as $s) {
                    if (! CompanyTests::active($s->test_type)) {
                        $s->forceFill(['next_run_at' => self::nextRun($s->cron, $s->timezone)])->save();

                        continue;
                    }
                    $q = self::queue($s, null);
                    $s->forceFill(['last_run_at' => now(), 'last_queued' => $q, 'next_run_at' => self::nextRun($s->cron, $s->timezone)])->save();
                    $n += $q;
                }

                return $n;
            });
        });
    }
}
