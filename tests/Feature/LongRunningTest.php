<?php

namespace Tests\Feature;

use App\Services\Agents\AgentService;
use App\Services\Engine\Engine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Very long backups: how long BMS waits, what it does when an agent goes quiet, and that waiting tests are not failed for it. */
class LongRunningTest extends TestCase
{
    private ApiClient $admin;

    private int $companyId;

    private int $serverId;

    private int $jobId;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('longco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'big-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->jobId = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'rsync'])->json('data.id');
        $this->secret = $this->admin->post("/api/servers/{$this->serverId}/agent")->json('agent_config.token');
    }

    private function agentCall(string $url, array $body)
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer {$this->secret}"])->postJson($url, $body);
    }

    private function poll(bool $busy = false, ?int $backupTimeout = null): array
    {
        $backup = ['type' => 'command'] + ($backupTimeout ? ['timeout_seconds' => $backupTimeout] : []);

        return $this->agentCall('/api/agent/poll', ['version' => '1.8.0', 'actions' => ['backup' => $backup, 'restore-test' => ['type' => 'restore_test']]] + ($busy ? ['busy' => true] : []))->json();
    }

    private function command(int $id): object
    {
        return DB::table('agent_commands')->find($id);
    }

    private function startBackup(?int $timeout = null): int
    {
        $this->poll(false, $timeout);
        $this->admin->post("/api/backup-jobs/{$this->jobId}/run")->assertStatus(202);
        $id = (int) $this->poll(false, $timeout)['command']['id'];

        return $id;
    }

    public function test_a_backup_is_given_the_time_its_own_action_allows_plus_a_margin_not_a_flat_eight_hours(): void
    {
        $id = $this->startBackup(24 * 3600);
        $this->assertEqualsWithDelta(24 * 3600 + 30 * 60, now()->diffInSeconds($this->command($id)->deadline_at, false), 5);
    }

    public function test_the_time_runs_from_when_it_starts_not_from_when_it_was_queued(): void
    {
        $this->poll();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/run")->assertStatus(202);
        $this->travel(3)->hours();
        $id = (int) $this->poll()['command']['id'];
        $this->assertEqualsWithDelta(8 * 3600, now()->diffInSeconds($this->command($id)->deadline_at, false), 5, 'an agent that gives no limit gets 8 hours from the start');
    }

    public function test_a_long_backup_is_not_expired_at_eight_hours_but_is_at_its_own_limit(): void
    {
        $id = $this->startBackup(24 * 3600);
        $this->travel(9)->hours();
        $this->assertSame(0, AgentService::expireStale($this->companyId));
        $this->assertSame('running', $this->command($id)->status);
        $this->assertSame('running', DB::table('backup_jobs')->find($this->jobId)->status);

        $this->travel(16)->hours();
        $this->assertSame(1, AgentService::expireStale($this->companyId));
        $this->assertSame('expired', $this->command($id)->status);
        $this->assertSame('failed', DB::table('backup_jobs')->find($this->jobId)->status);
    }

    public function test_an_agent_that_is_idle_while_bms_thinks_it_is_running_something_is_told_it_lost_it(): void
    {
        $id = $this->startBackup();
        $this->poll(true);
        $this->poll(false);
        $this->assertSame('running', $this->command($id)->status, 'inside the grace period a check-in cannot fail it');

        $this->travel(2)->minutes();
        $this->poll(true);
        $this->assertSame('running', $this->command($id)->status, 'a busy agent is left alone');

        $this->poll(false);
        $c = $this->command($id);
        $this->assertSame('failed', $c->status);
        $this->assertStringContainsString('restarted or lost', $c->output);
        $job = DB::table('backup_jobs')->find($this->jobId);
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('restarted or lost', $job->status_message);
        $this->assertSame(409, $this->agentCall("/api/agent/commands/$id/result", ['status' => 'success'])->status(), 'a late report for a lost command is refused');
    }

    public function test_a_command_that_finished_normally_is_not_treated_as_lost(): void
    {
        $id = $this->startBackup();
        $this->travel(5)->minutes();
        $this->agentCall("/api/agent/commands/$id/result", ['status' => 'success'])->assertOk();
        $this->poll(false);
        $this->assertSame('success', $this->command($id)->status);
        $this->assertSame('success', DB::table('backup_jobs')->find($this->jobId)->status);
    }

    public function test_a_test_waiting_behind_a_long_backup_is_held_not_failed(): void
    {
        $backup = $this->startBackup(24 * 3600);
        $test = $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore'])->json('data.id');
        Engine::runDueTests($this->companyId);
        $cmd = DB::table('agent_commands')->where('type', 'restore_test')->first();
        $this->assertSame('pending', $cmd->status);

        $this->travel(3)->hours();
        $this->poll(true, 24 * 3600);
        $this->assertSame(0, AgentService::expireStale($this->companyId), 'its own two hours are up, but the agent is busy with a backup that is still in time');
        $this->assertSame('pending', DB::table('agent_commands')->find($cmd->id)->status);
        $this->assertSame('running', DB::table('backup_test_executions')->find($test)->test_status, 'the test has not been failed');
        $this->assertSame('running', $this->command($backup)->status);
        $this->assertGreaterThan(now()->addHours(20), CarbonImmutable::parse(DB::table('agent_commands')->find($cmd->id)->deadline_at, 'UTC'), 'held until after the backup is due to end');
    }

    public function test_a_held_test_runs_once_the_backup_finishes(): void
    {
        $backup = $this->startBackup(24 * 3600);
        $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $this->travel(3)->hours();
        $this->poll(true, 24 * 3600);
        AgentService::expireStale($this->companyId);

        $this->agentCall("/api/agent/commands/$backup/result", ['status' => 'success'])->assertOk();
        $next = $this->poll(false, 24 * 3600)['command'];
        $this->assertSame('restore_test', $next['type'], 'the waiting test is handed over as soon as the agent is free');
    }

    public function test_a_waiting_command_is_still_expired_when_nothing_is_ahead_of_it(): void
    {
        $this->poll();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $this->travel(3)->hours();
        $this->assertSame(1, AgentService::expireStale($this->companyId), 'an agent that is simply offline still fails the test');
        $this->assertSame('expired', DB::table('agent_commands')->where('type', 'restore_test')->value('status'));
    }

    public function test_the_advertised_limit_is_stored_and_rejects_nonsense(): void
    {
        $this->poll(false, 7200);
        $this->assertSame(7200, DB::table('agents')->first()->actions ? json_decode(DB::table('agents')->value('actions'), true)['backup']['timeout_seconds'] : null);
        $this->assertSame(400, $this->agentCall('/api/agent/poll', ['actions' => ['backup' => ['type' => 'command', 'timeout_seconds' => 0]]])->status());
        $this->assertSame(400, $this->agentCall('/api/agent/poll', ['actions' => ['backup' => ['type' => 'command', 'timeout_seconds' => 'forever']]])->status());
    }
}
