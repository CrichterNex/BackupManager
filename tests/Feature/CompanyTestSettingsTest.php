<?php

namespace Tests\Feature;

use App\Services\CompanyTests;
use App\Services\Engine\Engine;
use App\Services\TestScheduling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Which tests a company uses: the platform admin offers a subset, and the company's own admin can turn one off. */
class CompanyTestSettingsTest extends TestCase
{
    private ApiClient $platform;

    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $manager;

    private ApiClient $otherAdmin;

    private int $homeId;

    private int $companyId;

    private int $otherId;

    private int $serverId;

    private int $jobId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeId = $this->makeCompany('platformhome');
        $this->companyId = $this->makeCompany('settingsco');
        $this->otherId = $this->makeCompany('othersettings');
        $this->makeUser($this->homeId, 'root', 'company_admin');
        DB::table('users')->where('username', 'root')->update(['is_platform_admin' => 1]);
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $techId = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $this->makeUser($this->otherId, 'otheradmin', 'company_admin');
        $this->platform = $this->login('root');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->manager = $this->login('mgr');
        $this->otherAdmin = $this->login('otheradmin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'srv-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->jobId = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'rsync'])->json('data.id');
        $this->admin->put("/api/servers/{$this->serverId}/assignments", ['user_ids' => [$techId]]);
    }

    private function settings(ApiClient $who): array
    {
        return collect($who->get('/api/test-settings')->assertOk()->json('data'))->keyBy('type')->all();
    }

    private function off(string $type, ?ApiClient $who = null)
    {
        return ($who ?? $this->admin)->put('/api/test-settings', ['tests' => [$type => false]]);
    }

    private function unavailable(string $type, ?int $company = null)
    {
        return $this->platform->put('/api/platform/companies/'.($company ?? $this->companyId).'/test-settings', ['tests' => [$type => false]]);
    }

    // ---------------------------------------------------------------- defaults

    public function test_a_company_with_no_settings_has_every_test(): void
    {
        $s = $this->settings($this->admin);
        $this->assertCount(8, $s);
        foreach ($s as $row) {
            $this->assertTrue($row['available'] && $row['enabled'] && $row['active'], $row['type']);
            $this->assertNotSame('', $row['title']);
        }
        $this->assertTrue($s['random_restore']['automated']);
        $this->assertFalse($s['m365_mailbox']['automated']);
        $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore'])->assertStatus(202);
    }

    public function test_everyone_in_the_company_can_see_the_settings_but_only_an_admin_can_change_them(): void
    {
        $this->settings($this->tech);
        $this->settings($this->manager);
        $this->off('m365_mailbox', $this->tech)->assertStatus(403);
        $this->off('m365_mailbox', $this->manager)->assertStatus(403);
        $this->assertTrue($this->settings($this->admin)['m365_mailbox']['active']);
    }

    // ---------------------------------------------------------------- the company turns a test off

    public function test_a_company_admin_turns_a_test_off_and_it_can_no_longer_be_created_anywhere(): void
    {
        $r = $this->off('m365_mailbox')->assertOk();
        $row = collect($r->json('data'))->firstWhere('type', 'm365_mailbox');
        $this->assertSame([true, false, false], [$row['available'], $row['enabled'], $row['active']]);

        $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'm365_mailbox'])->assertStatus(409)->assertJsonPath('error_code', 'TEST_011');
        $this->admin->post("/api/backup-jobs/{$this->jobId}/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(409)->assertJsonPath('error_code', 'TEST_011');
        $r = $this->admin->post('/api/test-schedules', ['name' => 'x', 'test_type' => 'm365_mailbox', 'manual' => true, 'target_type' => 'all', 'frequency' => 'daily', 'run_time' => '08:00', 'timezone' => 'UTC']);
        $r->assertStatus(409);
        $this->assertStringContainsString('turned off for your company', $r->json('error'));
        $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore'])->assertStatus(202);
        $this->admin->post("/api/backup-jobs/{$this->jobId}/manual-test", ['test_type' => 'recovery_point'])->assertStatus(201);
    }

    public function test_turning_it_back_on_restores_it(): void
    {
        $this->off('random_restore')->assertOk();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore'])->assertStatus(409);
        $this->admin->put('/api/test-settings', ['tests' => ['random_restore' => true]])->assertOk();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore'])->assertStatus(202);
    }

    public function test_the_change_is_recorded_in_the_audit_log_only_when_something_changed(): void
    {
        $this->off('m365_mailbox')->assertOk();
        $this->off('m365_mailbox')->assertOk();
        $entries = collect($this->admin->get('/api/audit?limit=100')->json('data'))->where('action', 'test_settings_changed');
        $this->assertCount(1, $entries);
        $this->assertSame(['m365_mailbox' => false], $entries->first()['details']['enabled']);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->admin->put('/api/test-settings', ['tests' => ['nonsense' => false]])->assertStatus(400);
        $this->admin->put('/api/test-settings', ['tests' => ['random_restore' => 'maybe']])->assertStatus(400);
        $this->admin->put('/api/test-settings', ['tests' => []])->assertStatus(400);
        $this->admin->put('/api/test-settings', [])->assertStatus(400);
    }

    // ---------------------------------------------------------------- the platform admin offers a subset

    public function test_a_platform_admin_chooses_which_tests_a_company_is_offered(): void
    {
        $r = $this->unavailable('database_integrity')->assertOk();
        $row = collect($r->json('data'))->firstWhere('type', 'database_integrity');
        $this->assertSame([false, true, false], [$row['available'], $row['enabled'], $row['active']]);
        $this->assertFalse($this->settings($this->admin)['database_integrity']['available']);
        $r = $this->admin->post("/api/backup-jobs/{$this->jobId}/manual-test", ['test_type' => 'database_integrity'])->assertStatus(409);
        $this->assertStringContainsString('not available to your company', $r->json('error'));
        $this->assertStringContainsString('platform administrator', $r->json('error'));
    }

    public function test_a_company_cannot_turn_on_a_test_it_was_not_offered(): void
    {
        $this->unavailable('database_integrity')->assertOk();
        $r = $this->admin->put('/api/test-settings', ['tests' => ['database_integrity' => true]])->assertStatus(400);
        $this->assertStringContainsString('not available to your company', $r->json('error'));
        $this->assertFalse($this->settings($this->admin)['database_integrity']['active']);
        $this->off('database_integrity')->assertOk();
    }

    public function test_only_a_platform_admin_can_offer_tests_and_it_affects_only_that_company(): void
    {
        $this->admin->put("/api/platform/companies/{$this->companyId}/test-settings", ['tests' => ['random_restore' => false]])->assertStatus(403);
        $this->admin->get("/api/platform/companies/{$this->companyId}/test-settings")->assertStatus(403);
        $this->unavailable('recovery_point')->assertOk();
        $this->assertTrue($this->settings($this->otherAdmin)['recovery_point']['active'], 'another company is untouched');
        $this->platform->put('/api/platform/companies/999999/test-settings', ['tests' => ['random_restore' => false]])->assertStatus(404);
        $this->platform->put("/api/platform/companies/{$this->companyId}/test-settings", ['tests' => ['nonsense' => false]])->assertStatus(400);
        $this->assertCount(8, $this->platform->get("/api/platform/companies/{$this->companyId}/test-settings")->assertOk()->json('data'));
    }

    public function test_a_platform_admins_change_is_in_the_companys_own_audit_trail(): void
    {
        $this->unavailable('recovery_point')->assertOk();
        $e = collect($this->admin->get('/api/audit?limit=100')->json('data'))->firstWhere('action', 'test_availability_changed');
        $this->assertNotNull($e);
        $this->assertSame(['recovery_point' => false], $e['details']['available']);
        $this->assertTrue($e['details']['via_platform_admin'] ?? false, 'the company can see a platform admin did it');
    }

    public function test_the_two_switches_are_independent(): void
    {
        $this->off('file_permissions')->assertOk();
        $this->unavailable('file_permissions')->assertOk();
        $this->platform->put("/api/platform/companies/{$this->companyId}/test-settings", ['tests' => ['file_permissions' => true]])->assertOk();
        $row = $this->settings($this->admin)['file_permissions'];
        $this->assertSame([true, false, false], [$row['available'], $row['enabled'], $row['active']], 'offered again, but the company had turned it off and still has');
    }

    // ---------------------------------------------------------------- what depended on the test

    public function test_turning_a_test_off_cancels_open_manual_tests_and_skips_queued_automatic_ones(): void
    {
        $manual = $this->admin->post("/api/backup-jobs/{$this->jobId}/manual-test", ['test_type' => 'recovery_point'])->json('data.id');
        $auto = $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'recovery_point'])->json('data.id');
        $other = $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'random_restore'])->json('data.id');
        $this->off('recovery_point')->assertOk();
        $m = DB::table('backup_test_executions')->find($manual);
        $this->assertSame('cancelled', $m->test_status);
        $this->assertStringContainsString('turned off', $m->error_message);
        $a = DB::table('backup_test_executions')->find($auto);
        $this->assertSame('skipped', $a->test_status);
        $this->assertStringContainsString('turned off', $a->error_message);
        $this->assertSame('pending', DB::table('backup_test_executions')->find($other)->test_status, 'other tests are untouched');
        $this->assertNull(DB::table('servers')->find($this->serverId)->last_test_status, 'a skipped test is not a result');
    }

    public function test_a_test_turned_off_after_it_was_claimed_by_the_engine_is_skipped_not_run(): void
    {
        $id = $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'recovery_point'])->json('data.id');
        DB::table('company_test_settings')->insert(['company_id' => $this->companyId, 'test_type' => 'recovery_point', 'available' => 1, 'enabled' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Engine::runDueTests($this->companyId);
        $t = DB::table('backup_test_executions')->find($id);
        $this->assertSame('skipped', $t->test_status);
        $this->assertStringContainsString('turned off for this company', $t->error_message);
    }

    public function test_a_schedule_for_a_test_that_is_off_queues_nothing_and_carries_on(): void
    {
        DB::table('backup_jobs')->where('id', $this->jobId)->update(['status' => 'success']);
        $id = $this->admin->post('/api/test-schedules', ['name' => 'Recovery', 'test_type' => 'recovery_point', 'target_type' => 'all', 'frequency' => 'daily', 'run_time' => '03:00', 'timezone' => 'UTC'])->assertStatus(201)->json('data.id');
        $this->off('recovery_point')->assertOk();
        DB::table('test_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(0, TestScheduling::runDue($this->companyId));
        $this->assertSame(0, DB::table('backup_test_executions')->where('test_type', 'recovery_point')->count());
        $this->assertTrue(CarbonImmutable::parse(DB::table('test_schedules')->find($id)->next_run_at, 'UTC')->isFuture(), 'it moves on rather than running every minute');

        $this->admin->post("/api/test-schedules/$id/run")->assertStatus(409)->assertJsonPath('error_code', 'TEST_011');
        $this->admin->put("/api/test-schedules/$id", ['name' => 'Renamed', 'enabled' => false])->assertOk();
        $this->admin->put('/api/test-settings', ['tests' => ['recovery_point' => true]])->assertOk();
        $this->admin->put("/api/test-schedules/$id", ['enabled' => true])->assertOk();
        DB::table('test_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(1, TestScheduling::runDue($this->companyId), 'back on, it runs again');
    }

    public function test_an_existing_schedule_cannot_be_switched_to_a_test_that_is_off(): void
    {
        $id = $this->admin->post('/api/test-schedules', ['name' => 'R', 'test_type' => 'random_restore', 'target_type' => 'all', 'frequency' => 'daily', 'run_time' => '03:00', 'timezone' => 'UTC'])->json('data.id');
        $this->off('recovery_point')->assertOk();
        $this->admin->put("/api/test-schedules/$id", ['test_type' => 'recovery_point'])->assertStatus(409);
        $this->admin->del("/api/test-schedules/$id")->assertNoContent();
    }

    public function test_the_weekly_automatic_check_skips_a_company_that_turned_off_random_restore_and_runs_for_the_others(): void
    {
        $serverB = $this->otherAdmin->post('/api/servers', ['hostname' => 'srv-b', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $jobB = $this->otherAdmin->post('/api/backup-jobs', ['server_id' => $serverB, 'backup_method' => 'rsync'])->json('data.id');
        DB::table('backup_jobs')->whereIn('id', [$this->jobId, $jobB])->update(['status' => 'success']);
        $this->off('random_restore')->assertOk();
        $this->assertSame(1, Engine::scheduleDueTests());
        $this->assertSame(0, DB::table('backup_test_executions')->where('backup_job_id', $this->jobId)->where('manual', 0)->count());
        $this->assertSame(1, DB::table('backup_test_executions')->where('backup_job_id', $jobB)->count());
    }

    // ---------------------------------------------------------------- what the forms see

    public function test_the_list_of_test_types_says_which_ones_this_company_can_use(): void
    {
        $this->off('m365_mailbox')->assertOk();
        $this->unavailable('database_integrity')->assertOk();
        $types = collect($this->tech->get('/api/test-types')->assertOk()->json('data'))->keyBy('type');
        $this->assertSame([true, false], [$types['random_restore']['active'], $types['m365_mailbox']['active']]);
        $this->assertSame([false, true], [$types['database_integrity']['available'], $types['database_integrity']['enabled']]);
        $this->assertFalse($types['database_integrity']['active']);
        $this->assertNotEmpty($types['m365_mailbox']['details'], 'the help text is still there');
        $theirs = collect($this->otherAdmin->get('/api/test-types')->json('data'))->keyBy('type');
        $this->assertTrue($theirs['m365_mailbox']['active'], 'another company is unaffected');
    }

    public function test_the_service_agrees_with_the_api(): void
    {
        $this->off('full_recovery')->assertOk();
        $this->assertFalse(CompanyTests::active('full_recovery', $this->companyId));
        $this->assertTrue(CompanyTests::active('full_recovery', $this->otherId));
        $this->assertFalse(CompanyTests::active('not-a-test', $this->companyId));
    }
}
