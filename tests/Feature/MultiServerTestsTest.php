<?php

namespace Tests\Feature;

use App\Services\Engine\Engine;
use App\Services\TestScheduling;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** A test for several servers at once, on a schedule or right now, and the type of backup shown wherever tests are listed. */
class MultiServerTestsTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $manager;

    private ApiClient $rival;

    private int $companyId;

    /** @var array<string, int> */
    private array $server = [];

    /** @var array<string, int> */
    private array $job = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('multico');
        $other = $this->makeCompany('rivalmulti');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $techId = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $this->makeUser($other, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->manager = $this->login('mgr');
        $this->rival = $this->login('rival');

        foreach (['alpha' => ['rsync'], 'beta' => ['rsync', 'acronis'], 'gamma' => ['acronis'], 'delta' => ['rsync']] as $host => $methods) {
            $this->server[$host] = $this->admin->post('/api/servers', ['hostname' => $host, 'os_type' => 'linux', 'backup_methods' => $methods])->json('data.id');
            foreach ($methods as $m) {
                $this->job["$host-$m"] = $this->admin->post('/api/backup-jobs', ['server_id' => $this->server[$host], 'backup_method' => $m])->json('data.id');
            }
        }
        // alpha, beta and gamma have a successful backup; delta never did
        DB::table('backup_jobs')->whereIn('id', [$this->job['alpha-rsync'], $this->job['beta-rsync'], $this->job['beta-acronis'], $this->job['gamma-acronis']])
            ->update(['status' => 'success', 'completed_time' => now()->subHours(3)]);
        $this->admin->put("/api/servers/{$this->server['alpha']}/assignments", ['user_ids' => [$techId]]);
        $this->admin->put("/api/servers/{$this->server['beta']}/assignments", ['user_ids' => [$techId]]);
    }

    private function ids(string ...$names): array
    {
        return array_map(fn ($n) => $this->server[$n], $names);
    }

    private function schedule(array $over = [])
    {
        return $this->admin->post('/api/test-schedules', $over + ['name' => 'Chosen', 'test_type' => 'random_restore', 'target_type' => 'servers', 'server_ids' => $this->ids('alpha', 'beta'),
            'frequency' => 'weekly', 'run_day' => 1, 'run_time' => '02:00', 'timezone' => 'UTC']);
    }

    private function due(int $scheduleId): int
    {
        DB::table('test_schedules')->where('id', $scheduleId)->update(['next_run_at' => now()->subMinute()]);

        return TestScheduling::runDue($this->companyId);
    }

    // ---------------------------------------------------------------- schedules for chosen servers

    public function test_a_schedule_can_cover_several_chosen_servers_and_says_so(): void
    {
        $r = $this->schedule()->assertStatus(201);
        $this->assertSame('servers', $r->json('data.target_type'));
        $this->assertEqualsCanonicalizing($this->ids('alpha', 'beta'), $r->json('data.server_ids'));
        $this->assertSame('2 servers: alpha, beta', $r->json('data.target_name'));
        $this->assertSame(['rsync'], $r->json('data.methods'), 'a file test covers only the backups BMS can read');
    }

    public function test_a_long_list_of_servers_is_shortened_in_the_name(): void
    {
        $r = $this->schedule(['server_ids' => $this->ids('alpha', 'beta', 'gamma', 'delta')])->assertStatus(201);
        $this->assertSame('4 servers: alpha, beta, delta and 1 more', $r->json('data.target_name'));
        $this->assertSame('Server alpha', $this->schedule(['name' => 'one', 'server_ids' => $this->ids('alpha')])->json('data.target_name'));
    }

    public function test_a_due_schedule_queues_a_test_for_each_chosen_server_and_only_those(): void
    {
        $id = $this->schedule(['server_ids' => $this->ids('alpha', 'beta', 'gamma')])->json('data.id');
        $this->assertSame(2, $this->due($id), 'alpha and beta have a file backup; gamma has only Acronis, which a file test cannot read');
        $this->assertEqualsCanonicalizing([$this->job['alpha-rsync'], $this->job['beta-rsync']], DB::table('backup_test_executions')->pluck('backup_job_id')->all());
    }

    public function test_a_recovery_point_or_by_hand_schedule_covers_each_backup_type_of_each_chosen_server(): void
    {
        $id = $this->schedule(['test_type' => 'recovery_point', 'server_ids' => $this->ids('beta', 'gamma')])->json('data.id');
        $this->assertSame(['acronis', 'rsync'], $this->admin->get('/api/test-schedules')->json('data.0.methods'));
        $this->assertSame(3, $this->due($id), 'beta has two backup types and gamma one');
        $methods = DB::table('backup_test_executions as t')->join('backup_jobs as j', 'j.id', '=', 't.backup_job_id')->pluck('j.backup_method')->sort()->values()->all();
        $this->assertSame(['acronis', 'acronis', 'rsync'], $methods);
    }

    public function test_the_list_of_servers_is_checked(): void
    {
        $this->schedule(['server_ids' => []])->assertStatus(400);
        $this->schedule(['server_ids' => null])->assertStatus(400);
        $this->schedule(['server_ids' => ['x']])->assertStatus(400);
        $this->schedule(['server_ids' => [999999]])->assertStatus(400);
        $theirs = $this->rival->post('/api/servers', ['hostname' => 'theirs', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->schedule(['server_ids' => [$this->server['alpha'], $theirs]])->assertStatus(400)->assertJsonPath('error', 'One of the chosen servers is not one of yours.');
        $this->assertSame(0, DB::table('test_schedules')->count());
    }

    public function test_the_servers_can_be_changed_and_the_list_is_dropped_when_the_target_changes(): void
    {
        $id = $this->schedule()->json('data.id');
        $r = $this->admin->put("/api/test-schedules/$id", ['server_ids' => $this->ids('alpha', 'beta', 'delta')])->assertOk();
        $this->assertCount(3, $r->json('data.server_ids'));
        $r = $this->admin->put("/api/test-schedules/$id", ['target_type' => 'all'])->assertOk();
        $this->assertNull($r->json('data.server_ids'));
        $this->admin->put("/api/test-schedules/$id", ['target_type' => 'servers'])->assertStatus(400);
    }

    public function test_the_single_server_target_still_works(): void
    {
        $id = $this->schedule(['target_type' => 'server', 'server_id' => $this->server['alpha'], 'server_ids' => null])->assertStatus(201)->json('data.id');
        $this->assertSame(1, $this->due($id));
    }

    // ---------------------------------------------------------------- the type of backup, wherever tests are listed

    public function test_every_test_list_says_which_type_of_backup_it_was(): void
    {
        $this->admin->post("/api/backup-jobs/{$this->job['beta-acronis']}/manual-test", ['test_type' => 'recovery_point'])->assertStatus(201);
        $this->admin->post("/api/backup-jobs/{$this->job['beta-rsync']}/manual-test", ['test_type' => 'recovery_point'])->assertStatus(201);
        $manual = collect($this->admin->get('/api/manual-tests')->json('data'))->pluck('backup_method')->sort()->values()->all();
        $this->assertSame(['acronis', 'rsync'], $manual);

        DB::table('backup_jobs')->where('id', $this->job['gamma-acronis'])->update(['completed_time' => now()->subHours(200)]);
        $this->admin->post("/api/backup-jobs/{$this->job['gamma-acronis']}/test", ['test_type' => 'recovery_point'])->assertStatus(202);
        Engine::runDueTests($this->companyId);
        $history = collect($this->admin->get('/api/tests')->json('data'))->firstWhere('hostname', 'gamma');
        $this->assertSame('acronis', $history['backup_method']);
        $failure = collect($this->admin->get('/api/test-failures')->json('data'))->firstWhere('hostname', 'gamma');
        $this->assertSame('acronis', $failure['backup_method']);
    }
}
