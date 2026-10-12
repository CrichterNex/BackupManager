<?php

namespace Tests\Feature;

use App\Services\Engine\Engine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Restore tests that also compare the backed-up file with its source file, through the agent on the source server. */
class SourceComparisonTest extends TestCase
{
    private ApiClient $admin;

    private int $companyId;

    private int $serverId;

    private int $locationId;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('srccmp');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'src-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->locationId = $this->admin->post('/api/backup-locations', ['name' => 'NAS', 'loc_key' => 'nas'])->json('data.id');
        $this->secret = $this->admin->post("/api/servers/{$this->serverId}/agent")->json('agent_config.token');
    }

    private function job(array $over = [])
    {
        return $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'rsync', 'location_id' => $this->locationId] + $over);
    }

    private function agentCall(string $url, array $body)
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer {$this->secret}"])->postJson($url, $body);
    }

    private function poll(array $extra = [])
    {
        return $this->agentCall('/api/agent/poll', $extra + ['version' => '1.2.0', 'actions' => ['restore-test' => ['type' => 'restore_test']], 'locations' => ['nas'], 'source_roots' => ['/srv/www']]);
    }

    // --- how a job is laid out ---

    public function test_a_layout_must_make_sense_for_what_the_job_covers(): void
    {
        $this->assertSame(201, $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/www'], 'layout' => 'contents'])->status());
        $two = $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/a', '/srv/b'], 'layout' => 'contents']);
        $this->assertSame(400, $two->status());
        $this->assertStringContainsString('exactly one folder', $two->json('error'));
        $this->assertSame(201, $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/a', '/srv/b'], 'layout' => 'by_folder_name'])->status());
        $same = $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/a/docs', '/srv/b/docs'], 'layout' => 'by_folder_name']);
        $this->assertSame(400, $same->status());
        $this->assertStringContainsString('same name', $same->json('error'));
        $this->assertSame(201, $this->job(['scope_type' => 'full_server', 'layout' => 'full_path'])->status());
        $this->assertSame(400, $this->job(['scope_type' => 'full_server', 'layout' => 'contents'])->status());
        $this->assertSame(400, $this->job(['layout' => 'full_path'])->status(), 'a layout needs a stated scope');
        $this->assertSame(400, $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/a'], 'layout' => 'sideways'])->status());
    }

    public function test_changing_the_scope_rechecks_the_layout_and_the_layout_can_be_cleared(): void
    {
        $id = $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/www'], 'layout' => 'contents'])->json('data.id');
        $this->assertSame(400, $this->admin->patch("/api/backup-jobs/$id", ['source_paths' => ['/srv/www', '/srv/api']])->status(), 'two folders no longer fit "contents"');
        $this->assertSame('contents', $this->admin->get("/api/backup-jobs/$id")->json('data.layout'), 'unchanged after the refusal');
        $this->assertSame(200, $this->admin->patch("/api/backup-jobs/$id", ['layout' => 'by_folder_name', 'source_paths' => ['/srv/www', '/srv/api']])->status());
        $this->assertNull($this->admin->patch("/api/backup-jobs/$id", ['layout' => null])->json('data.layout'));
    }

    // --- what the agent is told and what it says back ---

    public function test_the_agent_is_told_the_jobs_folders_exclusions_and_layout_only_when_a_layout_is_set(): void
    {
        $this->poll();
        $withLayout = $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/www'], 'excluded_paths' => ['/srv/www/cache'], 'layout' => 'contents', 'destination_path' => 'web-01'])->json('data.id');
        $plain = $this->job()->json('data.id');
        foreach ([$withLayout, $plain] as $id) {
            DB::table('backup_jobs')->where('id', $id)->update(['status' => 'success']);
            $this->admin->post("/api/backup-jobs/$id/test", ['test_type' => 'random_restore']);
        }
        Engine::runDueTests($this->companyId);
        $first = $this->poll()->json('command');
        $second = $this->poll()->json('command');
        $by = [$first['subpath'] ?? '' => $first, ($second['subpath'] ?? '') => $second];
        $this->assertSame(['layout' => 'contents', 'folders' => ['/srv/www'], 'excluded' => ['/srv/www/cache']], $by['web-01']['source']);
        $this->assertNull($by['']['source'], 'no layout, so no source comparison is asked for');
    }

    public function test_the_agent_advertises_the_source_folders_it_may_read_and_they_are_shown(): void
    {
        $this->poll();
        $this->assertSame(['/srv/www'], $this->admin->get("/api/servers/{$this->serverId}/agent")->json('data.source_roots'));
        $this->assertSame(['/srv/www'], $this->admin->get('/api/agents')->json('data.agents.0.source_roots'));
    }

    private function runTestAndReport(array $result, array $config = []): int
    {
        config($config);
        $this->poll();
        $id = $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/www'], 'layout' => 'contents'])->json('data.id');
        $testId = $this->admin->post("/api/backup-jobs/$id/test", ['test_type' => 'random_restore'])->json('data.id');
        Engine::runDueTests($this->companyId);
        $cmd = $this->poll()->json('command');
        $this->agentCall("/api/agent/commands/{$cmd['id']}/result", $result)->assertOk();

        return $testId;
    }

    public function test_a_verified_comparison_is_recorded_with_the_source_hash_as_the_original(): void
    {
        $id = $this->runTestAndReport(['status' => 'success', 'details' => [
            'hash_algorithm' => 'sha256', 'hash_original' => 'srchash', 'hash_restored' => 'srchash', 'source_status' => 'verified', 'source_note' => 'Compared with /srv/www/a.txt',
        ]]);
        $t = $this->admin->get("/api/tests/$id")->json('data');
        $this->assertSame('success', $t['test_status']);
        $this->assertSame('verified', $t['source_status']);
        $this->assertSame('Compared with /srv/www/a.txt', $t['source_note']);
        $this->assertTrue($t['hash_match']);
    }

    public function test_a_difference_from_an_unchanged_source_becomes_a_critical_failure(): void
    {
        $id = $this->runTestAndReport(['status' => 'failed', 'details' => [
            'failure_type' => 'hash_mismatch', 'severity' => 'critical', 'hash_original' => 'source', 'hash_restored' => 'backup',
            'failed_item' => '/mnt/nas/web-01/a.txt', 'error_message' => 'The backup copy differs from its source file.', 'source_status' => 'mismatch',
        ]], ['bms.test_max_attempts' => 1]);
        $t = $this->admin->get("/api/tests/$id")->json('data');
        $this->assertSame('failed', $t['test_status']);
        $this->assertSame('mismatch', $t['source_status']);
        $this->assertFalse($t['hash_match']);
        $f = $t['failures'][0];
        $this->assertSame(['hash_mismatch', 'critical', 'source', 'backup'], [$f['failure_type'], $f['severity'], $f['expected_value'], $f['actual_value']]);
        $this->assertSame(1, DB::table('notifications')->where('event_type', 'test_failed')->count());
    }

    public function test_not_comparable_is_recorded_honestly_and_is_not_a_failure(): void
    {
        $id = $this->runTestAndReport(['status' => 'success', 'details' => [
            'hash_algorithm' => 'sha256', 'hash_original' => 'h', 'hash_restored' => 'h',
            'source_status' => 'not_comparable', 'source_note' => 'Tried 10 random file(s) and none could be compared with its source: 10 changed since the backup.',
        ]]);
        $t = $this->admin->get("/api/tests/$id")->json('data');
        $this->assertSame('success', $t['test_status']);
        $this->assertSame('not_comparable', $t['source_status']);
        $this->assertStringContainsString('changed since the backup', $t['source_note']);
    }

    public function test_an_unknown_comparison_status_from_an_agent_is_refused(): void
    {
        $this->poll();
        $id = $this->job(['scope_type' => 'folders', 'source_paths' => ['/srv/www'], 'layout' => 'contents'])->json('data.id');
        $this->admin->post("/api/backup-jobs/$id/test", ['test_type' => 'random_restore']);
        Engine::runDueTests($this->companyId);
        $cmd = $this->poll()->json('command');
        $this->assertSame(400, $this->agentCall("/api/agent/commands/{$cmd['id']}/result", ['status' => 'success', 'details' => ['source_status' => 'definitely-fine']])->status());
    }

    public function test_a_test_with_no_agent_leaves_the_source_comparison_unattempted(): void
    {
        $this->admin->del("/api/servers/{$this->serverId}/agent");
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bms-nosrc-'.bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'a.txt', 'x');
        $loc = $this->admin->post('/api/backup-locations', ['name' => 'Local', 'loc_key' => 'local', 'path' => $dir])->json('data.id');
        $id = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'rsync', 'location_id' => $loc, 'scope_type' => 'folders', 'source_paths' => ['/srv/www'], 'layout' => 'contents'])->json('data.id');
        $testId = $this->admin->post("/api/backup-jobs/$id/test", ['test_type' => 'random_restore'])->json('data.id');
        config(['bms.restore_staging_dir' => $dir]);
        Engine::runDueTests($this->companyId);
        $t = $this->admin->get("/api/tests/$testId")->json('data');
        $this->assertSame('success', $t['test_status']);
        $this->assertNull($t['source_status'], 'BMS cannot read a remote source, so it does not claim to have compared');
        unlink($dir.DIRECTORY_SEPARATOR.'a.txt');
        @rmdir($dir);
    }

    public function test_the_compliance_report_counts_source_comparisons(): void
    {
        $this->runTestAndReport(['status' => 'success', 'details' => ['hash_original' => 'h', 'hash_restored' => 'h', 'source_status' => 'verified']]);
        $now = now()->addMinute()->toIso8601String();
        $id = $this->admin->post('/api/reports', ['report_type' => 'compliance', 'period_start' => now()->subDays(1)->toIso8601String(), 'period_end' => $now])->json('data.id');
        $tests = $this->admin->get("/api/reports/$id")->json('data.data.tests');
        $this->assertSame([1, 0, 0], [$tests['source_verified'], $tests['source_mismatch'], $tests['source_not_comparable']]);
        $this->assertStringContainsString('Compared with the source file', $this->admin->get("/api/reports/$id/view")->getContent());
    }
}
