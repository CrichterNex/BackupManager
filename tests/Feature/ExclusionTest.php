<?php

namespace Tests\Feature;

use Tests\ApiClient;
use Tests\TestCase;

/** Folders a job leaves out: C:\Folder is backed up but C:\Folder\Sub1 is not. */
class ExclusionTest extends TestCase
{
    private ApiClient $admin;

    private int $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        $company = $this->makeCompany('exclco');
        $this->makeUser($company, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'win-01', 'os_type' => 'windows', 'backup_methods' => ['robocopy', 'rsync']])->json('data.id');
    }

    private function job(array $body, string $method = 'robocopy')
    {
        return $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => $method] + $body);
    }

    private function folders(array $include, array $exclude)
    {
        return $this->job(['scope_type' => 'folders', 'source_paths' => $include, 'excluded_paths' => $exclude]);
    }

    public function test_a_folder_inside_a_backed_up_folder_can_be_excluded(): void
    {
        $res = $this->folders(['C:\\Folder'], ['C:\\Folder\\Sub1']);
        $this->assertSame(201, $res->status());
        $this->assertSame(['C:\\Folder'], $res->json('data.source_paths'));
        $this->assertSame(['C:\\Folder\\Sub1'], $res->json('data.excluded_paths'));
    }

    public function test_several_folders_and_several_exclusions_work_together(): void
    {
        $res = $this->folders(['C:\\Folder', 'D:\\Data'], ['C:\\Folder\\Sub1', 'C:\\Folder\\Sub2\\Cache', 'D:\\Data\\tmp', 'D:\\Data\\tmp']);
        $this->assertSame(201, $res->status());
        $this->assertSame(['C:\\Folder\\Sub1', 'C:\\Folder\\Sub2\\Cache', 'D:\\Data\\tmp'], $res->json('data.excluded_paths'), 'duplicates dropped');
    }

    public function test_windows_paths_ignore_case_and_trailing_slashes_but_unix_paths_do_not(): void
    {
        $this->assertSame(201, $this->folders(['C:\\Folder'], ['c:\\FOLDER\\sub1\\'])->status());
        $this->assertSame(400, $this->folders(['/srv/App'], ['/srv/app/tmp'])->status(), 'a different folder on Linux');
        $this->assertSame(201, $this->folders(['/srv/App'], ['/srv/App/tmp'], 'rsync')->status());
    }

    public function test_an_exclusion_must_be_inside_something_being_backed_up(): void
    {
        $res = $this->folders(['C:\\Folder'], ['D:\\Other']);
        $this->assertSame(400, $res->status());
        $this->assertStringContainsString('would change nothing', $res->json('error'));
        $this->assertSame(400, $this->folders(['C:\\Data'], ['C:\\Data2\\x'])->status(), 'a sibling that only shares a name prefix is not inside');
    }

    public function test_an_exclusion_cannot_cancel_or_swallow_a_folder_that_is_being_backed_up(): void
    {
        $same = $this->folders(['C:\\Folder'], ['C:\\Folder\\']);
        $this->assertSame(400, $same->status());
        $this->assertStringContainsString('Remove it from the folder list', $same->json('error'));
        $parent = $this->folders(['C:\\Folder\\Data'], ['C:\\Folder']);
        $this->assertSame(400, $parent->status());
        $this->assertStringContainsString('would remove that folder too', $parent->json('error'));
    }

    public function test_exclusions_need_a_scope_and_valid_paths(): void
    {
        $this->assertSame(400, $this->job(['excluded_paths' => ['C:\\Folder\\Sub1']])->status(), 'no scope stated');
        foreach (['Sub1', 'C:\\Folder\\..\\Windows', ''] as $bad) {
            $this->assertSame(400, $this->folders(['C:\\Folder'], [$bad])->status(), "path: $bad");
        }
    }

    public function test_a_full_server_backup_can_exclude_any_folder(): void
    {
        $res = $this->job(['scope_type' => 'full_server', 'excluded_paths' => ['C:\\Windows\\Temp', 'D:\\Recycle']]);
        $this->assertSame(201, $res->status());
        $this->assertSame(['C:\\Windows\\Temp', 'D:\\Recycle'], $res->json('data.excluded_paths'));
    }

    public function test_changing_the_folder_list_rechecks_the_exclusions(): void
    {
        $id = $this->folders(['C:\\Folder'], ['C:\\Folder\\Sub1'])->json('data.id');
        $res = $this->admin->patch("/api/backup-jobs/$id", ['source_paths' => ['D:\\Other']]);
        $this->assertSame(400, $res->status(), 'the exclusion would no longer be inside anything');
        $this->assertSame(['C:\\Folder'], $this->admin->get("/api/backup-jobs/$id")->json('data.source_paths'), 'unchanged after the refusal');
        $this->assertSame(200, $this->admin->patch("/api/backup-jobs/$id", ['source_paths' => ['C:\\Folder', 'D:\\Other'], 'excluded_paths' => ['C:\\Folder\\Sub1', 'D:\\Other\\x']])->status());
    }

    public function test_exclusions_can_be_edited_cleared_and_survive_other_changes(): void
    {
        $id = $this->folders(['C:\\Folder'], ['C:\\Folder\\Sub1'])->json('data.id');
        $this->admin->patch("/api/backup-jobs/$id", ['schedule' => '0 3 * * *']);
        $this->assertSame(['C:\\Folder\\Sub1'], $this->admin->get("/api/backup-jobs/$id")->json('data.excluded_paths'), 'an unrelated edit keeps them');
        $this->assertSame(['C:\\Folder\\Sub9'], $this->admin->patch("/api/backup-jobs/$id", ['excluded_paths' => ['C:\\Folder\\Sub9']])->json('data.excluded_paths'));
        $this->assertNull($this->admin->patch("/api/backup-jobs/$id", ['excluded_paths' => []])->json('data.excluded_paths'));
    }

    public function test_switching_to_full_server_keeps_valid_exclusions_and_a_folder_list_is_still_refused(): void
    {
        $id = $this->folders(['C:\\Folder'], ['C:\\Folder\\Sub1'])->json('data.id');
        $res = $this->admin->patch("/api/backup-jobs/$id", ['scope_type' => 'full_server', 'source_paths' => []]);
        $this->assertSame(200, $res->status());
        $this->assertSame(['C:\\Folder\\Sub1'], $res->json('data.excluded_paths'));
        $this->assertNull($res->json('data.source_paths'));
    }

    public function test_the_dr_report_states_the_exclusions_against_the_right_job(): void
    {
        $this->folders(['C:\\Folder'], ['C:\\Folder\\Sub1']);
        $this->job(['scope_type' => 'folders', 'source_paths' => ['D:\\Data']]);
        $this->job(['scope_type' => 'full_server', 'excluded_paths' => ['C:\\Windows\\Temp']], 'rsync');
        $id = $this->admin->post('/api/reports', ['report_type' => 'dr_readiness'])->json('data.id');
        $scope = $this->admin->get("/api/reports/$id")->json('data.data.servers.0.scope');
        $this->assertSame('Folders: D:\\Data; Folders: C:\\Folder (except C:\\Folder\\Sub1); Full server (except C:\\Windows\\Temp)', $scope);
        $this->assertStringContainsString('except C:\\Folder\\Sub1', $this->admin->get("/api/reports/$id/view")->getContent());
    }
}
