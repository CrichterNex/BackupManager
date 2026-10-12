<?php

namespace Tests\Feature;

use App\Services\Engine\Engine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

class LocationTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $manager;

    private ApiClient $rival;

    private int $companyId;

    private int $serverId;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('locco');
        $rivalCompany = $this->makeCompany('rivalloc');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($this->companyId, 'manager', 'company_manager');
        $this->makeUser($rivalCompany, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->manager = $this->login('manager');
        $this->rival = $this->login('rival');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'srv-01', 'os_type' => 'windows', 'backup_methods' => ['robocopy', 'manual_copy', 'acronis', 'rsync']])->json('data.id');
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bms-loc-'.bin2hex(random_bytes(4));
        mkdir($this->dir.DIRECTORY_SEPARATOR.'data', 0777, true);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'f.txt', 'hello');
        config(['bms.restore_staging_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $p) {
            is_dir($p) ? $this->rmTree($p) : unlink($p);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function rmTree(string $d): void
    {
        foreach (array_diff(scandir($d), ['.', '..']) as $f) {
            is_dir("$d/$f") ? $this->rmTree("$d/$f") : unlink("$d/$f");
        }
        rmdir($d);
    }

    private function location(array $over = [])
    {
        return $this->admin->post('/api/backup-locations', $over + ['name' => 'Main NAS', 'loc_key' => 'nas-main', 'kind' => 'nas', 'path' => $this->dir.DIRECTORY_SEPARATOR.'data']);
    }

    // --- managing locations ---

    public function test_admins_create_locations_and_everyone_who_sees_jobs_can_read_them(): void
    {
        $res = $this->location();
        $this->assertSame(201, $res->status());
        $this->assertSame('nas-main', $res->json('data.loc_key'));
        $this->assertTrue($res->json('data.readable_by_bms'));
        $this->assertSame(200, $this->tech->get('/api/backup-locations')->status());
        $this->assertSame(200, $this->manager->get('/api/backup-locations')->status());
        $this->assertSame(403, $this->tech->post('/api/backup-locations', ['name' => 'x', 'loc_key' => 'x'])->status());
        $this->assertSame(403, $this->manager->post('/api/backup-locations', ['name' => 'x', 'loc_key' => 'x'])->status());
    }

    public function test_a_location_needs_no_bms_path_when_only_an_agent_can_reach_it(): void
    {
        $res = $this->location(['name' => 'Offsite drive', 'loc_key' => 'offsite-usb', 'kind' => 'external_drive', 'path' => null]);
        $this->assertSame(201, $res->status());
        $this->assertFalse($res->json('data.readable_by_bms'));
    }

    public function test_input_is_validated(): void
    {
        $this->assertSame(400, $this->location(['loc_key' => 'Bad Key!'])->status());
        $this->assertSame(400, $this->location(['kind' => 'cloud-magic'])->status());
        foreach (['relative/path', '../etc', '/data/../etc', 'C:\\backups\\..\\Windows'] as $bad) {
            $this->assertSame(400, $this->location(['path' => $bad])->status(), $bad);
        }
        $this->assertSame(201, $this->location(['name' => 'Win share', 'loc_key' => 'win-share', 'path' => 'D:\\Backups'])->status());
        $this->assertSame(201, $this->location(['name' => 'UNC', 'loc_key' => 'unc', 'path' => '\\\\nas01\\backups'])->status());
    }

    public function test_names_and_keys_are_unique_per_company_and_reusable_across_companies(): void
    {
        $this->assertSame(201, $this->location()->status());
        $this->assertSame(409, $this->location(['name' => 'Other name'])->status(), 'same key');
        $this->assertSame(409, $this->location(['loc_key' => 'other-key'])->status(), 'same name');
        $this->assertSame(201, $this->rival->post('/api/backup-locations', ['name' => 'Main NAS', 'loc_key' => 'nas-main'])->status());
    }

    public function test_locations_are_private_to_their_company(): void
    {
        $id = $this->location()->json('data.id');
        $this->assertSame([], $this->rival->get('/api/backup-locations')->json('data'));
        $this->assertSame(404, $this->rival->patch("/api/backup-locations/$id", ['notes' => 'x'])->status());
        $this->assertSame(404, $this->rival->del("/api/backup-locations/$id")->status());
        $rivalServer = $this->rival->post('/api/servers', ['hostname' => 'r-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->assertSame(404, $this->rival->post('/api/backup-jobs', ['server_id' => $rivalServer, 'backup_method' => 'rsync', 'location_id' => $id])->status(), 'cannot use another company\'s location');
    }

    public function test_a_location_in_use_cannot_be_removed_or_rekeyed(): void
    {
        $id = $this->location()->json('data.id');
        $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'robocopy', 'location_id' => $id]);
        $this->assertSame(409, $this->admin->del("/api/backup-locations/$id")->status());
        $this->assertSame(409, $this->admin->patch("/api/backup-locations/$id", ['loc_key' => 'renamed'])->status());
        $this->assertSame(200, $this->admin->patch("/api/backup-locations/$id", ['notes' => 'rack 3'])->status());
        $free = $this->location(['name' => 'Spare', 'loc_key' => 'spare'])->json('data.id');
        $this->assertSame(204, $this->admin->del("/api/backup-locations/$free")->status());
    }

    public function test_an_optional_root_limits_where_paths_may_point(): void
    {
        config(['bms.location_path_roots' => [$this->dir]]);
        $this->assertSame(201, $this->location()->status());
        $this->assertSame(400, $this->location(['name' => 'Elsewhere', 'loc_key' => 'elsewhere', 'path' => '/etc'])->status());
    }

    // --- jobs and locations ---

    public function test_file_copy_methods_take_a_location_and_platform_methods_do_not(): void
    {
        $id = $this->location()->json('data.id');
        foreach (['robocopy', 'manual_copy', 'rsync'] as $method) {
            $res = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => $method, 'location_id' => $id]);
            $this->assertSame(201, $res->status(), $method);
            $this->assertSame('Main NAS', $res->json('data.location_name'));
        }
        $res = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'acronis', 'location_id' => $id]);
        $this->assertSame(400, $res->status());
        $this->assertStringContainsString('configured on that platform', $res->json('error'));
        $platformJob = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'acronis'])->json('data.id');
        $this->assertSame(400, $this->admin->patch("/api/backup-jobs/$platformJob", ['location_id' => $id])->status(), 'not on update either');
        $this->assertNull($this->admin->get("/api/backup-jobs/$platformJob")->json('data.location_id'));
    }

    // --- restore tests use the job's location ---

    public function test_a_restore_test_reads_the_jobs_location(): void
    {
        $id = $this->location()->json('data.id');
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'robocopy', 'location_id' => $id])->json('data.id');
        DB::table('backup_jobs')->where('id', $job)->update(['status' => 'success']);
        $this->assertSame(1, Engine::scheduleDueTests());
        Engine::runDueTests($this->companyId);
        $t = DB::table('backup_test_executions')->first();
        $this->assertSame('success', $t->test_status);
        $this->assertSame(1, (int) $t->hash_match);
    }

    public function test_a_job_without_a_location_is_skipped_with_a_clear_reason(): void
    {
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'manual_copy'])->json('data.id');
        $this->admin->post("/api/backup-jobs/$job/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $t = DB::table('backup_test_executions')->first();
        $this->assertSame('skipped', $t->test_status);
        $this->assertStringContainsString('no backup location', $t->error_message);
    }

    public function test_an_agent_only_location_without_an_agent_is_skipped_not_guessed(): void
    {
        $id = $this->location(['name' => 'Offsite', 'loc_key' => 'offsite', 'path' => null])->json('data.id');
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'robocopy', 'location_id' => $id])->json('data.id');
        $this->admin->post("/api/backup-jobs/$job/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $this->assertStringContainsString('only be reached by an agent', DB::table('backup_test_executions')->value('error_message'));
    }

    public function test_platform_backups_are_never_restore_tested_by_bms_even_with_an_agent(): void
    {
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'acronis'])->json('data.id');
        DB::table('backup_jobs')->where('id', $job)->update(['status' => 'success']);
        $this->assertSame(0, Engine::scheduleDueTests(), 'not scheduled');
        $this->admin->post("/api/backup-jobs/$job/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $this->assertStringContainsString('stored on that platform', DB::table('backup_test_executions')->value('error_message'));
    }

    // --- agents: the location travels as a key, never as a path ---

    private function agentSecret(): string
    {
        return $this->admin->post("/api/servers/{$this->serverId}/agent")->json('agent_config.token');
    }

    private function poll(string $secret, array $locations)
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer $secret"])->postJson('/api/agent/poll', [
            'version' => '1', 'actions' => ['restore-test' => ['type' => 'restore_test']], 'locations' => $locations,
        ]);
    }

    public function test_the_agent_gets_the_location_key_only_and_must_know_it(): void
    {
        $id = $this->location(['path' => null])->json('data.id');
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'robocopy', 'location_id' => $id])->json('data.id');
        $secret = $this->agentSecret();

        $this->poll($secret, []);
        $this->admin->post("/api/backup-jobs/$job/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $this->assertSame('skipped', DB::table('backup_test_executions')->value('test_status'));
        $this->assertStringContainsString('does not know the location "nas-main"', DB::table('backup_test_executions')->value('error_message'));

        DB::table('backup_test_executions')->delete();
        $this->poll($secret, ['nas-main']);
        $this->admin->post("/api/backup-jobs/$job/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $cmd = $this->poll($secret, ['nas-main'])->json('command');
        $this->assertSame('nas-main', $cmd['location']);
        $this->assertStringNotContainsString($this->dir, json_encode($cmd), 'no path is ever sent');
        $this->assertSame(['nas-main'], $this->admin->get("/api/servers/{$this->serverId}")->json('data.agent.locations'));
    }

    public function test_the_agent_rejects_malformed_location_keys(): void
    {
        $secret = $this->agentSecret();
        $this->assertSame(400, $this->poll($secret, ['../../etc'])->status());
        $this->assertSame(400, $this->poll($secret, ['Has Spaces'])->status());
    }
}
