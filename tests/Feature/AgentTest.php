<?php

namespace Tests\Feature;

use App\Services\Agents\AgentService;
use App\Services\Engine\Engine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

class AgentTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $other;

    private int $companyId;

    private int $serverId;

    private int $jobId;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('agentco');
        $otherCompany = $this->makeCompany('rival');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $techId = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($otherCompany, 'rivaladmin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->other = $this->login('rivaladmin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'remote-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->admin->put("/api/servers/{$this->serverId}/assignments", ['user_ids' => [$techId]]);
        $this->jobId = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'rsync', 'schedule' => '0 2 * * *'])->json('data.id');
        $this->secret = $this->admin->post("/api/servers/{$this->serverId}/agent")->json('agent_config.token');
    }

    /** Call the agent endpoints the way the Python agent does: bearer secret, no user session. */
    private function agent(string $method, string $url, array $data = [], ?string $secret = null)
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer '.($secret ?? $this->secret)])->json($method, $url, $data);
    }

    private function poll(array $actions = ['backup' => ['type' => 'command'], 'restore-test' => ['type' => 'restore_test']])
    {
        return $this->agent('POST', '/api/agent/poll', ['version' => '1.0.0', 'os' => 'Linux 6.1', 'actions' => $actions]);
    }

    // --- enrolment ---

    public function test_enrolling_shows_the_secret_once_and_stores_only_a_hash(): void
    {
        $this->assertStringStartsWith('bms_', $this->secret);
        $this->assertSame(hash('sha256', $this->secret), DB::table('agents')->where('server_id', $this->serverId)->value('token_hash'));
        $status = $this->admin->get("/api/servers/{$this->serverId}/agent");
        $this->assertTrue($status->json('data.enrolled'));
        $this->assertStringNotContainsString($this->secret, $status->getContent());
        $this->assertStringNotContainsString('token_hash', $status->getContent());
        $this->assertSame(409, $this->admin->post("/api/servers/{$this->serverId}/agent")->status(), 'one agent per server');
    }

    public function test_only_admins_can_enroll_or_remove_and_other_companies_cannot_see_it(): void
    {
        $this->assertSame(403, $this->tech->post("/api/servers/{$this->serverId}/agent")->status());
        $this->assertSame(403, $this->tech->del("/api/servers/{$this->serverId}/agent")->status());
        $this->assertSame(200, $this->tech->get("/api/servers/{$this->serverId}/agent")->status(), 'an assigned technician may see the status');
        $this->assertSame(404, $this->other->get("/api/servers/{$this->serverId}/agent")->status());
        $this->assertSame(404, $this->other->post("/api/servers/{$this->serverId}/agent")->status());
    }

    public function test_a_wrong_or_missing_agent_secret_is_rejected(): void
    {
        $this->assertSame(401, $this->poll()->status() === 200 ? $this->agent('POST', '/api/agent/poll', [], 'bms_wrong')->status() : 0);
        $this->assertSame(401, $this->anonymous()->postJson('/api/agent/poll', [])->status());
        $this->assertSame(401, $this->agent('POST', '/api/agent/poll', [], $this->admin->token)->status(), 'a user token is not an agent secret');
    }

    public function test_a_removed_agent_can_no_longer_connect_and_its_pending_commands_are_cancelled(): void
    {
        $this->poll();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/run");
        $this->assertSame(204, $this->admin->del("/api/servers/{$this->serverId}/agent")->status());
        $this->assertSame(401, $this->poll()->status());
        $this->assertSame(0, DB::table('agent_commands')->whereIn('status', ['pending', 'running'])->count());
    }

    public function test_a_removed_agent_can_be_enrolled_again_with_a_new_secret_and_the_old_one_stays_dead(): void
    {
        $old = $this->secret;
        $this->assertSame(200, $this->poll()->status());
        $this->assertSame(204, $this->admin->del("/api/servers/{$this->serverId}/agent")->status());

        $r = $this->admin->post("/api/servers/{$this->serverId}/agent");
        $this->assertSame(201, $r->status(), 'enrolling works again once the agent is gone');
        $new = $r->json('agent_config.token');
        $this->assertNotSame($old, $new);
        $this->assertSame(401, $this->agent('POST', '/api/agent/poll', ['version' => '1.0.0', 'actions' => []], $old)->status(), 'the old secret no longer works');
        $this->assertSame(200, $this->agent('POST', '/api/agent/poll', ['version' => '1.0.0', 'os' => 'Linux 6.1', 'actions' => ['backup' => ['type' => 'command']]], $new)->status());
        $this->assertSame(1, DB::table('agents')->where('server_id', $this->serverId)->count());
    }

    // --- re-enrolling keeps what was set up in BMS ---

    public function test_a_new_secret_keeps_the_locations_and_folders_set_in_bms_and_kills_the_old_secret(): void
    {
        $old = $this->secret;
        $this->poll();
        DB::table('agents')->where('server_id', $this->serverId)->update(['managed_locations' => json_encode(['vault' => '\\\\vault.example\\IT\\TestBkp']), 'managed_source_roots' => json_encode(['/bkp/bkp/Sync'])]);
        $agentId = DB::table('agents')->where('server_id', $this->serverId)->value('id');

        $r = $this->admin->post("/api/servers/{$this->serverId}/agent/rotate");
        $this->assertSame(200, $r->status());
        $new = $r->json('agent_config.token');
        $this->assertStringStartsWith('bms_', $new);
        $this->assertNotSame($old, $new);
        $this->assertStringContainsString($new, $r->json('install.linux'), 'the install command carries the new secret');

        $row = DB::table('agents')->where('server_id', $this->serverId)->first();
        $this->assertSame($agentId, (int) $row->id, 'the same agent, not a new one');
        $this->assertSame(['vault' => '\\\\vault.example\\IT\\TestBkp'], json_decode($row->managed_locations, true));
        $this->assertSame(['/bkp/bkp/Sync'], json_decode($row->managed_source_roots, true));
        $this->assertSame(hash('sha256', $new), $row->token_hash);

        $this->assertSame(401, $this->agent('POST', '/api/agent/poll', ['version' => '1.0.0', 'actions' => []], $old)->status());
        $poll = $this->agent('POST', '/api/agent/poll', ['version' => '1.10.1', 'os' => 'Linux', 'remote_actions' => true, 'actions' => ['backup' => ['type' => 'command']]], $new);
        $this->assertSame(200, $poll->status());
        $this->assertSame(['vault' => '\\\\vault.example\\IT\\TestBkp'], $poll->json('managed_locations'), 'the agent is given them again as soon as it checks in');
    }

    public function test_replacing_the_secret_cancels_what_the_agent_was_doing(): void
    {
        $this->poll();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/run");
        $this->assertSame(1, DB::table('agent_commands')->where('status', 'pending')->count());
        $this->admin->post("/api/servers/{$this->serverId}/agent/rotate")->assertOk();
        $this->assertSame(0, DB::table('agent_commands')->whereIn('status', ['pending', 'running'])->count());
        $this->assertSame('cancelled', DB::table('agent_commands')->value('status'));
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'agent_secret_replaced')->count());
    }

    public function test_only_admins_replace_a_secret_and_there_must_be_an_agent(): void
    {
        $this->assertSame(403, $this->tech->post("/api/servers/{$this->serverId}/agent/rotate")->status());
        $this->assertSame(404, $this->other->post("/api/servers/{$this->serverId}/agent/rotate")->status());
        $this->admin->del("/api/servers/{$this->serverId}/agent");
        $this->assertSame(404, $this->admin->post("/api/servers/{$this->serverId}/agent/rotate")->status(), 'nothing to replace once it is removed');
    }

    // --- a backup the agent never picks up ---

    private function queueAndGoQuiet(int $minutesSinceSeen = 20, int $minutesSinceQueued = 20): int
    {
        $this->poll();
        $id = $this->admin->post("/api/backup-jobs/{$this->jobId}/run")->json('data.id');
        DB::table('agents')->where('server_id', $this->serverId)->update(['last_seen_at' => now()->subMinutes($minutesSinceSeen)]);
        DB::table('agent_commands')->where('id', $id)->update(['created_at' => now()->subMinutes($minutesSinceQueued), 'deadline_at' => now()->addHours(8)]);

        return $id;
    }

    public function test_a_queued_backup_the_offline_agent_never_picks_up_is_raised_as_a_problem(): void
    {
        $this->queueAndGoQuiet();
        $this->assertSame(1, AgentService::flagUnreachable($this->companyId));
        $f = DB::table('backup_failures')->where('server_id', $this->serverId)->first();
        $this->assertSame(['failed', 'agent'], [$f->kind, $f->source]);
        $this->assertStringContainsString('last checked in at', $f->message);
        $this->assertStringContainsString('has not started', $f->message);
        $this->assertSame(1, DB::table('backup_failures')->where('server_id', $this->serverId)->count());
        $this->assertSame(1, AgentService::flagUnreachable($this->companyId), 'still true on the next minute, and still one problem');
        $this->assertSame(1, DB::table('backup_failures')->where('server_id', $this->serverId)->count());
    }

    public function test_nothing_is_raised_in_the_grace_period_or_while_the_agent_is_online(): void
    {
        $this->queueAndGoQuiet(20, 3);
        $this->assertSame(0, AgentService::flagUnreachable($this->companyId), 'queued only 3 minutes ago');

        DB::table('agent_commands')->update(['created_at' => now()->subMinutes(40), 'deadline_at' => now()->addHours(8)]);
        DB::table('agents')->where('server_id', $this->serverId)->update(['last_seen_at' => now()]);
        $this->assertSame(0, AgentService::flagUnreachable($this->companyId), 'the agent is online, so it is busy or about to take it');
        $this->assertSame(0, DB::table('backup_failures')->count());
    }

    public function test_the_problem_clears_when_the_backup_later_runs_and_succeeds(): void
    {
        $id = $this->queueAndGoQuiet();
        AgentService::flagUnreachable($this->companyId);
        $this->assertSame(1, (int) DB::table('backup_failures')->where('server_id', $this->serverId)->value('active'));

        $cmd = $this->poll()->json('command');
        $this->assertSame($id, $cmd['id']);
        $this->agent('POST', "/api/agent/commands/{$cmd['id']}/result", ['status' => 'success', 'exit_code' => 0, 'output' => 'done']);
        $this->assertSame(0, (int) DB::table('backup_failures')->where('server_id', $this->serverId)->value('active'), 'recovered, and left for someone to review');
    }

    public function test_admins_are_told_once_and_the_engine_does_it_each_minute(): void
    {
        $this->queueAndGoQuiet();
        Engine::tick();
        Engine::tick();
        $host = DB::table('servers')->where('id', $this->serverId)->value('hostname');
        $n = DB::table('notifications')->where('event_type', 'backup_failed')->where('message', 'like', "$host:%has not started%")->count();
        $this->assertSame(1, $n, 'told once, not every minute');
        $this->assertSame(1, DB::table('backup_failures')->where('server_id', $this->serverId)->count());
    }

    // --- manual backup ---

    public function test_a_manual_backup_is_queued_run_by_the_agent_and_recorded(): void
    {
        $this->poll();
        $run = $this->admin->post("/api/backup-jobs/{$this->jobId}/run");
        $this->assertSame(202, $run->status());
        $this->assertSame('backup', $run->json('data.action'));
        $this->assertSame('pending', $run->json('data.status'));
        $queued = $this->admin->get("/api/backup-jobs/{$this->jobId}")->json('data');
        $this->assertSame(['scheduled', 'Waiting for the agent to start it.'], [$queued['status'], $queued['status_message']], 'queued is waiting, not running');

        $cmd = $this->poll()->json('command');
        $this->assertSame('running', $this->admin->get("/api/backup-jobs/{$this->jobId}")->json('data.status'), 'running once the agent has picked it up');
        $this->assertSame('backup', $cmd['type']);
        $this->assertNull($this->poll()->json('command'), 'a command is handed out once');

        $this->agent('POST', "/api/agent/commands/{$cmd['id']}/result", ['status' => 'success', 'exit_code' => 0, 'output' => 'done'])->assertOk();
        $job = $this->admin->get("/api/backup-jobs/{$this->jobId}")->json('data');
        $this->assertSame('success', $job['status']);
        $this->assertSame('success', $this->admin->get("/api/servers/{$this->serverId}")->json('data.last_backup_status'));
        $this->assertSame(409, $this->agent('POST', "/api/agent/commands/{$cmd['id']}/result", ['status' => 'success'])->status(), 'a result is accepted once');
    }

    public function test_a_failed_backup_marks_the_job_failed_and_alerts_admins(): void
    {
        $this->poll();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/run");
        $cmd = $this->poll()->json('command');
        $this->agent('POST', "/api/agent/commands/{$cmd['id']}/result", ['status' => 'failed', 'exit_code' => 23, 'output' => 'rsync: disk full']);
        $job = $this->admin->get("/api/backup-jobs/{$this->jobId}")->json('data');
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('disk full', $job['status_message']);
        $this->assertTrue(collect($this->admin->get('/api/notifications')->json('data'))->contains(fn ($n) => $n['event_type'] === 'backup_failed'));
    }

    public function test_a_backup_needs_an_enrolled_online_agent_with_a_backup_action(): void
    {
        $bare = $this->admin->post('/api/servers', ['hostname' => 'bare-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $bareJob = $this->admin->post('/api/backup-jobs', ['server_id' => $bare, 'backup_method' => 'rsync'])->json('data.id');
        $this->assertSame(409, $this->admin->post("/api/backup-jobs/$bareJob/run")->status(), 'no agent');
        $this->assertSame(409, $this->admin->post("/api/backup-jobs/{$this->jobId}/run")->status(), 'agent has never checked in');
        $this->poll(['only-test' => ['type' => 'restore_test']]);
        $this->assertSame(400, $this->admin->post("/api/backup-jobs/{$this->jobId}/run")->status(), 'no backup action advertised');
        $this->poll();
        $this->assertSame(400, $this->admin->post("/api/backup-jobs/{$this->jobId}/run", ['action' => 'nope'])->status(), 'unknown action name');
        $this->assertSame(202, $this->admin->post("/api/backup-jobs/{$this->jobId}/run")->status());
        $this->assertSame(409, $this->admin->post("/api/backup-jobs/{$this->jobId}/run")->status(), 'already queued');
    }

    public function test_only_admins_can_start_a_backup(): void
    {
        $this->poll();
        $this->assertSame(403, $this->tech->post("/api/backup-jobs/{$this->jobId}/run")->status());
        $this->assertSame(404, $this->other->post("/api/backup-jobs/{$this->jobId}/run")->status());
    }

    public function test_bm_s_never_sends_a_command_line_only_a_name(): void
    {
        $this->poll();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/run");
        $cmd = $this->poll()->json('command');
        $this->assertEqualsCanonicalizing(['id', 'type', 'test_type', 'action', 'location', 'subpath', 'source', 'deadline_at'], array_keys($cmd));
        $this->assertSame(400, $this->admin->post("/api/backup-jobs/{$this->jobId}/run", ['action' => 'rm -rf /'])->status());
    }

    // --- restore tests through the agent ---

    public function test_the_engine_hands_a_restore_test_to_the_agent_and_records_its_result(): void
    {
        $this->poll();
        DB::table('backup_jobs')->where('id', $this->jobId)->update(['status' => 'success']);
        $this->assertSame(1, Engine::scheduleDueTests(), 'agent servers are tested whatever their backup method');
        Engine::runDueTests($this->companyId);
        $test = DB::table('backup_test_executions')->first();
        $this->assertSame('running', $test->test_status, 'waiting on the agent, not skipped');

        $cmd = $this->poll()->json('command');
        $this->assertSame('restore_test', $cmd['type']);
        $this->agent('POST', "/api/agent/commands/{$cmd['id']}/result", [
            'status' => 'success', 'details' => ['hash_algorithm' => 'sha256', 'hash_original' => 'abc', 'hash_restored' => 'abc', 'failed_item' => '/data/x'],
        ])->assertOk();
        $done = DB::table('backup_test_executions')->first();
        $this->assertSame('success', $done->test_status);
        $this->assertSame(1, (int) $done->hash_match);
        $this->assertSame('passed', DB::table('servers')->where('id', $this->serverId)->value('last_test_status'));
    }

    public function test_an_agent_reported_failure_is_retried_then_recorded_like_any_other(): void
    {
        $this->poll();
        $testId = $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore'])->json('data.id');
        Engine::runDueTests($this->companyId);
        $cmd = $this->poll()->json('command');
        $this->agent('POST', "/api/agent/commands/{$cmd['id']}/result", ['status' => 'failed', 'details' => ['failure_type' => 'hash_mismatch', 'severity' => 'critical', 'hash_original' => 'a', 'hash_restored' => 'b', 'error_message' => 'mismatch']]);
        $this->assertSame('failed', DB::table('backup_test_executions')->where('id', $testId)->value('test_status'));
        $this->assertSame(0, DB::table('backup_test_failures')->count(), 'the first failure is retried quietly');
        $this->assertSame(1, DB::table('backup_test_executions')->where('retry_of', $testId)->count());
    }

    public function test_a_test_is_skipped_with_a_reason_when_the_agent_has_no_restore_action(): void
    {
        $this->poll(['backup' => ['type' => 'command']]);
        $testId = $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore'])->json('data.id');
        Engine::runDueTests($this->companyId);
        $t = DB::table('backup_test_executions')->where('id', $testId)->first();
        $this->assertSame('skipped', $t->test_status);
        $this->assertStringContainsString('no restore_test action', $t->error_message);
    }

    // --- scheduling, expiry, isolation ---

    public function test_due_backups_are_queued_once_for_online_agents_only(): void
    {
        DB::table('backup_jobs')->where('id', $this->jobId)->update(['created_at' => now()->subDays(3), 'schedule' => '* * * * *']);
        $this->assertSame(0, AgentService::scheduleDueBackups($this->companyId), 'offline agent: nothing queued');
        $this->poll();
        $this->assertSame(1, AgentService::scheduleDueBackups($this->companyId));
        $this->assertSame(0, AgentService::scheduleDueBackups($this->companyId), 'not queued twice');
        $this->assertNull(DB::table('agent_commands')->value('requested_by'), 'marked as scheduled, not user-requested');
    }

    public function test_unclaimed_and_unreported_commands_expire_with_an_honest_message(): void
    {
        $this->poll();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/run");
        DB::table('agent_commands')->update(['deadline_at' => now()->subMinute()]);
        $this->assertSame(1, AgentService::expireStale($this->companyId));
        $job = $this->admin->get("/api/backup-jobs/{$this->jobId}")->json('data');
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('may be offline', $job['status_message']);
        $this->assertSame('expired', DB::table('agent_commands')->value('status'));
    }

    public function test_an_agent_cannot_report_on_another_agents_command(): void
    {
        $rivalServer = $this->other->post('/api/servers', ['hostname' => 'rival-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $rivalSecret = $this->other->post("/api/servers/$rivalServer/agent")->json('agent_config.token');
        $this->poll();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/run");
        $cmd = $this->poll()->json('command');
        $this->assertSame(404, $this->agent('POST', "/api/agent/commands/{$cmd['id']}/result", ['status' => 'success'], $rivalSecret)->status());
        $this->assertSame('running', DB::table('agent_commands')->where('id', $cmd['id'])->value('status'));
    }

    public function test_agent_commands_list_is_scoped_to_the_company_and_technician(): void
    {
        $this->poll();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/run");
        $this->assertCount(1, $this->admin->get('/api/agent-commands')->json('data'));
        $this->assertCount(1, $this->tech->get('/api/agent-commands')->json('data'));
        $this->assertCount(0, $this->other->get('/api/agent-commands')->json('data'));
    }

    public function test_a_test_type_the_engine_does_not_run_is_skipped_on_an_agent_server_and_never_handed_to_the_agent(): void
    {
        $this->poll();
        foreach (['database_integrity', 'incremental_chain', 'm365_mailbox'] as $type) {
            $this->assertSame(202, $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => $type])->status());
        }
        Engine::runDueTests($this->companyId);
        $this->assertSame(0, DB::table('agent_commands')->where('type', 'restore_test')->count(), 'no restore may be run for a test that is not automated');
        $rows = DB::table('backup_test_executions')->whereIn('test_type', ['database_integrity', 'incremental_chain', 'm365_mailbox'])->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame('skipped', $r->test_status, $r->test_type);
            $this->assertStringContainsString('not automated yet', (string) $r->error_message.(string) $r->notes.(string) $r->details);
        }
    }
}
