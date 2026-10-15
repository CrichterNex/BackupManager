<?php

namespace Tests\Feature;

use App\Services\Engine\Engine;
use App\Services\Reports\ReportScheduling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Reports that make themselves on a timetable. */
class ReportScheduleTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $manager;

    private ApiClient $tech;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('schedco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->admin = $this->login('admin');
        $this->manager = $this->login('mgr');
        $this->tech = $this->login('tech');
    }

    private function make(array $over = [], ?ApiClient $who = null)
    {
        return ($who ?? $this->admin)->post('/api/report-schedules', $over + ['name' => 'Monthly compliance', 'report_type' => 'compliance', 'period' => 'previous_month', 'frequency' => 'monthly', 'run_time' => '06:00', 'run_day' => 1, 'timezone' => 'UTC']);
    }

    private function due(int $id): void
    {
        DB::table('report_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
    }

    // ---------------------------------------------------------------- making a schedule

    public function test_each_kind_of_schedule_gets_the_right_timetable(): void
    {
        $m = $this->make()->assertStatus(201)->json('data');
        $this->assertSame(['0 6 1 * *', 'On day 1 of every month at 06:00, UTC', 'The previous calendar month'], [$m['cron'], $m['describe'], $m['period_text']]);
        $this->assertNotNull($m['next_run_at']);

        $w = $this->make(['name' => 'Weekly', 'frequency' => 'weekly', 'run_day' => 1, 'period' => 'previous_week'])->assertStatus(201)->json('data');
        $this->assertSame(['0 6 * * 1', 'Every Monday at 06:00, UTC'], [$w['cron'], $w['describe']]);

        $q = $this->make(['name' => 'Quarterly', 'frequency' => 'quarterly', 'run_day' => 2, 'period' => 'previous_quarter', 'report_type' => 'sla'])->assertStatus(201)->json('data');
        $this->assertSame(['0 6 2 1,4,7,10 *', 'On day 2 of January, April, July and October at 06:00, UTC'], [$q['cron'], $q['describe']]);

        $c = $this->make(['name' => 'Custom', 'frequency' => 'custom', 'cron' => '0 5 * * 1-5', 'run_time' => null, 'run_day' => null])->assertStatus(201)->json('data');
        $this->assertSame('0 5 * * 1-5', $c['cron']);
    }

    public function test_a_readiness_report_needs_no_period_and_the_others_do(): void
    {
        $dr = $this->make(['report_type' => 'dr_readiness', 'period' => 'last_30'])->assertStatus(201)->json('data');
        $this->assertNull($dr['period']);
        $this->assertSame('A snapshot of right now', $dr['period_text']);
        $this->make(['report_type' => 'sla', 'period' => null])->assertStatus(400);
        $this->make(['report_type' => 'security', 'period' => 'last_year'])->assertStatus(400);
    }

    public function test_bad_timetables_are_refused(): void
    {
        $this->make(['frequency' => 'custom', 'cron' => '*/30 * * * *'])->assertStatus(400);      // more than once a day
        $this->make(['frequency' => 'custom', 'cron' => 'nonsense'])->assertStatus(400);
        $this->make(['run_time' => '25:00'])->assertStatus(400);
        $this->make(['frequency' => 'weekly', 'run_day' => null])->assertStatus(400);
        $this->make(['frequency' => 'monthly', 'run_day' => 29])->assertStatus(400);
        $this->make(['timezone' => 'Mars/Olympus'])->assertStatus(400);
        $this->make(['report_type' => 'nonsense'])->assertStatus(400);
    }

    public function test_who_may_schedule_and_whose_schedules_are_whose(): void
    {
        $this->make([], $this->tech)->assertStatus(403);
        $this->tech->get('/api/report-schedules')->assertStatus(403);
        $id = $this->make(['name' => 'By a manager'], $this->manager)->assertStatus(201)->json('data.id');
        $this->assertCount(1, $this->admin->get('/api/report-schedules')->json('data'));

        $this->makeCompany('rival');
        $this->makeUser(DB::table('companies')->where('slug', 'rival')->value('id'), 'rivaladmin', 'company_admin');
        $rival = $this->login('rivaladmin');
        $this->assertCount(0, $rival->get('/api/report-schedules')->json('data'));
        $rival->put("/api/report-schedules/$id", ['enabled' => false])->assertStatus(404);
        $rival->post("/api/report-schedules/$id/run")->assertStatus(404);
        $rival->del("/api/report-schedules/$id")->assertStatus(404);
    }

    public function test_a_schedule_can_be_changed_paused_and_deleted(): void
    {
        $id = $this->make()->json('data.id');
        $r = $this->admin->put("/api/report-schedules/$id", ['frequency' => 'weekly', 'run_day' => 3, 'period' => 'previous_week'])->assertOk()->json('data');
        $this->assertSame('0 6 * * 3', $r['cron']);
        $p = $this->admin->put("/api/report-schedules/$id", ['enabled' => false])->assertOk()->json('data');
        $this->assertNull($p['next_run_at'], 'a paused schedule is not due');
        $this->assertNotNull($this->admin->put("/api/report-schedules/$id", ['enabled' => true])->json('data.next_run_at'));
        $this->admin->del("/api/report-schedules/$id")->assertNoContent();
        $this->assertCount(0, $this->admin->get('/api/report-schedules')->json('data'));
        $this->assertSame(['report_schedule_created', 'report_schedule_updated', 'report_schedule_deleted'], DB::table('audit_trail')->where('action', 'like', 'report_schedule_%')->orderBy('id')->pluck('action')->unique()->values()->all());
    }

    // ---------------------------------------------------------------- making the reports

    public function test_a_due_schedule_makes_its_report_for_the_right_period_and_moves_on(): void
    {
        $id = $this->make()->json('data.id');
        $this->due($id);
        $this->assertSame(1, ReportScheduling::runDue($this->companyId));
        $rep = DB::table('reports')->first();
        $first = CarbonImmutable::now('UTC')->startOfMonth();
        $this->assertSame(['compliance', $first->subMonth()->format('Y-m-d'), $first->format('Y-m-d')], [$rep->report_type, substr($rep->period_start, 0, 10), substr($rep->period_end, 0, 10)]);
        $s = DB::table('report_schedules')->find($id);
        $this->assertSame($rep->id, (int) $s->last_report_id);
        $this->assertNull($s->last_error);
        $this->assertGreaterThan(now()->timestamp, strtotime($s->next_run_at.' UTC'), 'the next one is in the future');
        $this->assertSame(0, ReportScheduling::runDue($this->companyId), 'not made twice');
    }

    public function test_it_is_made_once_not_once_for_every_time_the_engine_was_off(): void
    {
        $id = $this->make(['frequency' => 'weekly', 'run_day' => 1, 'period' => 'previous_week'])->json('data.id');
        DB::table('report_schedules')->where('id', $id)->update(['next_run_at' => now()->subWeeks(5)]);
        $this->assertSame(1, ReportScheduling::runDue($this->companyId));
        $this->assertSame(1, DB::table('reports')->count());
    }

    public function test_a_paused_schedule_makes_nothing_and_a_readiness_snapshot_is_made_now(): void
    {
        $paused = $this->make()->json('data.id');
        $this->admin->put("/api/report-schedules/$paused", ['enabled' => false]);
        $this->due($paused);
        $this->assertSame(0, ReportScheduling::runDue($this->companyId));

        $dr = $this->make(['name' => 'Readiness', 'report_type' => 'dr_readiness', 'frequency' => 'weekly', 'run_day' => 5])->json('data.id');
        $this->due($dr);
        $this->assertSame(1, ReportScheduling::runDue($this->companyId));
        $this->assertSame('dr_readiness', DB::table('reports')->value('report_type'));
    }

    public function test_admins_are_told_and_the_audit_trail_says_it_was_made_by_a_schedule(): void
    {
        $id = $this->make()->json('data.id');
        $this->due($id);
        ReportScheduling::runDue($this->companyId);
        $n = DB::table('notifications')->where('event_type', 'report_ready')->get();
        $this->assertCount(1, $n, 'one for the admin; managers are not company admins');
        $this->assertStringContainsString('Monthly compliance', $n[0]->subject);
        $a = DB::table('audit_trail')->where('action', 'report_generated')->first();
        $this->assertStringContainsString('"scheduled":true', $a->details);
        $this->assertNull($a->user_id);
    }

    public function test_run_now_makes_a_report_without_moving_the_schedule(): void
    {
        $id = $this->make()->json('data.id');
        $next = DB::table('report_schedules')->where('id', $id)->value('next_run_at');
        $r = $this->admin->post("/api/report-schedules/$id/run")->assertStatus(201)->json('data');
        $this->assertSame($r['report_id'], (int) DB::table('reports')->value('id'));
        $this->assertSame($next, DB::table('report_schedules')->where('id', $id)->value('next_run_at'));
        $this->assertNotNull(DB::table('report_schedules')->where('id', $id)->value('last_run_at'));
        $this->assertSame($this->admin->get('/api/reports')->json('data.0.id'), $r['report_id']);
    }

    public function test_the_engine_makes_due_reports(): void
    {
        $id = $this->make()->json('data.id');
        $this->due($id);
        $before = DB::table('reports')->count();
        $this->assertGreaterThanOrEqual(1, Engine::tick()['reports']);
        $this->assertGreaterThan($before, DB::table('reports')->count());
    }

    public function test_the_periods_are_worked_out_from_the_day_it_runs(): void
    {
        $wed = CarbonImmutable::parse('2026-10-14 06:00:00', 'UTC');
        [$a, $b] = ReportScheduling::range('previous_week', $wed);
        $this->assertSame(['2026-10-05', '2026-10-12'], [$a->format('Y-m-d'), $b->format('Y-m-d')]);
        [$a, $b] = ReportScheduling::range('previous_month', $wed);
        $this->assertSame(['2026-09-01', '2026-10-01'], [$a->format('Y-m-d'), $b->format('Y-m-d')]);
        [$a, $b] = ReportScheduling::range('previous_quarter', $wed);
        $this->assertSame(['2026-07-01', '2026-10-01'], [$a->format('Y-m-d'), $b->format('Y-m-d')]);
        [$a, $b] = ReportScheduling::range('last_30', $wed);
        $this->assertSame(['2026-09-14', '2026-10-14'], [$a->format('Y-m-d'), $b->format('Y-m-d')]);
    }
}
