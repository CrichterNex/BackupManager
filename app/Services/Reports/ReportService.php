<?php

namespace App\Services\Reports;

use App\Models\Company;
use App\Models\Report;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ReportService
{
    /** Build a report from current data and archive the snapshot, as the current company. */
    public static function generate(string $type, CarbonInterface $from, CarbonInterface $to, ?int $generatedBy, array $opts = []): Report
    {
        $companyId = CompanyContext::id();
        $data = ReportBuilder::build($companyId, $type, $from, $to, $opts);

        return Report::create([
            'report_type' => $type, 'period_start' => $from, 'period_end' => $to, 'data' => $data,
            'generated_by' => $generatedBy, 'created_at' => now(),
        ])->refresh();
    }

    /** Calendar month before $now, in UTC: [from, to). */
    public static function previousMonth(CarbonInterface $now): array
    {
        $first = CarbonImmutable::create($now->year, $now->month, 1, 0, 0, 0, 'UTC');

        return [$first->subMonth(), $first];
    }

    /** Calendar quarter before $now, in UTC: [from, to). */
    public static function previousQuarter(CarbonInterface $now): array
    {
        $startMonth = intdiv($now->month - 1, 3) * 3 + 1;
        $current = CarbonImmutable::create($now->year, $startMonth, 1, 0, 0, 0, 'UTC');

        return [$current->subMonths(3), $current];
    }

    /**
     * Archive the compliance report for last month and the SLA report for last quarter, once each, for every
     * active company that existed during that period. Returns the number of reports created.
     */
    public static function generateScheduled(?CarbonInterface $now = null): int
    {
        $now = ($now ?? now())->utc();
        $plan = [['compliance', ...self::previousMonth($now)], ['sla', ...self::previousQuarter($now)]];
        $made = 0;
        foreach (Company::where('is_active', true)->get() as $company) {
            foreach ($plan as [$type, $from, $to]) {
                if ($company->created_at >= $to) {
                    continue;
                }
                $made += CompanyContext::run($company->id, function () use ($type, $from, $to) {
                    if (Report::where('report_type', $type)->where('period_start', $from)->where('period_end', $to)->exists()) {
                        return 0;
                    }
                    self::generate($type, $from, $to, null);

                    return 1;
                });
            }
        }

        return $made;
    }
}
