<?php

namespace Tests\Feature;

use App\Models\TestSchedule;
use App\Services\Engine\Engine;
use App\Services\TestScheduling;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Recovery point, location write and read-back, full read-back and file permissions: queueing, results and the checks BMS makes itself. */
class MoreTestTypesTest extends TestCase
{
    private ApiClient $admin;

    private int $companyId;

    private int $serverId;

    private int $jobId;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('morecotest');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'file-01', 'os_type' => 'linux', 'backup_methods' => ['rsync', 'acronis']])->json('data.id');
        $this->jobId = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'rsync'])->json('data.id');
    }

    private function enroll(): void
    {
        $this->secret = $this->admin->post("/api/servers/{$this->serverId}/agent")->json('agent_config.token');
    }

    private function agent(string $url, array $body)
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer {$this->secret}"])->postJson($url, $body);
    }

    /** @param  list<string>|null  $tests */
    private function poll(?array $tests = ['random_restore', 'synthetic_file', 'full_recovery', 'file_permissions'], array $timeouts = []): array
    {
        $rt = ['type' => 'restore_test'] + ($tests !== null ? ['tests' => $tests] : []) + ($timeouts ? ['test_timeouts' => $timeouts] : []);

        return $this->agent('/api/agent/poll', ['version' => '1.9.0', 'actions' => ['restore-test' => $rt]])->json();
    }

    private function queue(string $type, ?int $job = null): int
    {
        return $this->admin->post('/api/backup-jobs/'.($job ?? $this->jobId).'/test', ['test_type' => $type])->assertStatus(202)->json('data.id');
    }

    private function test(int $id): object
    {
        return DB::table('backup_test_executions')->find($id);
    }

    private function succeeded(int $job, $completed): void
    {
        DB::table('backup_jobs')->where('id', $job)->update(['status' => 'success', 'completed_time' => $completed]);
    }

    // ---- recovery point (BMS's own check) ----

    public function test_a_recent_successful_backup_passes_and_the_summary_says_how_old_it_is(): void
    {
        $this->succeeded($this->jobId, now()->subHours(5));
        $id = $this->queue('recovery_point');
        Engine::runDueTests($this->companyId);
        $t = $this->test($id);
        $this->assertSame('success', $t->test_status);
        $this->assertStringContainsString('5 hours old (allowed 36)', $t->summary);
        $this->assertSame('passed', DB::table('backup_jobs')->find($this->jobId)->test_status);
    }

    public function test_an_old_or_missing_backup_fails_with_a_failure_record_and_no_quiet_retry(): void
    {
        $this->succeeded($this->jobId, now()->subHours(80));
        $id = $this->queue('recovery_point');
        Engine::runDueTests($this->companyId);
        $t = $this->test($id);
        $this->assertSame('failed', $t->test_status);
        $this->assertStringContainsString('80 hours old, more than the 36 hours allowed', $t->error_message);
        $this->assertSame(1, DB::table('backup_test_failures')->where('test_execution_id', $id)->count());
        $this->assertSame(0, DB::table('backup_test_executions')->where('retry_of', $id)->count(), 'an old backup does not fix itself in an hour, so no quiet retry');

        DB::table('backup_jobs')->where('id', $this->jobId)->update(['status' => 'failed']);
        $id2 = $this->queue('recovery_point');
        Engine::runDueTests($this->companyId);
        $this->assertSame('failed', $this->test($id2)->test_status);
        $this->assertStringContainsString('No successful backup is recorded', $this->test($id2)->error_message);
    }

    public function test_a_jobs_schedule_sets_how_old_a_backup_may_be(): void
    {
        DB::table('backup_jobs')->where('id', $this->jobId)->update(['schedule' => '0 2 * * *']);
        $this->succeeded($this->jobId, now()->subHours(25));
        $id = $this->queue('recovery_point');
        Engine::runDueTests($this->companyId);
        $this->assertSame('success', $this->test($id)->test_status, 'daily: 1.5 x 24 + 2 = 38 hours');
        $this->assertStringContainsString('allowed 38', $this->test($id)->summary);

        DB::table('backup_jobs')->where('id', $this->jobId)->update(['schedule' => '0 2 * * 1']);
        $id2 = $this->queue('recovery_point');
        Engine::runDueTests($this->companyId);
        $this->assertStringContainsString('allowed 254', $this->test($id2)->summary, 'weekly: 1.5 x 168 + 2');
    }

    public function test_recovery_point_works_for_a_platform_backup_with_no_agent_and_no_location(): void
    {
        $acronis = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'acronis'])->json('data.id');
        $this->succeeded($acronis, now()->subHours(3));
        $id = $this->queue('recovery_point', $acronis);
        Engine::runDueTests($this->companyId);
        $this->assertSame('success', $this->test($id)->test_status);
        $other = $this->queue('random_restore', $acronis);
        Engine::runDueTests($this->companyId);
        $this->assertSame('skipped', $this->test($other)->test_status, 'but a restore test still cannot read a platform backup');
    }

    public function test_the_recovery_point_for_a_job_looks_only_at_its_own_method(): void
    {
        $acronis = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'acronis'])->json('data.id');
        $this->succeeded($acronis, now()->subHours(1));
        $this->succeeded($this->jobId, now()->subHours(100));
        $id = $this->queue('recovery_point');
        Engine::runDueTests($this->companyId);
        $this->assertSame('failed', $this->test($id)->test_status, 'a fresh Acronis backup does not vouch for a stale rsync one');
    }

    public function test_the_summary_is_shown_by_the_api(): void
    {
        $this->succeeded($this->jobId, now()->subHours(2));
        $id = $this->queue('recovery_point');
        Engine::runDueTests($this->companyId);
        $this->assertStringContainsString('Latest recovery point', collect($this->admin->get('/api/tests')->json('data'))->firstWhere('id', $id)['summary']);
    }

    // ---- tests that run on the agent ----

    public function test_each_agent_test_is_queued_with_its_own_type(): void
    {
        $this->enroll();
        $this->poll();
        foreach (['synthetic_file', 'full_recovery', 'file_permissions', 'random_restore'] as $type) {
            $id = $this->queue($type);
            Engine::runDueTests($this->companyId);
            $cmd = DB::table('agent_commands')->where('test_execution_id', $id)->first();
            $this->assertSame($type, $cmd->test_type);
            $this->assertSame($type, $this->poll()['command']['test_type'] ?? null, "the agent is told which test to run: $type");
            DB::table('agent_commands')->where('id', $cmd->id)->update(['status' => 'success']);
        }
    }

    public function test_an_agent_that_does_not_list_the_test_is_never_asked_for_it(): void
    {
        $this->enroll();
        $this->poll(null);
        foreach (['synthetic_file', 'full_recovery', 'file_permissions'] as $type) {
            $id = $this->queue($type);
            Engine::runDueTests($this->companyId);
            $this->assertSame('skipped', $this->test($id)->test_status, $type);
            $this->assertStringContainsString("cannot run the $type test", $this->test($id)->error_message);
        }
        $this->assertSame(0, DB::table('agent_commands')->where('type', 'restore_test')->count());
        $id = $this->queue('random_restore');
        Engine::runDueTests($this->companyId);
        $this->assertSame(1, DB::table('agent_commands')->where('test_execution_id', $id)->count(), 'an older agent can still run a random restore');
    }

    public function test_the_file_tests_are_skipped_with_a_clear_reason_when_the_server_has_no_agent(): void
    {
        foreach (['synthetic_file', 'full_recovery', 'file_permissions'] as $type) {
            $id = $this->queue($type);
            Engine::runDueTests($this->companyId);
            $this->assertSame('skipped', $this->test($id)->test_status);
            $this->assertStringContainsString("runs on the server's agent", $this->test($id)->error_message);
        }
    }

    public function test_a_full_read_gets_its_own_longer_time_limit(): void
    {
        $this->enroll();
        $this->poll(timeouts: ['full_recovery' => 6 * 3600, 'random_restore' => 300]);
        $this->queue('full_recovery');
        Engine::runDueTests($this->companyId);
        $cmd = $this->poll(timeouts: ['full_recovery' => 6 * 3600, 'random_restore' => 300])['command'];
        $this->assertEqualsWithDelta(6 * 3600 + 30 * 60, now()->diffInSeconds(DB::table('agent_commands')->find($cmd['id'])->deadline_at, false), 5);
    }

    public function test_an_agents_result_is_recorded_with_what_it_found(): void
    {
        $this->enroll();
        $this->poll();
        $id = $this->queue('full_recovery');
        Engine::runDueTests($this->companyId);
        $cmdId = $this->poll()['command']['id'];
        $this->agent("/api/agent/commands/$cmdId/result", ['status' => 'success', 'output' => 'ok', 'details' => ['summary' => '1,204 files, 18.2 GB read, all readable', 'files' => 1204, 'bytes' => 19541508096]])->assertOk();
        $t = $this->test($id);
        $this->assertSame('success', $t->test_status);
        $this->assertSame('1,204 files, 18.2 GB read, all readable', $t->summary);
        $this->assertSame(1204, json_decode($t->details, true)['files']);
    }

    public function test_an_agent_that_could_not_judge_the_test_makes_it_skipped_never_a_pass(): void
    {
        $this->enroll();
        $this->poll();
        $id = $this->queue('file_permissions');
        Engine::runDueTests($this->companyId);
        $cmdId = $this->poll()['command']['id'];
        $this->agent("/api/agent/commands/$cmdId/result", ['status' => 'success', 'details' => ['skip_reason' => 'This job does not say how its folders are laid out.']])->assertOk();
        $t = $this->test($id);
        $this->assertSame('skipped', $t->test_status);
        $this->assertStringContainsString('laid out', $t->error_message);
        $this->assertNull(DB::table('backup_jobs')->find($this->jobId)->test_status, 'a skip does not change the job\'s test result');
    }

    public function test_a_failed_agent_test_is_retried_quietly_like_any_other(): void
    {
        $this->enroll();
        $this->poll();
        $id = $this->queue('synthetic_file');
        Engine::runDueTests($this->companyId);
        $cmdId = $this->poll()['command']['id'];
        $this->agent("/api/agent/commands/$cmdId/result", ['status' => 'failed', 'details' => ['failure_type' => 'access_denied', 'severity' => 'high', 'failed_item' => '/mnt/nas', 'error_message' => 'The agent could not write to the backup location.']])->assertOk();
        $this->assertSame('failed', $this->test($id)->test_status);
        $this->assertSame(0, DB::table('backup_test_failures')->where('test_execution_id', $id)->count(), 'a first failure is not raised yet');
    }

    // ---- scheduling ----

    public function test_a_recovery_point_schedule_covers_platform_backups_but_a_file_test_schedule_does_not(): void
    {
        $acronis = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'acronis'])->json('data.id');
        $this->succeeded($acronis, now()->subHour());
        DB::table('backup_jobs')->where('id', $this->jobId)->update(['status' => 'success']);
        $make = fn (string $type) => $this->admin->post('/api/test-schedules', ['name' => $type, 'test_type' => $type, 'target_type' => 'server', 'server_id' => $this->serverId,
            'frequency' => 'daily', 'run_time' => '03:00', 'timezone' => 'UTC'])->assertStatus(201)->json('data.id');
        $rp = $make('recovery_point');
        $this->assertSame(2, CompanyContext::run($this->companyId, fn () => TestScheduling::queue(TestSchedule::find($rp), null)), 'one per method on the server: the rsync backup and the Acronis backup');
    }

    public function test_every_automated_type_can_be_scheduled_and_the_unbuilt_ones_cannot(): void
    {
        foreach (['random_restore', 'synthetic_file', 'full_recovery', 'file_permissions', 'recovery_point'] as $type) {
            $this->admin->post('/api/test-schedules', ['name' => $type, 'test_type' => $type, 'target_type' => 'all', 'frequency' => 'weekly', 'run_day' => 1, 'run_time' => '02:00', 'timezone' => 'UTC'])->assertStatus(201);
        }
        foreach (['database_integrity', 'incremental_chain', 'm365_mailbox'] as $type) {
            $this->admin->post('/api/test-schedules', ['name' => $type, 'test_type' => $type, 'target_type' => 'all', 'frequency' => 'weekly', 'run_day' => 1, 'run_time' => '02:00', 'timezone' => 'UTC'])->assertStatus(400);
        }
    }

    public function test_the_help_text_names_where_each_test_runs_and_is_honest_about_the_unbuilt_ones(): void
    {
        $types = collect($this->admin->get('/api/test-types')->json('data'))->keyBy('type');
        $this->assertCount(6, $types->where('automated', true));
        foreach ($types as $t) {
            $this->assertNotSame('', $t['runs']);
        }
        $this->assertStringContainsString('agent only', $types['full_recovery']['runs']);
        $this->assertStringContainsString('No agent needed', $types['recovery_point']['runs']);
        $this->assertStringNotContainsString('checksum', strtolower($types['full_recovery']['details']), 'a read with nothing to compare against must not claim a checksum');
        $this->assertStringContainsString('does not restore', $types['full_recovery']['details']);
    }
}
