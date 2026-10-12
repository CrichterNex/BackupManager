<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Server;
use App\Services\TestResults;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

class ApiTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $manager;

    private ApiClient $tech;

    private ApiClient $otherTech;

    private int $companyId;

    private int $techId;

    private int $serverId;

    private int $assignedServer;

    private int $jobId;

    private int $assignedJob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('acme');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'manager', 'company_manager');
        $this->techId = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($this->companyId, 'tech2', 'company_technician');
        $this->admin = $this->login('admin');
        $this->manager = $this->login('manager');
        $this->tech = $this->login('tech');
        $this->otherTech = $this->login('tech2');

        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'files-01', 'os_type' => 'windows', 'backup_methods' => ['windows_manual_copy']])->json('data.id');
        $this->assignedServer = $this->admin->post('/api/servers', ['hostname' => 'web-01', 'os_type' => 'linux', 'backup_methods' => ['linux_manual_copy', 'rsync']])->json('data.id');
        $this->admin->put("/api/servers/{$this->assignedServer}/assignments", ['user_ids' => [$this->techId]]);
        $this->jobId = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'windows_manual_copy', 'schedule' => '0 2 * * *'])->json('data.id');
        $this->assignedJob = $this->admin->post('/api/backup-jobs', ['server_id' => $this->assignedServer, 'backup_method' => 'linux_manual_copy'])->json('data.id');
    }

    private function recordResult(int $testId, array $result): void
    {
        $this->asCompany($this->companyId, fn () => TestResults::record($testId, $result));
    }

    // --- authentication ---

    public function test_wrong_password_and_unknown_user_get_the_same_message(): void
    {
        $wrong = $this->postJson('/api/auth/login', ['username' => 'admin', 'password' => 'nope-nope-nope']);
        $unknown = $this->postJson('/api/auth/login', ['username' => 'ghost', 'password' => 'nope-nope-nope']);
        $this->assertSame(401, $wrong->status());
        $this->assertSame(401, $unknown->status());
        $this->assertSame($wrong->json('error'), $unknown->json('error'));
    }

    public function test_failed_logins_are_audited(): void
    {
        $this->anonymous()->postJson('/api/auth/login', ['username' => 'admin', 'password' => 'nope-nope-nope']);
        $this->anonymous()->postJson('/api/auth/login', ['username' => 'ghost', 'password' => 'nope-nope-nope']);
        // The wrong-password attempt is attributed to the company; the unknown-user attempt has no tenant.
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'login_failed')->where('success', 0)->where('company_id', $this->companyId)->count());
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'login_failed')->whereNull('company_id')->count());
    }

    public function test_cookie_is_httponly_samesite_strict_and_authenticates(): void
    {
        $res = $this->postJson('/api/auth/login', ['username' => 'admin', 'password' => self::PASSWORD]);
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'auth_token');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/companies/acme/dashboard', $res->json('redirect_to'));

        $me = $this->anonymous()->call('GET', '/api/me', [], ['auth_token' => $cookie->getValue()], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame('admin', $me->json('data.username'), $me->getContent());
    }

    public function test_a_token_is_required_and_a_tampered_one_is_rejected(): void
    {
        $this->assertSame(401, $this->anonymous()->getJson('/api/servers')->status());
        $this->assertSame(401, $this->anonymous()->withHeaders(['Authorization' => 'Bearer abc.def.ghi'])->getJson('/api/servers')->status());
    }

    public function test_deactivation_applies_immediately_to_existing_tokens(): void
    {
        $this->makeUser($this->companyId, 'temp', 'company_manager');
        $t = $this->login('temp');
        $this->assertSame(200, $t->get('/api/servers')->status());
        DB::table('users')->where('username', 'temp')->update(['active' => 0]);
        $this->assertSame(401, $t->get('/api/servers')->status());
        $this->assertSame(401, $this->postJson('/api/auth/login', ['username' => 'temp', 'password' => self::PASSWORD])->status());
    }

    public function test_logout_revokes_the_token(): void
    {
        $c = $this->login('manager');
        $this->assertSame(200, $c->get('/api/me')->status());
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => 'Bearer '.$c->token])->postJson('/api/auth/logout');
        $this->assertSame(401, $c->get('/api/me')->status());
    }

    public function test_password_changes_need_the_correct_current_password(): void
    {
        $this->makeUser($this->companyId, 'pw', 'company_manager');
        $c = $this->login('pw');
        $this->assertSame(401, $c->post('/api/me/change-password', ['current_password' => 'wrong-wrong-wrong', 'new_password' => 'another-long-pass-1'])->status());
        $this->assertSame(400, $c->post('/api/me/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'short'])->status());
        $this->assertSame(200, $c->post('/api/me/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'another-long-pass-1'])->status());
        $this->assertSame(200, $this->postJson('/api/auth/login', ['username' => 'pw', 'password' => 'another-long-pass-1'])->status());
    }

    public function test_password_hashes_are_never_returned(): void
    {
        $body = $this->admin->get('/api/users')->getContent();
        $this->assertDoesNotMatchRegularExpression('/password|\$2[aby]\$/', $body);
    }

    // --- roles ---

    public function test_manager_is_read_only(): void
    {
        $this->assertSame(200, $this->manager->get('/api/servers')->status());
        $this->assertSame(200, $this->manager->get('/api/backup-jobs')->status());
        $this->assertSame(403, $this->manager->post('/api/servers', ['hostname' => 'x', 'os_type' => 'linux'])->status());
        $this->assertSame(403, $this->manager->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'windows_manual_copy'])->status());
        $this->assertSame(403, $this->manager->post("/api/backup-jobs/{$this->jobId}/test")->status());
        $this->assertSame(403, $this->manager->get('/api/users')->status());
        $this->assertSame(403, $this->manager->get('/api/audit')->status());
    }

    public function test_technician_cannot_configure_anything(): void
    {
        $this->assertSame(403, $this->tech->post('/api/servers', ['hostname' => 'x', 'os_type' => 'linux'])->status());
        $this->assertSame(403, $this->tech->post('/api/backup-jobs', ['server_id' => $this->assignedServer, 'backup_method' => 'rsync'])->status());
        $this->assertSame(403, $this->tech->del("/api/servers/{$this->assignedServer}")->status());
        $this->assertSame(403, $this->tech->get('/api/users')->status());
    }

    public function test_technician_sees_only_assigned_servers_and_their_jobs(): void
    {
        $this->assertSame(['web-01'], collect($this->tech->get('/api/servers')->json('data'))->pluck('hostname')->all());
        $this->assertSame([], $this->otherTech->get('/api/servers')->json('data'));
        $this->assertSame(404, $this->tech->get("/api/servers/{$this->serverId}")->status());
        $this->assertSame([$this->assignedJob], collect($this->tech->get('/api/backup-jobs')->json('data'))->pluck('id')->all());
        $this->assertSame(404, $this->tech->get("/api/backup-jobs/{$this->jobId}")->status());
        $this->assertSame(404, $this->tech->post("/api/backup-jobs/{$this->jobId}/test")->status());
        $this->assertSame(202, $this->tech->post("/api/backup-jobs/{$this->assignedJob}/test")->status());
    }

    public function test_only_admins_manage_users_and_cannot_demote_themselves(): void
    {
        $me = $this->admin->get('/api/me')->json('data.id');
        $this->assertSame(400, $this->admin->patch("/api/users/$me", ['role' => 'company_manager'])->status());
        $this->assertSame(400, $this->admin->patch("/api/users/$me", ['active' => false])->status());
        $new = ['username' => 'newbie', 'email' => 'n@x.io', 'role' => 'company_technician', 'password' => 'a-long-password-1'];
        $this->assertSame(201, $this->admin->post('/api/users', $new)->status());
        $this->assertSame(409, $this->admin->post('/api/users', ['username' => 'NEWBIE'] + $new)->status());
        $this->assertSame(400, $this->admin->post('/api/users', ['username' => 'weak', 'email' => 'w@x.io', 'role' => 'company_manager', 'password' => 'short'])->status());
    }

    public function test_assignments_only_accept_technicians_from_the_same_company(): void
    {
        $mgr = $this->manager->get('/api/me')->json('data.id');
        $this->assertSame(400, $this->admin->put("/api/servers/{$this->serverId}/assignments", ['user_ids' => [$mgr]])->status());
        $this->assertSame(400, $this->admin->put("/api/servers/{$this->serverId}/assignments", ['user_ids' => [999999]])->status());
    }

    // --- servers and jobs ---

    public function test_server_input_is_validated(): void
    {
        $this->assertSame(400, $this->admin->post('/api/servers', ['hostname' => 'bad host!', 'os_type' => 'linux'])->status());
        $this->assertSame(400, $this->admin->post('/api/servers', ['hostname' => 'ok', 'os_type' => 'solaris'])->status());
        $this->assertSame(400, $this->admin->post('/api/servers', ['hostname' => 'ok2', 'os_type' => 'linux', 'ip_address' => 'not-an-ip'])->status());
        $this->assertSame(409, $this->admin->post('/api/servers', ['hostname' => 'FILES-01', 'os_type' => 'linux'])->status());
    }

    public function test_jobs_need_a_configured_method_and_valid_cron(): void
    {
        $this->assertSame(400, $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'veeam'])->status());
        $this->assertSame(400, $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'windows_manual_copy', 'schedule' => 'every day'])->status());
        $this->assertSame(400, $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'windows_manual_copy', 'schedule' => '0 2 * *'])->status());
    }

    public function test_job_completion_updates_the_server_and_notifies_admins_on_failure(): void
    {
        $this->admin->patch("/api/backup-jobs/{$this->jobId}", ['status' => 'running']);
        $this->admin->patch("/api/backup-jobs/{$this->jobId}", ['status' => 'failed', 'status_message' => 'disk full']);
        $server = $this->admin->get("/api/servers/{$this->serverId}")->json('data');
        $this->assertSame('failed', $server['last_backup_status']);
        $this->assertSame('failing', $server['status']);
        $n = $this->admin->get('/api/notifications?unread=true')->json();
        $this->assertTrue(collect($n['data'])->contains(fn ($x) => $x['event_type'] === 'backup_failed' && $x['message'] === 'disk full'));
        $this->assertCount(0, $this->manager->get('/api/notifications')->json('data'));
    }

    public function test_subscription_server_limit_is_enforced(): void
    {
        $small = $this->makeCompany('small', 1);
        $this->makeUser($small, 'smalladmin', 'company_admin');
        $c = $this->login('smalladmin');
        $this->assertSame(201, $c->post('/api/servers', ['hostname' => 'one', 'os_type' => 'linux'])->status());
        $this->assertSame(409, $c->post('/api/servers', ['hostname' => 'two', 'os_type' => 'linux'])->status());
    }

    public function test_servers_are_soft_deleted_keeping_history_and_freeing_the_hostname(): void
    {
        $id = $this->admin->post('/api/servers', ['hostname' => 'temp-box', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->admin->post('/api/backup-jobs', ['server_id' => $id, 'backup_method' => 'rsync']);
        $this->assertSame(204, $this->admin->del("/api/servers/$id")->status());
        $this->assertSame(404, $this->admin->get("/api/servers/$id")->status());
        $this->assertSame(0, (int) DB::table('servers')->where('id', $id)->value('active'));
        $this->assertSame(1, DB::table('backup_jobs')->where('server_id', $id)->whereNotNull('deleted_at')->count());
        $this->assertSame(201, $this->admin->post('/api/servers', ['hostname' => 'temp-box', 'os_type' => 'linux'])->status());
    }

    public function test_lists_paginate_filter_and_sort_safely(): void
    {
        $res = $this->admin->get('/api/servers?limit=1&page=2&sort_by=hostname&sort_order=asc&os=linux');
        $this->assertSame(200, $res->status());
        $this->assertSame(1, $res->json('pagination.limit'));
        $this->assertSame(200, $this->admin->get('/api/servers?sort_by=id;DROP TABLE servers')->status());
        $this->assertSame([], $this->admin->get('/api/servers?search=%25')->json('data'));
        $this->assertSame(400, $this->admin->get('/api/servers?limit=5000')->status());
    }

    // --- test failure workflow ---

    private function makeFailure(): array
    {
        $testId = $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore'])->json('data.id');
        $this->recordResult($testId, ['success' => false, 'failure_type' => 'hash_mismatch', 'severity' => 'critical', 'hash_original' => 'aaa', 'hash_restored' => 'bbb', 'failed_item' => '/data/report.xlsx']);

        return [$testId, $this->admin->get('/api/test-failures?resolved=false')->json('data.0.id')];
    }

    public function test_duplicate_open_tests_of_one_type_are_refused(): void
    {
        $this->assertSame(202, $this->admin->post("/api/backup-jobs/{$this->assignedJob}/test", ['test_type' => 'file_permissions'])->status());
        $this->assertSame(409, $this->admin->post("/api/backup-jobs/{$this->assignedJob}/test", ['test_type' => 'file_permissions'])->status());
    }

    public function test_a_failure_flags_the_server_and_job_and_alerts_admins(): void
    {
        [$testId] = $this->makeFailure();
        $t = $this->admin->get("/api/tests/$testId")->json('data');
        $this->assertSame('failed', $t['test_status']);
        $this->assertFalse($t['hash_match']);
        $this->assertCount(1, $t['failures']);
        $this->assertSame('failed', $this->admin->get("/api/servers/{$this->serverId}")->json('data.last_test_status'));
        $this->assertSame('failed', $this->admin->get("/api/backup-jobs/{$this->jobId}")->json('data.test_status'));
        $this->assertTrue(collect($this->admin->get('/api/notifications')->json('data'))->contains(fn ($n) => $n['event_type'] === 'test_failed'));
    }

    public function test_a_result_cannot_be_recorded_twice(): void
    {
        [$testId] = $this->makeFailure();
        $this->expectExceptionMessageMatches('/not pending/');
        $this->recordResult($testId, ['success' => true]);
    }

    public function test_resolution_needs_notes_and_a_valid_type_and_managers_cannot_resolve(): void
    {
        [, $failureId] = $this->makeFailure();
        $this->assertSame(400, $this->admin->post("/api/test-failures/$failureId/resolve", ['resolution_type' => 'known_issue', 'backup_usable' => true, 'notes' => '  '])->status());
        $this->assertSame(400, $this->admin->post("/api/test-failures/$failureId/resolve", ['resolution_type' => 'made_up', 'backup_usable' => true, 'notes' => 'x'])->status());
        $this->assertSame(403, $this->manager->post("/api/test-failures/$failureId/resolve", ['resolution_type' => 'known_issue', 'backup_usable' => true, 'notes' => 'x'])->status());
    }

    public function test_a_failure_resolves_once_is_attributed_and_logged(): void
    {
        [, $failureId] = $this->makeFailure();
        $ok = $this->admin->post("/api/test-failures/$failureId/resolve", ['resolution_type' => 'retest_passed', 'backup_usable' => true, 'notes' => 'Retest matched']);
        $this->assertSame(200, $ok->status());
        $this->assertTrue($ok->json('data.resolved'));
        $this->assertNotEmpty($ok->json('data.resolved_by'));
        $this->assertSame(409, $this->admin->post("/api/test-failures/$failureId/resolve", ['resolution_type' => 'known_issue', 'backup_usable' => true, 'notes' => 'again'])->status());
        $this->assertCount(1, $this->admin->get('/api/audit?action=failure_resolved')->json('data'));
    }

    public function test_technicians_cannot_resolve_failures_on_unassigned_servers(): void
    {
        [, $failureId] = $this->makeFailure();
        $this->assertSame(404, $this->tech->post("/api/test-failures/$failureId/resolve", ['resolution_type' => 'known_issue', 'backup_usable' => true, 'notes' => 'x'])->status());
    }

    // --- dashboard and audit ---

    public function test_health_score_never_defaults_empty_data_to_100(): void
    {
        $this->assertNull(DashboardController::healthScore(null, null));
        $this->assertSame(100, DashboardController::healthScore(1.0, null));
        $this->assertSame(50, DashboardController::healthScore(null, 0.5));
        $this->assertSame(75, DashboardController::healthScore(1.0, 0.5));
    }

    public function test_dashboard_reports_company_metrics_scoped_for_technicians(): void
    {
        $this->makeFailure();
        $d = $this->admin->get('/api/dashboard')->json('data');
        $this->assertSame('acme', $d['company']['slug']);
        $this->assertSame(2, $d['servers']['total']);
        $this->assertGreaterThanOrEqual(1, $d['unresolved_failures']);
        $t = $this->tech->get('/api/dashboard')->json('data');
        $this->assertSame(1, $t['servers']['total']);
        $this->assertSame(0, $t['unresolved_failures']);
    }

    public function test_audit_records_who_did_what_and_unknown_routes_are_not_probeable(): void
    {
        $rows = $this->admin->get('/api/audit?action=server_created')->json('data');
        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertSame('admin', $rows[0]['username']);
        $this->assertSame(401, $this->anonymous()->getJson('/api/nope')->status());
        $this->assertSame(404, $this->admin->get('/api/nope')->status());
    }

    public function test_server_model_never_leaks_internal_columns(): void
    {
        $server = $this->admin->get("/api/servers/{$this->serverId}")->json('data');
        foreach (['company_id', 'active', 'active_hostname'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $server);
        }
        $this->assertSame(Server::class, Server::class);
    }
}
