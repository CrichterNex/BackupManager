<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\Audit;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\ReportService;
use App\Support\ApiException;
use App\Support\Constants;
use App\Support\Page;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    private const COLS = ['id', 'report_type', 'period_start', 'period_end', 'generated_by', 'created_at'];

    public function index(Request $request)
    {
        $q = $request->validate(['type' => ['sometimes', Rule::in(Constants::REPORT_TYPES)]]);
        $query = Report::query()->select(self::COLS)->when(isset($q['type']), fn ($x) => $x->where('report_type', $q['type']))->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json(['success' => true, 'data' => $rows, 'pagination' => $pagination]);
    }

    public function store(Request $request)
    {
        $pct = ['numeric', 'min:0', 'max:100'];
        $b = $request->validate([
            'report_type' => ['required', Rule::in(Constants::REPORT_TYPES)],
            'period_start' => ['sometimes', 'date'],
            'period_end' => ['sometimes', 'date'],
            'targets' => ['sometimes', 'array'],
            'targets.backup_success_pct' => $pct,
            'targets.test_coverage_pct' => $pct,
            'targets.test_success_pct' => $pct,
            'dr' => ['sometimes', 'array'],
            'dr.max_backup_age_hours' => ['numeric', 'min:1', 'max:8760'],
            'dr.max_test_age_days' => ['numeric', 'min:1', 'max:3650'],
        ]);
        $now = CarbonImmutable::now('UTC');
        $from = $to = $now;
        if ($b['report_type'] !== 'dr_readiness') {
            if (! isset($b['period_start'], $b['period_end'])) {
                throw ApiException::badRequest('period_start and period_end are required');
            }
            $from = CarbonImmutable::parse($b['period_start'], 'UTC');
            $to = CarbonImmutable::parse($b['period_end'], 'UTC');
            if ($to <= $from) {
                throw ApiException::badRequest('period_end must be after period_start');
            }
            if ($from->diffInDays($to) > 366) {
                throw ApiException::badRequest('A report period cannot be longer than 366 days');
            }
            if ($to > $now->addDay()) {
                throw ApiException::badRequest('period_end cannot be in the future');
            }
        }
        $report = DB::transaction(function () use ($b, $from, $to, $request) {
            $r = ReportService::generate($b['report_type'], $from, $to, $request->user()->id, ['targets' => $b['targets'] ?? [], 'dr' => $b['dr'] ?? []]);
            Audit::log($request, 'report_generated', 'report', $r->id, ['report_type' => $b['report_type'], 'period_start' => $from->toIso8601ZuluString(), 'period_end' => $to->toIso8601ZuluString()]);

            return $r;
        });

        return response()->json(['success' => true, 'data' => $report->only(self::COLS)], 201);
    }

    public function show(int $id)
    {
        return response()->json(['success' => true, 'data' => Report::find($id) ?? throw ApiException::notFound('Report', 'REPORT_001')]);
    }

    // Print-ready page. ?anonymise=1 takes the names out, so it can be sent outside the company.
    public function view(Request $request, int $id)
    {
        $report = Report::find($id) ?? throw ApiException::notFound('Report', 'REPORT_001');
        $anon = $request->boolean('anonymise');
        $anon && Audit::log($request, 'report_exported', 'report', $report->id, ['format' => 'html', 'anonymised' => true]);

        return response(ReportRenderer::render($report->data, $report->id, $anon))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    // The same report as a PDF file. The file name has no company or server name in it, so it is safe to send as it is.
    public function pdf(Request $request, int $id)
    {
        $report = Report::find($id) ?? throw ApiException::notFound('Report', 'REPORT_001');
        $anon = $request->boolean('anonymise');
        Audit::log($request, 'report_exported', 'report', $report->id, ['format' => 'pdf', 'anonymised' => $anon]);
        $pdf = ReportPdf::make(ReportRenderer::render($report->data, $report->id, $anon, true), $report->report_type !== 'security');

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="bms-'.$report->report_type.'-'.$report->id.($anon ? '-anonymised' : '').'.pdf"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
