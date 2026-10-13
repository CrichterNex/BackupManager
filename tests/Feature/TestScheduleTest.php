<?php

namespace Tests\Feature;

use App\Models\BackupTestExecution;
use App\Models\TestSchedule;
use App\Services\Engine\Engine;
use App\Services\TestScheduling;
use App\Support\Constants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Help text for each test, and recurring schedules that queue them. */
class TestScheduleTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $manager;

    private ApiClient $tech;

    private ApiClient $rival;

    private int $companyId;

    private int $serverA;

    private int $serverB;

    private int $jobA;

    private int $jobB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('schedco');
        $other = $this->makeCompany('rivalsched');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($other, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->manager = $this->login('mgr');
        $this->tech = $this->login('tech');
        $this->rival = $this->login('rival');
        $this->serverA = $this->admin->post('/api/servers', ['hostname' => 'alpha', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->serverB = $this->admin->post('/api/servers', ['hostname' => 'beta', 'os_type' => 'linux', 'backup_methods' => ['rsync', 'acronis']])->json('data.id');
        $this->jobA = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverA, 'backup_method' => 'rsync'])->json('data.id');
        $this->jobB = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverB, 'backup_method' => 'rsync'])->json('data.id');
        DB::table('backup_jobs')->whereIn('id', [$this->jobA, $this->jobB])->update(['status' => 'success']);
    }

    private function make(array $over = [])
    {
        return $this->admin->post('/api/test-schedules', $over + ['name' => 'Weekly', 'test_type' => 'random_restore', 'target_type' => 'all',
            'frequency' => 'weekly', 'run_day' => 1, 'run_time' => '02:00', 'timezone' => 'UTC']);
    }

    private function executions(): int
    {
        return DB::table('backup_test_executions')->where('company_id', $this->companyId)->count();
    }

    public function test_every_test_type_has_help_text_and_says_honestly_whether_it_runs(): void
    {
        $types = collect($this->tech->get('/api/test-types')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(Constants::TEST_TYPES, $types->pluck('type')->all());
        foreach ($types as $t) {
            $this->assertNotSame('', $t['title']);
            $this->assertNotSame('', $t['summary']);
            $this->assertNotSame('', $t['details']);
        }
        $this->assertTrue($types->firstWhere('type', 'random_restore')['automated']);
        $notRun = $types->where('automated', false);
        $this->assertEqualsCanonicalizing(['database_integrity', 'incremental_chain', 'm365_mailbox'], $notRun->pluck('type')->all());
        foreach ($notRun as $t) {
            $this->assertStringContainsString('skipped', $t['details'], $t['type'].' must not claim to run');
        }
    }

    public function test_an_admin_creates_a_schedule_and_it_works_out_the_cron_and_the_next_run(): void
    {
        $r = $this->make()->assertStatus(201);
        $this->assertSame('0 2 * * 1', $r->json('data.cron'));
        $this->assertSame('Every Monday at 02:00, UTC', $r->json('data.describe'));
        $this->assertSame('Every server with a backup BMS can test', $r->json('data.target_name'));
        $next = CarbonImmutable::parse($r->json('data.next_run_at'));
        $this->assertTrue($next->isFuture());
        $this->assertSame(1, $next->dayOfWeek);
        $this->assertSame(2, $next->hour);
    }

    public function test_the_time_is_in_the_schedules_time_zone(): void
    {
        $r = $this->make(['timezone' => 'Africa/Johannesburg', 'frequency' => 'daily', 'run_time' => '02:00'])->assertStatus(201);
        $this->assertSame('0 2 * * *', $r->json('data.cron'));
        $this->assertSame(0, CarbonImmutable::parse($r->json('data.next_run_at'))->hour, '02:00 in South Africa (UTC+2) is 00:00 UTC');
    }

    public function test_daily_weekly_monthly_and_custom_each_validate(): void
    {
        $this->make(['frequency' => 'monthly', 'run_day' => 15, 'run_time' => '03:30'])->assertStatus(201)->assertJsonPath('data.cron', '30 3 15 * *');
        $this->make(['frequency' => 'custom', 'cron' => '0 4 * * 1-5'])->assertStatus(201)->assertJsonPath('data.cron', '0 4 * * 1-5');
        foreach ([['frequency' => 'monthly', 'run_day' => 31], ['frequency' => 'weekly', 'run_day' => null], ['frequency' => 'daily', 'run_time' => '25:00'],
            ['frequency' => 'daily', 'run_time' => null], ['frequency' => 'custom', 'cron' => 'nonsense'], ['timezone' => 'Mars/Base'],
            ['test_type' => 'database_integrity'], ['name' => ''], ['target_type' => 'server', 'server_id' => 999999], ['target_type' => 'job', 'backup_job_id' => 999999]] as $bad) {
            $this->make($bad)->assertStatus(400);
        }
    }

    public function test_a_schedule_more_often_than_hourly_is_refused(): void
    {
        $this->make(['frequency' => 'custom', 'cron' => '* * * * *'])->assertStatus(400);
        $this->make(['frequency' => 'custom', 'cron' => '*/30 * * * *'])->assertStatus(400);
        $this->make(['frequency' => 'custom', 'cron' => '0 * * * *'])->assertStatus(201);
    }

    public function test_a_due_schedule_queues_each_servers_newest_successful_backup_once_and_moves_on(): void
    {
        $id = $this->make()->json('data.id');
        DB::table('test_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(2, TestScheduling::runDue($this->companyId));
        $this->assertSame(2, $this->executions());
        $e = BackupTestExecution::withoutGlobalScopes()->first();
        $this->assertSame('random_restore', $e->test_type);
        $this->assertNull($e->executed_by, 'automated');
        $this->assertSame('Scheduled: Weekly', $e->notes);

        $s = DB::table('test_schedules')->find($id);
        $this->assertTrue(CarbonImmutable::parse($s->next_run_at, 'UTC')->isFuture());
        $this->assertSame(2, $s->last_queued);
        $this->assertSame(0, TestScheduling::runDue($this->companyId), 'not due again until its next time');
    }

    public function test_a_test_already_pending_for_a_job_is_not_stacked_up(): void
    {
        $id = $this->make(['target_type' => 'job', 'backup_job_id' => $this->jobA])->json('data.id');
        $this->admin->post("/api/backup-jobs/{$this->jobA}/test", ['test_type' => 'random_restore'])->assertStatus(202);
        DB::table('test_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(0, TestScheduling::runDue($this->companyId));
        $this->assertSame(1, $this->executions());
    }

    public function test_a_server_schedule_only_tests_that_server_and_ignores_platform_backups(): void
    {
        $acronis = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverB, 'backup_method' => 'acronis'])->json('data.id');
        DB::table('backup_jobs')->where('id', $acronis)->update(['status' => 'success']);
        $id = $this->make(['target_type' => 'server', 'server_id' => $this->serverB])->json('data.id');
        DB::table('test_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(1, TestScheduling::runDue($this->companyId));
        $this->assertSame($this->jobB, (int) DB::table('backup_test_executions')->value('backup_job_id'));
    }

    public function test_a_paused_schedule_never_runs_and_resuming_sets_the_next_run(): void
    {
        $id = $this->make()->json('data.id');
        $this->admin->put("/api/test-schedules/$id", ['enabled' => false])->assertOk()->assertJsonPath('data.next_run_at', null);
        DB::table('test_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(0, TestScheduling::runDue($this->companyId));
        $this->admin->put("/api/test-schedules/$id", ['enabled' => true])->assertOk();
        $this->assertTrue(CarbonImmutable::parse(DB::table('test_schedules')->find($id)->next_run_at, 'UTC')->isFuture());
    }

    public function test_editing_recalculates_when_it_next_runs(): void
    {
        $id = $this->make()->json('data.id');
        $r = $this->admin->put("/api/test-schedules/$id", ['frequency' => 'daily', 'run_time' => '05:15'])->assertOk();
        $this->assertSame('15 5 * * *', $r->json('data.cron'));
        $this->assertNull($r->json('data.run_day'), 'a daily schedule has no day');
        $this->assertSame(5, CarbonImmutable::parse($r->json('data.next_run_at'))->hour);
    }

    public function test_run_now_queues_the_tests_without_moving_the_schedule(): void
    {
        $id = $this->make()->json('data.id');
        $before = DB::table('test_schedules')->find($id)->next_run_at;
        $this->admin->post("/api/test-schedules/$id/run")->assertStatus(202)->assertJsonPath('data.queued', 2);
        $this->assertSame($before, DB::table('test_schedules')->find($id)->next_run_at);
        $this->assertSame($this->adminId(), (int) DB::table('backup_test_executions')->value('executed_by'));
        $this->admin->post("/api/test-schedules/$id/run")->assertOk()->assertJsonPath('data.queued', 0);
    }

    public function test_delete_removes_it_and_every_change_is_audited(): void
    {
        $id = $this->make()->json('data.id');
        $this->admin->put("/api/test-schedules/$id", ['name' => 'Renamed'])->assertOk();
        $this->admin->post("/api/test-schedules/$id/run")->assertStatus(202);
        $this->admin->del("/api/test-schedules/$id")->assertNoContent();
        $this->assertSame(0, TestSchedule::withoutGlobalScopes()->count());
        $actions = collect($this->admin->get('/api/audit?limit=50')->json('data'))->pluck('action');
        foreach (['test_schedule_created', 'test_schedule_updated', 'test_schedule_run_now', 'test_schedule_deleted'] as $a) {
            $this->assertTrue($actions->contains($a), $a);
        }
    }

    public function test_only_admins_manage_managers_can_see_technicians_cannot_and_other_companies_see_nothing(): void
    {
        $id = $this->make()->json('data.id');
        foreach ([$this->manager, $this->tech] as $who) {
            $who->post('/api/test-schedules', ['name' => 'x'])->assertStatus(403);
            $who->put("/api/test-schedules/$id", ['enabled' => false])->assertStatus(403);
            $who->del("/api/test-schedules/$id")->assertStatus(403);
            $who->post("/api/test-schedules/$id/run")->assertStatus(403);
        }
        $this->assertCount(1, $this->manager->get('/api/test-schedules')->assertOk()->json('data'));
        $this->tech->get('/api/test-schedules')->assertStatus(403);
        $this->assertSame([], $this->rival->get('/api/test-schedules')->json('data'));
        $this->rival->put("/api/test-schedules/$id", ['enabled' => false])->assertStatus(404);
        $this->rival->del("/api/test-schedules/$id")->assertStatus(404);
        $this->rival->post("/api/test-schedules/$id/run")->assertStatus(404);
    }

    public function test_a_schedule_cannot_point_at_another_companys_server_or_job(): void
    {
        $theirServer = DB::table('servers')->insertGetId(['company_id' => $this->makeCompany('third'), 'hostname' => 'theirs', 'os_type' => 'linux', 'backup_methods' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $this->make(['target_type' => 'server', 'server_id' => $theirServer])->assertStatus(400);
    }

    private function waitingTest(int $job, int $minutes = 55): int
    {
        return DB::table('backup_test_executions')->insertGetId(['company_id' => $this->companyId, 'backup_job_id' => $job, 'test_type' => 'random_restore', 'test_status' => 'pending',
            'scheduled_time' => now()->addMinutes($minutes), 'retry_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_run_now_brings_a_waiting_test_forward_and_the_engine_then_takes_it(): void
    {
        $id = $this->waitingTest($this->jobA);
        $this->assertSame(0, Engine::runDueTests($this->companyId), 'not due yet');
        $this->admin->post("/api/tests/$id/run-now")->assertStatus(202)->assertJsonPath('data.test_status', 'pending');
        $this->assertTrue(CarbonImmutable::parse(DB::table('backup_test_executions')->find($id)->scheduled_time, 'UTC')->lte(now()->addSecond()));
        $this->assertSame(1, Engine::runDueTests($this->companyId));
        $this->assertNotSame('pending', DB::table('backup_test_executions')->find($id)->test_status);
        $e = collect($this->admin->get('/api/audit?limit=50')->json('data'))->firstWhere('action', 'test_run_now');
        $this->assertSame(2, $e['details']['attempt'], 'it is still the second attempt, only sooner');
    }

    public function test_only_a_waiting_test_can_be_run_now(): void
    {
        foreach (['running', 'success', 'failed', 'skipped', 'cancelled'] as $status) {
            $id = $this->waitingTest($this->jobA);
            DB::table('backup_test_executions')->where('id', $id)->update(['test_status' => $status]);
            $this->admin->post("/api/tests/$id/run-now")->assertStatus(409)->assertJsonPath('error_code', 'TEST_004');
        }
    }

    public function test_run_now_follows_the_run_test_permission_and_company_and_assignment_limits(): void
    {
        $id = $this->waitingTest($this->jobA);
        $this->manager->post("/api/tests/$id/run-now")->assertStatus(403);
        $this->rival->post("/api/tests/$id/run-now")->assertStatus(404);
        $this->tech->post("/api/tests/$id/run-now")->assertStatus(404);
        $techId = (int) DB::table('users')->where('username', 'tech')->value('id');
        $this->admin->put("/api/servers/{$this->serverA}/assignments", ['user_ids' => [$techId]])->assertOk();
        $this->tech->post("/api/tests/$id/run-now")->assertStatus(202);
    }

    private function adminId(): int
    {
        return (int) DB::table('users')->where('username', 'admin')->value('id');
    }
}
