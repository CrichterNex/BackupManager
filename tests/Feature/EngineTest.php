<?php

namespace Tests\Feature;

use App\Services\Engine\Engine;
use App\Services\Engine\RandomRestoreRunner;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

class EngineTest extends TestCase
{
    private ApiClient $admin;

    private int $companyId;

    private string $root;

    private string $staging;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('engine-co');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $this->admin = $this->login('admin');
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bms-engine-'.bin2hex(random_bytes(4));
        $this->staging = $this->root.DIRECTORY_SEPARATOR.'staging';
        mkdir($this->staging, 0777, true);
        config(['bms.restore_staging_dir' => $this->staging]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
            is_dir("$dir/$f") ? $this->removeTree("$dir/$f") : unlink("$dir/$f");
        }
        rmdir($dir);
    }

    private function backupTree(string $name = 'ok'): string
    {
        $dir = $this->root.DIRECTORY_SEPARATOR.$name;
        mkdir($dir.DIRECTORY_SEPARATOR.'sub', 0777, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'a.txt', 'alpha');
        file_put_contents($dir.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'b.txt', 'beta');

        return $dir;
    }

    /** @return array{server: int, job: int} */
    private function makeJob(string $hostname, string $method, ?string $path, string $status = 'success'): array
    {
        $server = $this->admin->post('/api/servers', ['hostname' => $hostname, 'os_type' => 'linux', 'backup_methods' => [$method], 'backup_location_path' => $path])->json('data.id');
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $server, 'backup_method' => $method])->json('data.id');
        DB::table('backup_jobs')->where('id', $job)->update(['status' => $status]);

        return ['server' => $server, 'job' => $job];
    }

    private function executionsFor(int $jobId): array
    {
        return DB::table('backup_test_executions')->where('backup_job_id', $jobId)->orderBy('id')->get()->all();
    }

    // --- runner ---

    public function test_the_restore_runner_passes_an_intact_backup_and_removes_its_staging_copy(): void
    {
        $r = RandomRestoreRunner::run($this->backupTree(), $this->staging);
        $this->assertTrue($r['success']);
        $this->assertSame($r['hash_original'], $r['hash_restored']);
        $this->assertSame([], array_values(array_diff(scandir($this->staging), ['.', '..'])));
    }

    public function test_the_runner_fails_with_file_missing_for_an_empty_or_missing_location(): void
    {
        $empty = $this->root.DIRECTORY_SEPARATOR.'empty';
        mkdir($empty);
        $this->assertSame('file_missing', RandomRestoreRunner::run($empty, $this->staging)['failure_type']);
        $this->assertSame('file_missing', RandomRestoreRunner::run($this->root.DIRECTORY_SEPARATOR.'nope', $this->staging)['failure_type']);
    }

    // --- scheduling and execution ---

    public function test_only_filesystem_jobs_with_a_successful_backup_are_queued_once_per_period(): void
    {
        $good = $this->makeJob('good-01', 'rsync', $this->backupTree());
        $failed = $this->makeJob('failed-01', 'rsync', $this->backupTree('b'), 'failed');
        $vendor = $this->makeJob('veeam-01', 'veeam', null);
        $this->assertSame(1, Engine::scheduleDueTests());
        $this->assertSame(0, Engine::scheduleDueTests());
        $this->assertCount(1, $this->executionsFor($good['job']));
        $this->assertCount(0, $this->executionsFor($failed['job']));
        $this->assertCount(0, $this->executionsFor($vendor['job']));
    }

    public function test_a_pass_is_recorded_and_updates_the_server_and_job(): void
    {
        $good = $this->makeJob('good-01', 'rsync', $this->backupTree());
        Engine::scheduleDueTests();
        Engine::runDueTests($this->companyId);
        $t = $this->executionsFor($good['job'])[0];
        $this->assertSame('success', $t->test_status);
        $this->assertSame(1, (int) $t->hash_match);
        $this->assertSame('passed', DB::table('servers')->where('id', $good['server'])->value('last_test_status'));
        $this->assertSame('passed', DB::table('backup_jobs')->where('id', $good['job'])->value('test_status'));
    }

    public function test_unsupported_tests_are_skipped_not_passed(): void
    {
        ['job' => $job] = $this->makeJob('veeam-02', 'veeam', null);
        $this->assertSame(202, $this->admin->post("/api/backup-jobs/$job/test", ['test_type' => 'random_restore'])->status());
        Engine::runDueTests($this->companyId);
        $t = $this->executionsFor($job)[0];
        $this->assertSame('skipped', $t->test_status);
        $this->assertMatchesRegularExpression('/not available for veeam/', $t->error_message);
    }

    public function test_a_failure_is_retried_quietly_then_recorded_once_and_alerted_after_the_last_attempt(): void
    {
        ['job' => $job] = $this->makeJob('broken-01', 'linux_manual_copy', $this->root.DIRECTORY_SEPARATOR.'missing');
        $this->admin->post("/api/backup-jobs/$job/test", ['test_type' => 'random_restore']);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            Engine::runDueTests($this->companyId);
            $this->assertCount(min($attempt + 1, 3), $this->executionsFor($job));
            $failures = DB::table('backup_test_failures as f')->join('backup_test_executions as t', 't.id', '=', 'f.test_execution_id')->where('t.backup_job_id', $job)->count();
            $this->assertSame($attempt === 3 ? 1 : 0, $failures);
            if ($attempt < 3) {
                DB::table('backup_test_executions')->where('backup_job_id', $job)->where('test_status', 'pending')->update(['scheduled_time' => now()]);
            }
        }
        $alerts = DB::table('notifications')->where('event_type', 'test_failed')->get();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('failed 3 times', $alerts[0]->message);
    }

    public function test_unresolved_failures_escalate_to_managers_once_per_level(): void
    {
        ['job' => $job] = $this->makeJob('broken-02', 'linux_manual_copy', $this->root.DIRECTORY_SEPARATOR.'missing');
        $this->admin->post("/api/backup-jobs/$job/test", ['test_type' => 'random_restore']);
        for ($i = 0; $i < 3; $i++) {
            Engine::runDueTests($this->companyId);
            DB::table('backup_test_executions')->where('test_status', 'pending')->update(['scheduled_time' => now()]);
        }
        DB::table('backup_test_failures')->update(['created_at' => now()->subHours(5)]);
        $this->assertSame(1, Engine::escalateFailures($this->companyId));
        $this->assertSame(0, Engine::escalateFailures($this->companyId));
        DB::table('backup_test_failures')->update(['created_at' => now()->subHours(9)]);
        $this->assertSame(1, Engine::escalateFailures($this->companyId));
        $roles = DB::table('notifications as n')->join('users as u', 'u.id', '=', 'n.user_id')->where('n.event_type', 'failure_escalated')->pluck('u.role')->sort()->values()->all();
        $this->assertSame(['company_admin', 'company_admin', 'company_manager', 'company_manager'], $roles);
    }

    public function test_a_second_process_cannot_run_the_engine_while_one_holds_the_lock(): void
    {
        // A separate connection stands in for another process holding the engine lock.
        $cfg = config('database.connections.'.config('database.default'));
        $other = new \PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}", $cfg['username'], $cfg['password']);
        $other->query("SELECT GET_LOCK('bms_engine', 0)");
        try {
            $this->assertNull(Engine::tick(), 'the pass must be skipped while another process holds the lock');
        } finally {
            $other->query("SELECT RELEASE_LOCK('bms_engine')");
        }
        $this->assertIsArray(Engine::tick(), 'and runs normally once the lock is free');
    }
}
