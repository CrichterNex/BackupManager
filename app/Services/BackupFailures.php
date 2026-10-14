<?php

namespace App\Services;

use App\Models\BackupFailure;
use App\Models\BackupJob;
use App\Models\Integration;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Throwable;

/**
 * Backup problems that need a person to review them. Whatever notices a problem (the Acronis sync, an agent reporting a failed backup) calls
 * report() with what it found, or with no problem when the backup is fine, and this decides what is open:
 *  - a new problem opens an item and tells the admins;
 *  - the same problem again only updates it, and a worse one raises its severity and tells the admins;
 *  - a problem that goes away is marked recovered but stays open until someone reviews it, because "it fixed itself" is something to look at;
 *  - a problem someone has reviewed and that is still there does not open again, unless it gets worse or goes away and comes back.
 *
 * It also measures the gap. A backup that succeeds after others were skipped has not made up for them: the item records how long there was no good
 * backup and how many scheduled backups were missed, so a recovery after a gap is never presented as a clean one.
 */
final class BackupFailures
{
    private const RANK = ['warning' => 1, 'overdue' => 2, 'critical' => 3, 'failed' => 4];

    private const SEVERITY = ['failed' => 'high', 'critical' => 'high', 'overdue' => 'medium', 'warning' => 'low'];

    private const TITLE = [
        'failed' => 'Backup failed', 'critical' => 'Backup platform reports a critical problem', 'overdue' => 'Backup is overdue', 'warning' => 'Backup platform reports a warning',
    ];

    private const DEFAULT_INTERVAL = 86400;

    /**
     * @param  ?string  $kind  failed, critical, overdue or warning; null when the backup is fine
     * @param  array{last_good_at?: ?CarbonImmutable, back_at?: ?CarbonImmutable, interval?: ?int}  $gap  the last good backup before the problem, the good one
     *                                                                                                    that ended it (when $kind is null) and how often a backup is expected
     */
    public static function report(Server $server, string $source, string $method, ?string $kind, ?string $message = null, ?int $jobId = null, array $gap = []): ?BackupFailure
    {
        $latest = BackupFailure::where('server_id', $server->id)->where('source', $source)->where('backup_method', $method)->orderByDesc('id')->first();
        $interval = $gap['interval'] ?? null;

        if ($kind === null) {
            // Nothing wrong now. Work out what the gap was, remember that it went away, and leave the item open for review.
            if ($latest && $latest->active) {
                $end = $gap['back_at'] ?? CarbonImmutable::now();
                $latest->forceFill(['active' => false, 'recovered_at' => now()] + self::gap($latest->last_good_at, $end, $interval ?? $latest->interval_seconds))->save();
            }

            return null;
        }

        $lastGood = $gap['last_good_at'] ?? null;
        $fields = ['kind' => $kind, 'severity' => self::SEVERITY[$kind], 'message' => (string) $message, 'last_seen_at' => now(), 'active' => true, 'recovered_at' => null]
            + ($jobId ? ['backup_job_id' => $jobId] : []);

        if ($latest && ! $latest->resolved) {
            $worse = self::RANK[$kind] > self::RANK[$latest->kind];
            $reappeared = ! $latest->active;
            $good = $latest->last_good_at ?? $lastGood;
            $latest->forceFill($fields + ['last_good_at' => $good, 'interval_seconds' => $interval ?? $latest->interval_seconds]
                + self::gap($good, CarbonImmutable::now(), $interval ?? $latest->interval_seconds))->save();
            if ($worse || $reappeared) {
                self::tell($server, $latest, $worse ? 'got worse' : 'came back');
            }

            return $latest;
        }

        if ($latest && $latest->resolved && $latest->active && self::RANK[$kind] <= self::RANK[$latest->kind]) {
            // Reviewed, still there, no worse: it has been looked at, so it is not raised again.
            $latest->forceFill(['last_seen_at' => now()] + self::gap($latest->last_good_at, CarbonImmutable::now(), $interval ?? $latest->interval_seconds))->save();

            return $latest;
        }

        $failure = BackupFailure::create($fields + ['server_id' => $server->id, 'source' => $source, 'backup_method' => $method, 'first_seen_at' => now(),
            'last_good_at' => $lastGood, 'interval_seconds' => $interval] + self::gap($lastGood, CarbonImmutable::now(), $interval));
        self::tell($server, $failure, null);

        return $failure;
    }

    /**
     * Hours without a good backup, and how many scheduled backups that skipped. A backup every 24 hours, last good at midnight and next good
     * 72 hours later, skipped two: the ones due at 24 and 48 hours. The one at 72 is the one that succeeded.
     *
     * @return array{gap_hours: ?int, missed_runs: int}
     */
    public static function gap(?\DateTimeInterface $lastGood, \DateTimeInterface $end, ?int $intervalSeconds): array
    {
        if ($lastGood === null) {
            return ['gap_hours' => null, 'missed_runs' => 0];
        }
        $seconds = max(0, $end->getTimestamp() - $lastGood->getTimestamp());
        $interval = max(3600, $intervalSeconds ?: self::DEFAULT_INTERVAL);

        return ['gap_hours' => (int) round($seconds / 3600), 'missed_runs' => max(0, (int) round($seconds / $interval) - 1)];
    }

    /**
     * How often a backup of this server and method is expected: the job's schedule if it has one, otherwise the usual gap between its recent
     * successes, otherwise a day. Never less than an hour or more than a week.
     */
    public static function intervalSeconds(Server $server, string $method, ?string $schedule = null): int
    {
        if ($schedule) {
            try {
                $c = CronExpression::factory($schedule);
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                return min(604800, max(3600, $c->getNextRunDate($now, 1)->getTimestamp() - $c->getNextRunDate($now, 0)->getTimestamp()));
            } catch (Throwable) {
                // a bad expression was reported when the job was saved: fall through to the history
            }
        }
        $times = BackupJob::where('server_id', $server->id)->where('backup_method', $method)->where('status', 'success')->whereNotNull('completed_time')
            ->orderByDesc('completed_time')->limit(12)->pluck('completed_time')->map(fn ($t) => CarbonImmutable::parse($t)->getTimestamp())->all();
        $gaps = [];
        for ($i = 0; $i + 1 < count($times); $i++) {
            $gaps[] = $times[$i] - $times[$i + 1];
        }
        if (count($gaps) >= 3) {
            sort($gaps);
            $median = $gaps[intdiv(count($gaps), 2)];

            return min(604800, max(3600, $median));
        }

        return self::DEFAULT_INTERVAL;
    }

    private static function tell(Server $server, BackupFailure $f, ?string $what): void
    {
        TestResults::notifyAdmins('backup_failed', self::TITLE[$f->kind].($what ? " ($what)" : ''), "{$server->hostname}: {$f->message}", 'backup_failure', $f->id);
    }

    /** What the Acronis sync found, as a kind of problem. The worst applies: a failed run, then Acronis calling it critical, then overdue, then Acronis warning. */
    public static function acronisKind(string $state, ?string $platformStatus): ?string
    {
        return match (true) {
            $state === 'failed' => 'failed',
            $platformStatus === 'critical' => 'critical',
            $state === 'warning' => 'overdue',
            $platformStatus === 'warning' => 'warning',
            default => null,
        };
    }

    /** @param  array{last_run_at: ?CarbonImmutable, last_success_at: ?CarbonImmutable, status: ?string}  $d */
    public static function acronisMessage(string $kind, array $d, Integration $integration): string
    {
        $fmt = fn (?CarbonImmutable $t) => $t ? $t->utc()->format('Y-m-d H:i').' UTC' : 'never';
        $last = 'Last successful backup: '.$fmt($d['last_success_at']).'.';

        return match ($kind) {
            'failed' => "The latest backup run reported by {$integration->name} did not succeed (last run {$fmt($d['last_run_at'])}). $last",
            'critical' => "{$integration->name} reports this device as critical. $last",
            'overdue' => "{$integration->name} has reported no successful backup for over {$integration->stale_after_hours} hours. $last",
            default => "{$integration->name} reports a warning for this device. $last",
        };
    }

    /**
     * What the Acronis sync passes to report(): the last good backup it can see, which is the one before the problem while it lasts and the
     * one that ended it once the problem is gone.
     *
     * @param  array{last_success_at: ?CarbonImmutable}  $d
     * @return array{last_good_at: ?CarbonImmutable, back_at: ?CarbonImmutable, interval: int}
     */
    public static function acronisGap(Server $server, array $d): array
    {
        return ['last_good_at' => $d['last_success_at'], 'back_at' => $d['last_success_at'], 'interval' => self::intervalSeconds($server, 'acronis')];
    }
}
