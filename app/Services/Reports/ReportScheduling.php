<?php

namespace App\Services\Reports;

use App\Models\ReportSchedule;
use App\Services\Audit;
use App\Services\TestResults;
use App\Services\TestScheduling;
use App\Support\ApiException;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;

/** Reports on a timetable: working out when one is next due, which period it covers, and making it when it is. */
final class ReportScheduling
{
    public const PERIODS = [
        'previous_week' => 'The previous week (Monday to Sunday)', 'previous_month' => 'The previous calendar month', 'previous_quarter' => 'The previous calendar quarter',
        'last_30' => 'The last 30 days', 'last_90' => 'The last 90 days',
    ];

    /** Cron for the friendly choices (weekly, monthly, quarterly) or a custom one that runs at most once a day. */
    public static function cronFor(string $frequency, ?string $time, ?int $day, ?string $custom): string
    {
        if ($frequency === 'quarterly') {
            if (! $time || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
                throw ApiException::badRequest('Give the time of day as HH:MM, for example 06:00.');
            }
            if ($day === null || $day < 1 || $day > 28) {
                throw ApiException::badRequest('Choose a day of the month from 1 to 28, so it exists in every month.');
            }

            return (int) $m[2].' '.(int) $m[1]." $day 1,4,7,10 *";
        }
        $cron = TestScheduling::cronFor($frequency, $time, $day, $custom);
        $c = new CronExpression($cron);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($c->getNextRunDate($now, 1, false, 'UTC')->getTimestamp() - $c->getNextRunDate($now, 0, false, 'UTC')->getTimestamp() < 86400) {
            throw ApiException::badRequest('A report can be made at most once a day. Choose a less frequent schedule.');
        }

        return $cron;
    }

    public static function nextRun(string $cron, string $timezone, ?\DateTimeInterface $from = null): CarbonImmutable
    {
        return TestScheduling::nextRun($cron, $timezone, $from);
    }

    public static function describe(ReportSchedule $s): string
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $when = match ($s->frequency) {
            'weekly' => 'Every '.($days[$s->run_day] ?? '?')." at {$s->run_time}",
            'monthly' => "On day {$s->run_day} of every month at {$s->run_time}",
            'quarterly' => "On day {$s->run_day} of January, April, July and October at {$s->run_time}",
            default => "Custom schedule ({$s->cron})",
        };

        return "$when, {$s->timezone}";
    }

    /** [from, to) in UTC for a period, or [now, now] for a readiness snapshot. @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function range(?string $period, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        return match ($period) {
            'previous_week' => [$now->startOfWeek()->subWeek(), $now->startOfWeek()],
            'previous_month' => ReportService::previousMonth($now),
            'previous_quarter' => ReportService::previousQuarter($now),
            'last_30' => [$now->subDays(30), $now],
            'last_90' => [$now->subDays(90), $now],
            default => [$now, $now],
        };
    }

    /** Make the report a schedule describes. Returns its id. */
    public static function make(ReportSchedule $s, ?int $userId, ?CarbonImmutable $now = null): int
    {
        [$from, $to] = self::range($s->report_type === 'dr_readiness' ? null : $s->period, $now);
        $report = ReportService::generate($s->report_type, $from, $to, $userId);
        Audit::log(null, 'report_generated', 'report', $report->id, ['report_type' => $s->report_type, 'scheduled' => true, 'schedule' => $s->name, 'by_user' => $userId !== null],
            true, ['company_id' => $s->company_id, 'user_id' => $userId]);

        return $report->id;
    }

    /** Make every report that has fallen due for a company. A schedule the engine was too late for makes one report, not one for each missed time. Returns how many were made. */
    public static function runDue(int $companyId, ?CarbonImmutable $now = null): int
    {
        return CompanyContext::run($companyId, function () use ($now) {
            $made = 0;
            foreach (ReportSchedule::where('enabled', true)->where('next_run_at', '<=', $now ?? now())->get() as $s) {
                $id = $error = null;
                try {
                    $id = DB::transaction(fn () => self::make($s, null, $now));
                    $made++;
                } catch (\Throwable $e) {
                    $error = mb_substr($e->getMessage(), 0, 300);
                }
                $s->forceFill(['last_run_at' => $now ?? now(), 'last_report_id' => $id ?? $s->last_report_id, 'last_error' => $error, 'next_run_at' => self::nextRun($s->cron, $s->timezone, $now)])->save();
                if ($id) {
                    TestResults::notifyAdmins('report_ready', "Scheduled report ready: {$s->name}", 'The report "'.$s->name.'" has been made. Open Reports to view it or download it as a PDF.', 'report', $id);
                }
            }

            return $made;
        });
    }
}
