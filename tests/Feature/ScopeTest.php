<?php

namespace Tests\Feature;

use App\Services\Engine\Engine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

class ScopeTest extends TestCase
{
    private ApiClient $admin;

    private int $companyId;

    private int $serverId;

    private int $locationId;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('scopeco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'web-01', 'os_type' => 'linux', 'backup_methods' => ['rsync', 'robocopy', 'acronis']])->json('data.id');

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bms-scope-'.bin2hex(random_bytes(4));
        $S = DIRECTORY_SEPARATOR;
        foreach (['web-01/daily/sub', 'other', 'empty-dest', 'outside'] as $d) {
            mkdir($this->root.$S.'nas'.$S.str_replace('/', $S, $d), 0777, true);
        }
        mkdir($this->root.$S.'nas'.$S.'..'.$S.'outside-sibling', 0777, true);
        file_put_contents($this->root.$S.'nas'.$S.'root-file.txt', 'root');
        file_put_contents($this->root.$S.'nas'.$S.'web-01'.$S.'daily'.$S.'a.txt', 'alpha');
        file_put_contents($this->root.$S.'nas'.$S.'web-01'.$S.'daily'.$S.'sub'.$S.'b.txt', 'beta');
        file_put_contents($this->root.$S.'nas'.$S.'other'.$S.'z.txt', 'zzz');
        file_put_contents($this->root.$S.'outside-sibling'.$S.'secret.txt', 'secret');
        config(['bms.restore_staging_dir' => $this->root]);
        $this->locationId = $this->admin->post('/api/backup-locations', ['name' => 'NAS', 'loc_key' => 'nas', 'path' => $this->root.$S.'nas'])->json('data.id');
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
        parent::tearDown();
    }

    private function rm(string $d): void
    {
        if (! is_dir($d)) {
            return;
        }
        foreach (array_diff(scandir($d), ['.', '..']) as $f) {
            is_dir("$d/$f") ? $this->rm("$d/$f") : unlink("$d/$f");
        }
        rmdir($d);
    }

    private function job(array $over = [], string $method = 'rsync')
    {
        return $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => $method] + $over);
    }

    // --- what the job backs up ---

    public function test_a_job_can_cover_the_whole_server(): void
    {
        $res = $this->job(['scope_type' => 'full_server']);
        $this->assertSame(201, $res->status());
        $this->assertSame('full_server', $res->json('data.scope_type'));
        $this->assertNull($res->json('data.source_paths'));
    }

    public function test_a_job_can_cover_specific_folders_in_unix_or_windows_style(): void
    {
        $res = $this->job(['scope_type' => 'folders', 'source_paths' => ['/var/www', 'D:\\Data\\Shares', '\\\\fs01\\finance', '/var/www']]);
        $this->assertSame(201, $res->status());
        $this->assertSame(['/var/www', 'D:\\Data\\Shares', '\\\\fs01\\finance'], $res->json('data.source_paths'), 'duplicates dropped, order kept');
    }

    public function test_a_job_with_no_stated_scope_is_allowed_and_shows_as_not_stated(): void
    {
        $res = $this->job();
        $this->assertSame(201, $res->status());
        $this->assertNull($res->json('data.scope_type'));
    }

    public function test_bad_scopes_are_refused(): void
    {
        $this->assertSame(400, $this->job(['scope_type' => 'folders'])->status(), 'folders need a list');
        $this->assertSame(400, $this->job(['scope_type' => 'folders', 'source_paths' => []])->status());
        $this->assertSame(400, $this->job(['scope_type' => 'full_server', 'source_paths' => ['/etc']])->status(), 'a full backup has no list');
        $this->assertSame(400, $this->job(['source_paths' => ['/etc']])->status(), 'a list needs a scope');
        foreach (['var/www', '/var/../etc', 'C:\\x\\..\\y', '', '~/docs'] as $bad) {
            $this->assertSame(400, $this->job(['scope_type' => 'folders', 'source_paths' => [$bad]])->status(), "path: $bad");
        }
        $this->assertSame(400, $this->job(['scope_type' => 'everything'])->status());
    }

    public function test_the_scope_can_be_changed_later(): void
    {
        $id = $this->job(['scope_type' => 'full_server'])->json('data.id');
        $res = $this->admin->patch("/api/backup-jobs/$id", ['scope_type' => 'folders', 'source_paths' => ['/srv/a', '/srv/b']]);
        $this->assertSame(['/srv/a', '/srv/b'], $res->json('data.source_paths'));
        $res = $this->admin->patch("/api/backup-jobs/$id", ['scope_type' => 'full_server', 'source_paths' => []]);
        $this->assertSame('full_server', $res->json('data.scope_type'));
        $this->assertNull($res->json('data.source_paths'));
        $this->assertSame(400, $this->admin->patch("/api/backup-jobs/$id", ['scope_type' => 'folders'])->status(), 'no folders left to keep');
    }

    public function test_platform_backups_can_state_a_scope_too(): void
    {
        $this->assertSame(201, $this->job(['scope_type' => 'full_server'], 'acronis')->status());
    }

    // --- where in the location the data lands ---

    public function test_the_destination_folder_is_relative_and_normalised(): void
    {
        $res = $this->job(['location_id' => $this->locationId, 'destination_path' => 'web-01\\daily\\']);
        $this->assertSame(201, $res->status());
        $this->assertSame('web-01/daily', $res->json('data.destination_path'));
        foreach (['/abs', '\\\\nas\\x', 'C:\\x', '../x', 'a/../b', 'a//b', './a'] as $bad) {
            $this->assertSame(400, $this->job(['location_id' => $this->locationId, 'destination_path' => $bad])->status(), "dest: $bad");
        }
    }

    public function test_a_destination_folder_needs_a_location_and_goes_when_the_location_does(): void
    {
        $this->assertSame(400, $this->job(['destination_path' => 'web-01/daily'])->status());
        $id = $this->job(['location_id' => $this->locationId, 'destination_path' => 'web-01/daily'])->json('data.id');
        $res = $this->admin->patch("/api/backup-jobs/$id", ['location_id' => null]);
        $this->assertNull($res->json('data.destination_path'));
        $this->assertSame(400, $this->admin->patch("/api/backup-jobs/$id", ['destination_path' => 'x'])->status());
    }

    // --- restore tests read the job's own folder ---

    private function restoreTestFor(string $destination): int
    {
        $id = $this->job(['location_id' => $this->locationId, 'destination_path' => $destination])->json('data.id');
        DB::table('backup_jobs')->where('id', $id)->update(['status' => 'success']);
        $this->admin->post("/api/backup-jobs/$id/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);

        return $id;
    }

    private function latestTest(int $jobId): object
    {
        return DB::table('backup_test_executions')->where('backup_job_id', $jobId)->orderBy('id')->first();
    }

    public function test_a_restore_test_reads_only_the_jobs_destination_folder(): void
    {
        $t = $this->latestTest($this->restoreTestFor('web-01/daily'));
        $this->assertSame('success', $t->test_status);

        // The location root has files, but this job's folder is empty, so a test of this job must not pass on them.
        $empty = $this->latestTest($this->restoreTestFor('empty-dest'));
        $this->assertSame('failed', $empty->test_status);
        $this->assertStringContainsString('no files to restore', $empty->error_message);
    }

    public function test_a_missing_destination_folder_is_a_failure_not_a_pass(): void
    {
        $t = $this->latestTest($this->restoreTestFor('web-01/never-created'));
        $this->assertSame('failed', $t->test_status);
        $this->assertStringContainsString('does not exist', $t->error_message);
    }

    public function test_a_destination_that_escapes_the_location_is_never_read(): void
    {
        // Bypass the API check on purpose: this is the second line of defence.
        $id = $this->job(['location_id' => $this->locationId])->json('data.id');
        DB::table('backup_jobs')->where('id', $id)->update(['status' => 'success', 'destination_path' => '../outside-sibling']);
        $this->admin->post("/api/backup-jobs/$id/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $t = $this->latestTest($id);
        $this->assertSame('failed', $t->test_status);
        $this->assertStringContainsString('outside the backup location', $t->error_message);
    }

    // --- agents get a relative sub-path, never a path ---

    public function test_the_agent_gets_the_destination_as_a_relative_subpath(): void
    {
        $secret = $this->admin->post("/api/servers/{$this->serverId}/agent")->json('agent_config.token');
        $poll = function () use ($secret) {
            Auth::forgetGuards();
            $this->flushHeaders();

            return $this->withHeaders(['Authorization' => "Bearer $secret"])->postJson('/api/agent/poll', ['version' => '1', 'actions' => ['restore-test' => ['type' => 'restore_test']], 'locations' => ['nas']]);
        };
        $poll();
        $id = $this->job(['location_id' => $this->locationId, 'destination_path' => 'web-01/daily'])->json('data.id');
        DB::table('backup_jobs')->where('id', $id)->update(['status' => 'success']);
        $this->admin->post("/api/backup-jobs/$id/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $cmd = $poll()->json('command');
        $this->assertSame('nas', $cmd['location']);
        $this->assertSame('web-01/daily', $cmd['subpath']);
        $this->assertStringNotContainsString($this->root, json_encode($cmd));
    }

    // --- reports ---

    public function test_the_dr_report_shows_what_each_server_is_stated_to_back_up(): void
    {
        $this->job(['scope_type' => 'folders', 'source_paths' => ['/etc', '/var/www']]);
        $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/app']], 'robocopy');
        $other = $this->admin->post('/api/servers', ['hostname' => 'db-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $full = $this->admin->post('/api/servers', ['hostname' => 'full-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->admin->post('/api/backup-jobs', ['server_id' => $full, 'backup_method' => 'rsync', 'scope_type' => 'full_server']);

        $id = $this->admin->post('/api/reports', ['report_type' => 'dr_readiness'])->json('data.id');
        $servers = array_column($this->admin->get("/api/reports/$id")->json('data.data.servers'), 'scope', 'hostname');
        $this->assertSame('Folders: /etc, /var/www, /srv/app', $servers['web-01']);
        $this->assertSame('Full server', $servers['full-01']);
        $this->assertNull($servers['db-01']);
        $this->assertStringContainsString('Backs up (as stated)', $this->admin->get("/api/reports/$id/view")->getContent());
        $this->assertNotNull($other);
    }
}
