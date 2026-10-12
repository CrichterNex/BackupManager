<?php

namespace Tests\Feature;

use App\Services\Reports\ReportService;
use App\Services\TestResults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $manager;

    private ApiClient $tech;

    private ApiClient $other;

    private int $companyId;

    private int $otherId;

    private int $serverA;

    private int $serverB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('reportco');
        $this->otherId = $this->makeCompany('otherco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'manager', 'company_manager');
        $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($this->otherId, 'other', 'company_admin');
        $this->admin = $this->login('admin');
        $this->manager = $this->login('manager');
        $this->tech = $this->login('tech');
        $this->other = $this->login('other');

        $this->serverA = $this->admin->post('/api/servers', ['hostname' => 'web-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->serverB = $this->admin->post('/api/servers', ['hostname' => 'db-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $jobA = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverA, 'backup_method' => 'rsync'])->json('data.id');
        $jobB = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverB, 'backup_method' => 'rsync'])->json('data.id');
        foreach ([1, 2, 3] as $d) {
            $this->finishedJob($this->serverA, 'success', $d);
        }
        $this->finishedJob($this->serverB, 'failed', 1);
        DB::table('servers')->where('id', $this->serverA)->update(['last_backup_time' => now()->subHours(2), 'last_backup_status' => 'success']);
        $this->runTest($jobA, ['success' => true, 'hash_algorithm' => 'sha256', 'hash_original' => 'aa', 'hash_restored' => 'aa']);
        $this->runTest($jobA, ['success' => true, 'hash_algorithm' => 'sha256', 'hash_original' => 'bb', 'hash_restored' => 'bb']);
        $this->runTest($jobB, ['success' => false, 'failure_type' => 'hash_mismatch', 'severity' => 'critical', 'hash_original' => 'cc', 'hash_restored' => 'dd', 'error_message' => 'mismatch']);
    }

    private function finishedJob(int $serverId, string $status, int $daysAgo): void
    {
        $done = now()->subDays($daysAgo);
        DB::table('backup_jobs')->insert([
            'company_id' => $this->companyId, 'server_id' => $serverId, 'backup_method' => 'rsync', 'status' => $status,
            'started_time' => $done->copy()->subHours(2), 'completed_time' => $done, 'size_gb' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function runTest(int $jobId, array $result): void
    {
        $id = $this->admin->post("/api/backup-jobs/$jobId/test", ['test_type' => 'random_restore'])->json('data.id');
        $this->asCompany($this->companyId, fn () => TestResults::record($id, $result));
    }

    private function period(): array
    {
        return ['period_start' => now()->subDays(30)->toIso8601String(), 'period_end' => now()->addMinute()->toIso8601String()];
    }

    private function snapshot(ApiClient $c, array $body): array
    {
        $id = $c->post('/api/reports', $body)->json('data.id');

        return $c->get("/api/reports/$id")->json('data.data');
    }

    // --- compliance ---

    public function test_compliance_figures_come_from_recorded_data_and_are_rated_against_targets(): void
    {
        $res = $this->admin->post('/api/reports', ['report_type' => 'compliance', ...$this->period()]);
        $this->assertSame(201, $res->status());
        $d = $this->admin->get('/api/reports/'.$res->json('data.id'))->json('data.data');
        $this->assertEquals(['finished' => 4, 'success' => 3, 'failed' => 1, 'success_rate_pct' => 75.0, 'avg_duration_seconds' => 7200], array_intersect_key($d['backups'], array_flip(['finished', 'success', 'failed', 'success_rate_pct', 'avg_duration_seconds'])));
        $this->assertEquals(['run' => 3, 'passed' => 2, 'failed' => 1, 'pass_rate_pct' => 66.7, 'servers_tested' => 2, 'coverage_pct' => 100.0, 'hash_mismatches' => 1],
            array_intersect_key($d['tests'], array_flip(['run', 'passed', 'failed', 'pass_rate_pct', 'servers_tested', 'coverage_pct', 'hash_mismatches'])));
        $this->assertSame(1, $d['failures']['open_now']['critical']);
        $this->assertSame(0, $d['failures']['open_now']['high']);
        $this->assertSame('non_compliant', $d['certification']['level']);
        $this->assertNotEmpty($d['not_measured']);
    }

    public function test_an_empty_period_is_insufficient_data_not_a_pass(): void
    {
        $d = $this->snapshot($this->admin, ['report_type' => 'compliance', 'period_start' => now()->subDays(400)->toIso8601String(), 'period_end' => now()->subDays(370)->toIso8601String()]);
        $this->assertSame('insufficient_data', $d['certification']['level']);
        $this->assertNull($d['backups']['success_rate_pct']);
    }

    public function test_an_archived_report_stays_fixed_after_the_data_changes(): void
    {
        $id = $this->admin->post('/api/reports', ['report_type' => 'compliance', ...$this->period()])->json('data.id');
        DB::table('backup_jobs')->update(['status' => 'failed']);
        $this->assertSame(3, $this->admin->get("/api/reports/$id")->json('data.data.backups.success'));
    }

    // --- other types ---

    public function test_sla_marks_each_target_met_missed_or_no_data(): void
    {
        $d = $this->snapshot($this->admin, ['report_type' => 'sla', ...$this->period(), 'targets' => ['backup_success_pct' => 70]]);
        $this->assertSame(['met', 'met', 'missed'], array_column($d['metrics'], 'status'));
        $this->assertContains('RTO and RPO actuals', $d['not_measured']);
    }

    public function test_security_report_counts_users_and_audited_activity(): void
    {
        $d = $this->snapshot($this->admin, ['report_type' => 'security', ...$this->period()]);
        $this->assertSame(1, $d['users']['active_admins']);
        $this->assertGreaterThanOrEqual(3, $d['sign_ins']['successful']);
        $this->assertGreaterThan(0, $d['audit']['entries']);
    }

    public function test_dr_report_flags_servers_that_are_not_ready_and_says_why(): void
    {
        $d = $this->snapshot($this->admin, ['report_type' => 'dr_readiness']);
        $byName = array_column($d['servers'], null, 'hostname');
        $this->assertTrue($byName['web-01']['ready']);
        $this->assertFalse($byName['db-01']['ready']);
        $this->assertMatchesRegularExpression('/No backup recorded/', implode(',', $byName['db-01']['reasons']));
        $this->assertSame(['total' => 2, 'ready' => 1, 'not_ready' => 1, 'status' => 'not_ready'], $d['summary']);
        $this->assertNull($d['last_full_recovery_test']);
    }

    // --- access and safety ---

    public function test_managers_can_read_and_generate_but_technicians_cannot(): void
    {
        $this->assertSame(201, $this->manager->post('/api/reports', ['report_type' => 'security', ...$this->period()])->status());
        $this->assertSame(200, $this->manager->get('/api/reports')->status());
        $this->assertSame(403, $this->tech->get('/api/reports')->status());
        $this->assertSame(403, $this->tech->post('/api/reports', ['report_type' => 'dr_readiness'])->status());
    }

    public function test_another_company_never_sees_a_report(): void
    {
        $id = $this->admin->post('/api/reports', ['report_type' => 'dr_readiness'])->json('data.id');
        $this->assertSame(404, $this->other->get("/api/reports/$id")->status());
        $this->assertSame(404, $this->other->get("/api/reports/$id/view")->status());
        $this->assertCount(0, $this->other->get('/api/reports')->json('data'));
    }

    public function test_bad_periods_are_rejected(): void
    {
        $this->assertSame(400, $this->admin->post('/api/reports', ['report_type' => 'compliance'])->status());
        $this->assertSame(400, $this->admin->post('/api/reports', ['report_type' => 'compliance', 'period_start' => now()->subDays(400)->toIso8601String(), 'period_end' => now()->toIso8601String()])->status());
        $this->assertSame(400, $this->admin->post('/api/reports', ['report_type' => 'compliance', 'period_start' => now()->toIso8601String(), 'period_end' => now()->subDay()->toIso8601String()])->status());
    }

    public function test_stored_text_is_escaped_in_the_printable_view(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['name' => '<img src=x onerror=alert(1)>']);
        $id = $this->admin->post('/api/reports', ['report_type' => 'compliance', ...$this->period()])->json('data.id');
        $res = $this->admin->get("/api/reports/$id/view");
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('html', $res->headers->get('Content-Type'));
        $html = $res->getContent();
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
        $this->assertStringContainsString('Not measured by BMS', $html);
    }

    public function test_report_generation_is_audited(): void
    {
        $this->admin->post('/api/reports', ['report_type' => 'dr_readiness']);
        $this->assertGreaterThan(0, DB::table('audit_trail')->where('action', 'report_generated')->where('company_id', $this->companyId)->count());
    }

    // --- scheduled ---

    public function test_last_month_and_last_quarter_are_archived_once_and_only_for_companies_that_existed_then(): void
    {
        DB::table('reports')->delete();
        DB::table('companies')->where('id', $this->companyId)->update(['created_at' => '2020-01-01 00:00:00']);
        DB::table('companies')->where('id', $this->otherId)->update(['created_at' => '2026-03-02 00:00:00']);
        $now = CarbonImmutable::parse('2026-03-15T10:00:00Z');
        $this->assertSame(2, ReportService::generateScheduled($now));
        $this->assertSame(0, ReportService::generateScheduled($now));
        $rows = DB::table('reports')->orderBy('report_type')->get(['company_id', 'report_type', 'period_start']);
        $this->assertSame([[$this->companyId, 'compliance', '2026-02-01 00:00:00'], [$this->companyId, 'sla', '2025-10-01 00:00:00']],
            $rows->map(fn ($r) => [$r->company_id, $r->report_type, $r->period_start])->all());
    }
}
