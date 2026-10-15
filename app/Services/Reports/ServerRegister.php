<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;

/**
 * One row for each active server with what a reader of a report wants beside it: what it stores, how often it is backed up, how long backups are
 * kept, and who last tested it. Built when the report is generated, so a report keeps saying what was true then.
 *
 * How often is the job's schedule when it has one. Without one it is worked out from the gaps between the last successful backups, and says so,
 * because a guess from history is not the same as a schedule. Backups that the platform runs on its own timetable are labelled as such.
 */
final class ServerRegister
{
    /** Methods whose timetable is set in the backup platform, and not read from it per run. */
    private const PLATFORM_SET = ['acronis_m365', 'microsoft_native_365'];

    /** @return list<array<string, mixed>> */
    public static function build(int $companyId, CarbonInterface $asAt): array
    {
        $at = CarbonImmutable::instance($asAt)->utc()->toDateTimeString();
        $servers = DB::select('SELECT id, hostname, data_function, os_type, criticality, backup_methods, retention_policy_days, last_backup_time, last_backup_status FROM servers WHERE company_id = ? AND active = 1 ORDER BY hostname', [$companyId]);

        $schedules = [];
        $jobRetention = [];
        foreach (DB::select('SELECT server_id, backup_method, schedule, retention_days FROM backup_jobs WHERE company_id = ? AND deleted_at IS NULL ORDER BY id DESC', [$companyId]) as $j) {
            if ($j->schedule && ! isset($schedules[$j->server_id][$j->backup_method])) {
                $schedules[$j->server_id][$j->backup_method] = $j->schedule;
            }
            if ($j->retention_days) {
                $jobRetention[$j->server_id][(int) $j->retention_days] = true;
            }
        }
        $history = [];
        $recent = DB::select(
            "SELECT server_id, backup_method, completed_time FROM (
                SELECT server_id, backup_method, completed_time, ROW_NUMBER() OVER (PARTITION BY server_id, backup_method ORDER BY completed_time DESC) AS rn
                  FROM backup_jobs WHERE company_id = ? AND status = 'success' AND completed_time IS NOT NULL AND completed_time <= ? AND deleted_at IS NULL) x
              WHERE rn <= 12 ORDER BY server_id, backup_method, completed_time DESC", [$companyId, $at]);
        foreach ($recent as $r) {
            $history[$r->server_id][$r->backup_method][] = CarbonImmutable::parse($r->completed_time, 'UTC')->getTimestamp();
        }
        $tests = [];
        foreach (DB::select(
            "SELECT server_id, test_type, test_status, completed_time, manual, first_name, last_name, username, has_user FROM (
                SELECT j.server_id, t.test_type, t.test_status, t.completed_time, t.manual, u.first_name, u.last_name, u.username, (t.executed_by IS NOT NULL) AS has_user,
                       ROW_NUMBER() OVER (PARTITION BY j.server_id ORDER BY t.completed_time DESC, t.id DESC) AS rn
                  FROM backup_test_executions t JOIN backup_jobs j ON j.id = t.backup_job_id LEFT JOIN users u ON u.id = t.executed_by
                 WHERE t.company_id = ? AND t.test_status IN ('success', 'failed') AND t.completed_time IS NOT NULL AND t.completed_time <= ?) x
              WHERE rn = 1", [$companyId, $at]) as $t) {
            $tests[$t->server_id] = $t;
        }

        $out = [];
        foreach ($servers as $s) {
            $methods = array_values(array_unique(array_merge(json_decode($s->backup_methods ?? '[]', true) ?: [], array_keys($schedules[$s->id] ?? []), array_keys($history[$s->id] ?? []))));
            $t = $tests[$s->id] ?? null;
            $out[] = [
                'hostname' => $s->hostname, 'function' => $s->data_function !== null && trim($s->data_function) !== '' ? trim($s->data_function) : null,
                'os_type' => $s->os_type, 'criticality' => $s->criticality, 'methods' => $methods,
                'frequency' => self::frequency($methods, $schedules[$s->id] ?? [], $history[$s->id] ?? []),
                'retention' => self::retention($s->retention_policy_days, array_keys($jobRetention[$s->id] ?? [])),
                'last_backup_time' => $s->last_backup_time ? CarbonImmutable::parse($s->last_backup_time, 'UTC')->toIso8601ZuluString() : null, 'last_backup_status' => $s->last_backup_status,
                'last_test' => $t ? [
                    'type' => $t->test_type, 'result' => $t->test_status, 'at' => CarbonImmutable::parse($t->completed_time, 'UTC')->toIso8601ZuluString(),
                    // A test BMS ran on its own is the system's. One a person did is theirs, by name.
                    'automatic' => ! $t->manual, 'by' => $t->manual ? ($t->has_user ? self::name($t) : null) : null,
                ] : null,
            ];
        }

        return $out;
    }

    private static function name(object $u): string
    {
        $full = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));

        return $full !== '' ? $full : (string) $u->username;
    }

    /**
     * @param  list<string>  $methods
     * @param  array<string, string>  $schedules  cron by method
     * @param  array<string, list<int>>  $history  recent success times by method, newest first
     * @return array{text: string, parts: list<array{method: string, label: string, basis: string}>}
     */
    private static function frequency(array $methods, array $schedules, array $history): array
    {
        $parts = [];
        foreach ($methods as $m) {
            if (isset($schedules[$m])) {
                $gap = self::cronGap($schedules[$m]);
                $parts[] = ['method' => $m, 'label' => $gap ? self::label($gap) : 'Custom schedule', 'basis' => 'scheduled'];
            } elseif (in_array($m, self::PLATFORM_SET, true)) {
                // The records for these are made when BMS reads the platform, so their gaps say how often BMS looked, not how often it backs up.
                $parts[] = ['method' => $m, 'label' => 'Set in the backup platform', 'basis' => 'platform'];
            } elseif (($times = $history[$m] ?? []) && count($times) >= 3) {
                $gaps = [];
                for ($i = 0; $i + 1 < count($times); $i++) {
                    $gaps[] = $times[$i] - $times[$i + 1];
                }
                sort($gaps);
                $parts[] = ['method' => $m, 'label' => self::label($gaps[intdiv(count($gaps), 2)]), 'basis' => 'observed'];
            } else {
                $parts[] = ['method' => $m, 'label' => 'Not known', 'basis' => 'none'];
            }
        }
        if (! $parts) {
            return ['text' => 'Not known', 'parts' => []];
        }
        $labels = array_unique(array_map(fn ($p) => $p['label'].($p['basis'] === 'observed' ? ' (observed)' : ''), $parts));
        $text = count($labels) === 1
            ? $labels[array_key_first($labels)]
            : implode('; ', array_map(fn ($p) => $p['label'].($p['basis'] === 'observed' ? ' (observed)' : '').' ('.ucfirst(str_replace('_', ' ', $p['method'])).')', $parts));

        return ['text' => $text, 'parts' => $parts];
    }

    /** Seconds between two consecutive runs of a cron expression, or null if it is not one BMS can read. */
    private static function cronGap(string $cron): ?int
    {
        try {
            $c = new CronExpression($cron);
            $now = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));

            return $c->getNextRunDate($now, 1)->getTimestamp() - $c->getNextRunDate($now, 0)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function label(int $seconds): string
    {
        $h = $seconds / 3600;
        $d = $seconds / 86400;

        return match (true) {
            $h < 1.5 => 'Hourly',
            $h < 23 => 'Every '.max(2, (int) round($h)).' hours',
            $d <= 1.5 => 'Daily',
            $d <= 9 => 'Weekly',
            $d < 12 => 'Every '.(int) round($d).' days',
            $d <= 16 => 'Fortnightly',
            $d <= 45 => 'Monthly',
            $d <= 100 => 'Quarterly',
            default => 'Every '.(int) round($d).' days',
        };
    }

    /** @param  list<int>  $jobDays */
    private static function retention(?int $policyDays, array $jobDays): string
    {
        $days = $policyDays ? [$policyDays] : $jobDays;
        sort($days);
        if (! $days) {
            return 'Not set';
        }
        $fmt = fn (int $n) => $n % 365 === 0 ? ($n / 365).' year'.($n / 365 === 1 ? '' : 's') : $n.' day'.($n === 1 ? '' : 's');

        return implode(' / ', array_map($fmt, $days));
    }
}
