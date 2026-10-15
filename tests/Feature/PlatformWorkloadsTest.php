<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\PlatformWorkloads;
use App\Services\TestScheduling;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\ApiClient;
use Tests\TestCase;

/**
 * Microsoft 365 backups in Acronis: one activity per mailbox, OneDrive, Team or site, summed up per kind of workload.
 */
class PlatformWorkloadsTest extends TestCase
{
    private const DC = 'https://eu2-cloud.acronis.com';

    private const BACKUP = '0016750A-EE56-49E6-BF59-7C768C9869AC';

    private const CLEANUP = '019CF690-803B-451F-9E4B-B76C478BC05F';

    private ApiClient $admin;

    private int $companyId;

    private int $integrationId;

    private int $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->companyId = $this->makeCompany('m365co');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'tenant365', 'os_type' => 'microsoft_365', 'backup_methods' => ['acronis_m365']])->json('data.id');
    }

    private function ago(float $hours): string
    {
        return CarbonImmutable::now()->subMinutes((int) ($hours * 60))->toIso8601ZuluString();
    }

    private function item(string $kind, string $id, string $result = 'ok', array $over = []): array
    {
        $a = ['uuid' => 'act-'.$kind.'-'.$id.'-'.md5(json_encode($over).$result), 'type' => self::BACKUP, 'state' => 'completed', 'createdAt' => $this->ago(3), 'startedAt' => $this->ago(3), 'completedAt' => $this->ago(2), 'updatedAt' => $this->ago(2),
            'resource' => ['id' => strtoupper($id), 'type' => $kind, 'name' => "name-$id"], 'policy' => ['id' => 'p', 'name' => 'Microsoft 365 mailboxes'],
            'context' => ['activityType' => 'backup'], 'result' => ['code' => $result]];
        if ($result === 'error') {
            $a['result']['error'] = ['domain' => 'task-manager', 'code' => 'timedOut', 'context' => ['reason' => 'heartbeat-expired']];
        }
        if ($result === 'warning') {
            $a['result']['warnings'] = [['code' => 'O365MailboxFolderContentListInternalError', 'context' => ['message' => 'Could not list a folder']]];
        }

        return array_replace_recursive($a, $over);
    }

    private function fake(array $activities): void
    {
        Http::swap(new HttpFactory);
        Http::fake([
            self::DC.'/api/2/idp/token' => Http::response(['access_token' => 'tok', 'expires_on' => time() + 3600, 'token_type' => 'bearer'], 200),
            self::DC.'/api/resource_management/v4/resource_statuses*' => Http::response(['items' => [], 'paging' => ['cursors' => ['total' => 0]]], 200),
            self::DC.'/api/task_manager/v2/activities*' => Http::response(['items' => $activities, 'paging' => ['cursors' => []]], 200),
        ]);
    }

    private function sync(array $activities): void
    {
        $this->fake($activities);
        $this->integrationId ??= $this->admin->post('/api/integrations', ['platform' => 'acronis', 'name' => 'Acronis 365', 'base_url' => self::DC, 'client_id' => 'client-1234', 'client_secret' => 'secret-value'])->json('data.id');
        $this->admin->post("/api/integrations/{$this->integrationId}/sync")->assertOk();
    }

    private function summary(string $w): ?array
    {
        return CompanyContext::run($this->companyId, fn () => PlatformWorkloads::summary(Integration::find($this->integrationId), $w));
    }

    private function link(string $id = 'm365:mailbox')
    {
        return $this->admin->post("/api/servers/{$this->serverId}/links", ['integration_id' => $this->integrationId, 'external_id' => $id]);
    }

    public function test_each_item_is_kept_once_with_its_latest_result_and_its_name(): void
    {
        $this->sync([
            $this->item('MAILBOX', 'a'), $this->item('MAILBOX', 'b', 'warning'), $this->item('TEAM', 't1', 'error'), $this->item('DRIVE', 'd1'), $this->item('SITE', 's1', 'cancelled'),
            $this->item('MAILBOX', 'c', 'ok', ['type' => self::CLEANUP]),
            $this->item('MAILBOX', 'e', 'ok', ['state' => 'started', 'result' => null]),
        ]);
        $this->assertSame(5, DB::table('platform_workload_items')->count(), 'the cleanup of old backups and a backup still running are not results');
        $rows = DB::table('platform_workload_items')->get()->keyBy('resource_id');
        $this->assertSame('name-a', $rows['a']->name, 'kept for every item, so a test can be picked from what was really backed up');
        $this->assertSame('name-b', $rows['b']->name);
        $this->assertSame('O365MailboxFolderContentListInternalError', $rows['b']->error_code);
        $this->assertSame(['error', 'timedOut', 'heartbeat-expired'], [$rows['t1']->result, $rows['t1']->error_code, $rows['t1']->message]);
        $this->assertSame('cancelled', $rows['s1']->result);
    }

    public function test_a_later_result_replaces_an_earlier_one_and_old_activity_read_again_never_does(): void
    {
        $this->sync([$this->item('MAILBOX', 'a', 'error', ['completedAt' => $this->ago(10)])]);
        $this->assertSame('error', DB::table('platform_workload_items')->value('result'));
        $this->sync([$this->item('MAILBOX', 'a', 'ok', ['completedAt' => $this->ago(1)])]);
        $row = DB::table('platform_workload_items')->first();
        $this->assertSame(['ok', 'name-a'], [$row->result, $row->name]);
        $this->sync([$this->item('MAILBOX', 'a', 'error', ['completedAt' => $this->ago(10)])]);
        $this->assertSame('ok', DB::table('platform_workload_items')->value('result'));
        $this->assertSame(1, DB::table('platform_workload_items')->count());
    }

    public function test_a_workload_with_nothing_read_has_no_summary(): void
    {
        $this->sync([]);
        $this->assertNull($this->summary('mailbox'));
    }

    public function test_the_counts_and_the_state_of_a_healthy_workload(): void
    {
        $items = [];
        foreach (range(1, 98) as $i) {
            $items[] = $this->item('MAILBOX', "ok$i");
        }
        $items[] = $this->item('MAILBOX', 'w1', 'warning');
        $items[] = $this->item('MAILBOX', 'x1', 'error');
        $items[] = $this->item('MAILBOX', 'c1', 'cancelled');
        $this->sync($items);
        $s = $this->summary('mailbox');
        $this->assertSame([101, 98, 1, 1, 1], [$s['items'], $s['ok'], $s['warning'], $s['failed'], $s['cancelled']]);
        $this->assertSame('success', $s['state'], '1 failure in 100 counted items is under the 5 percent allowed, and a cancelled one is not a failure');
        $this->assertSame(['timedOut' => 1], $s['errors']);
        $this->assertSame(['O365MailboxFolderContentListInternalError' => 1], $s['warnings']);
        $this->assertSame(['Microsoft 365 mailboxes'], $s['plans']);
    }

    public function test_too_many_failed_items_fail_the_workload_and_none_succeeding_does_too(): void
    {
        $this->sync([...array_map(fn ($i) => $this->item('TEAM', "ok$i"), range(1, 9)), ...array_map(fn ($i) => $this->item('TEAM', "bad$i", 'error'), range(1, 2))]);
        $s = $this->summary('teams');
        $this->assertSame(['failed', 18.2], [$s['state'], $s['failed_percent']]);

        DB::table('platform_workload_items')->delete();
        $this->sync([$this->item('SITE', 'only', 'error')]);
        $this->assertSame('failed', $this->summary('sharepoint')['state']);
    }

    public function test_a_workload_nothing_has_backed_up_lately_is_a_warning_but_not_while_still_catching_up(): void
    {
        $this->sync([$this->item('DRIVE', 'd1', 'ok', ['completedAt' => $this->ago(60)])]);
        $this->assertSame('warning', $this->summary('onedrive')['state']);

        DB::table('integrations')->update(['activity_through' => now()->subDays(3)]);
        $this->assertSame('unknown', $this->summary('onedrive')['state'], 'while BMS reads older activity, an old last backup says nothing about now');
    }

    public function test_items_outside_the_window_are_not_counted(): void
    {
        $this->sync([$this->item('DRIVE', 'old', 'error', ['completedAt' => $this->ago(24 * 9)]), $this->item('DRIVE', 'new')]);
        $this->assertSame(1, $this->summary('onedrive')['items']);
    }

    public function test_the_connection_offers_each_kind_read_as_a_device_and_a_server_can_be_linked_to_one(): void
    {
        $this->sync([$this->item('MAILBOX', 'a'), $this->item('DRIVE', 'd')]);
        $devices = collect($this->admin->get("/api/integrations/{$this->integrationId}/devices")->json('data'));
        $this->assertSame(['m365:mailbox', 'm365:onedrive'], $devices->where('workload', true)->pluck('id')->sort()->values()->all());
        $this->assertStringContainsString('all items', $devices->firstWhere('id', 'm365:mailbox')['name']);

        $this->link('m365:mailbox')->assertStatus(201);
        $s = $this->admin->get("/api/servers/{$this->serverId}")->json('data');
        $this->assertSame('success', $s['last_backup_status']);
        $this->assertSame('mailbox', $s['workloads'][0]['workload']);
        $this->assertSame(1, $s['workloads'][0]['summary']['items']);
        $job = DB::table('backup_jobs')->where('server_id', $this->serverId)->first();
        $this->assertSame(['acronis_m365', 'success'], [$job->backup_method, $job->status]);
        $this->assertStringContainsString('1 ok', $job->status_message);
    }

    public function test_a_kind_with_nothing_read_cannot_be_linked(): void
    {
        $this->sync([$this->item('MAILBOX', 'a')]);
        $this->link('m365:teams')->assertStatus(400);
        $this->link('m365:bogus')->assertStatus(400);
    }

    public function test_a_failed_workload_marks_the_server_failed_and_raises_a_problem_to_review_once(): void
    {
        $this->sync([...array_map(fn ($i) => $this->item('TEAM', "ok$i"), range(1, 3)), ...array_map(fn ($i) => $this->item('TEAM', "bad$i", 'error'), range(1, 2))]);
        $this->link('m365:teams')->assertStatus(201);
        $this->admin->post("/api/integrations/{$this->integrationId}/sync")->assertOk();
        $this->assertSame('failed', DB::table('servers')->find($this->serverId)->last_backup_status);
        $f = DB::table('backup_failures')->where('server_id', $this->serverId)->get();
        $this->assertCount(1, $f);
        $this->assertSame(['failed', 'acronis_m365'], [$f[0]->kind, $f[0]->backup_method]);
        $this->assertStringContainsString('2 of 5 items failed', $f[0]->message);
        $this->assertStringContainsString('timedOut (x2)', $f[0]->message);
        $w = $this->admin->get("/api/servers/{$this->serverId}")->json('data.workloads.0');
        $this->assertCount(2, $w['problems']);
        $this->assertStringStartsWith('name-bad', $w['problems'][0]['name']);
    }

    public function test_the_integrations_page_data_carries_the_summaries(): void
    {
        $this->sync([$this->item('SITE', 's1'), $this->item('SITE', 's2', 'error')]);
        $row = collect($this->admin->get('/api/integrations')->json('data'))->firstWhere('id', $this->integrationId);
        $this->assertSame('sharepoint', $row['workloads'][0]['workload']);
        $this->assertSame([2, 1, 1], [$row['workloads'][0]['items'], $row['workloads'][0]['ok'], $row['workloads'][0]['failed']]);
    }

    public function test_the_first_read_starts_from_a_date_and_later_ones_from_the_cursor(): void
    {
        $this->sync([]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'task_manager') && str_contains(urldecode($r->url()), 'updatedAt=gt(') && ! isset($r->data()['after']));
    }

    // ---------------------------------------------------------------- tests pick from what Acronis really backed up

    private function pending(string $type): array
    {
        return DB::table('backup_test_executions')->where('test_type', $type)->orderBy('id')->pluck('target')->all();
    }

    public function test_a_mailbox_test_picks_any_mailbox_acronis_backed_up_with_no_list_to_fill_in(): void
    {
        $this->sync([$this->item('MAILBOX', 'a'), $this->item('MAILBOX', 'b'), $this->item('DRIVE', 'd1'), $this->item('SITE', 's1')]);
        $t = $this->admin->post("/api/servers/{$this->serverId}/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201)->json('data');
        $this->assertContains($t['target'], ['name-a', 'name-b'], 'a mailbox, not a drive or a site');
    }

    public function test_each_365_test_samples_its_own_kind_of_item(): void
    {
        $this->sync([$this->item('MAILBOX', 'a'), $this->item('DRIVE', 'd1'), $this->item('SITE', 's1')]);
        $this->admin->post("/api/servers/{$this->serverId}/manual-test", ['test_type' => 'onedrive_file'])->assertStatus(201);
        $this->admin->post("/api/servers/{$this->serverId}/manual-test", ['test_type' => 'sharepoint_site'])->assertStatus(201);
        $this->assertSame(['name-d1'], $this->pending('onedrive_file'));
        $this->assertSame(['name-s1'], $this->pending('sharepoint_site'));
    }

    public function test_an_item_whose_latest_backup_failed_or_was_cancelled_is_never_picked(): void
    {
        $this->sync([$this->item('MAILBOX', 'bad', 'error'), $this->item('MAILBOX', 'gone', 'cancelled'), $this->item('MAILBOX', 'good', 'warning')]);
        foreach (range(1, 4) as $i) {
            $id = $this->admin->post("/api/servers/{$this->serverId}/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201)->json('data.id');
            DB::table('backup_test_executions')->where('id', $id)->update(['test_status' => 'cancelled']);
        }
        $this->assertSame(['name-good'], array_values(array_unique($this->pending('m365_mailbox'))));
    }

    public function test_it_goes_through_the_mailboxes_before_repeating_one(): void
    {
        $this->sync([$this->item('MAILBOX', 'a'), $this->item('MAILBOX', 'b'), $this->item('MAILBOX', 'c')]);
        foreach (range(1, 3) as $i) {
            $id = $this->admin->post("/api/servers/{$this->serverId}/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201)->json('data.id');
            DB::table('backup_test_executions')->where('id', $id)->update(['test_status' => 'cancelled']);
        }
        $picked = $this->pending('m365_mailbox');
        $this->assertCount(3, array_unique($picked), 'three tests, three different mailboxes');
    }

    public function test_the_tenants_own_list_still_wins_when_it_has_one(): void
    {
        $this->sync([$this->item('MAILBOX', 'a')]);
        $this->admin->patch("/api/servers/{$this->serverId}", ['test_targets' => ['chosen@example.test']])->assertOk();
        $t = $this->admin->post("/api/servers/{$this->serverId}/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201)->json('data');
        $this->assertSame('chosen@example.test', $t['target']);
    }

    public function test_with_nothing_read_from_acronis_there_is_no_pick_and_the_tester_chooses(): void
    {
        $this->sync([]);
        $t = $this->admin->post("/api/servers/{$this->serverId}/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201)->json('data');
        $this->assertNull($t['target']);
    }

    public function test_a_linked_server_samples_only_from_its_own_connection(): void
    {
        $this->sync([$this->item('MAILBOX', 'mine')]);
        $this->link('m365:mailbox')->assertStatus(201);
        $other = DB::table('integrations')->insertGetId(['company_id' => $this->companyId, 'platform' => 'acronis', 'name' => 'Other tenant', 'base_url' => self::DC, 'credentials' => encrypt(['client_id' => 'x', 'client_secret' => 'y']), 'active' => true, 'stale_after_hours' => 36, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform_workload_items')->insert(['company_id' => $this->companyId, 'integration_id' => $other, 'workload' => 'mailbox', 'resource_id' => 'zz', 'result' => 'ok', 'name' => 'name-theirs', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 3) as $i) {
            $id = $this->admin->post("/api/servers/{$this->serverId}/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201)->json('data.id');
            DB::table('backup_test_executions')->where('id', $id)->update(['test_status' => 'cancelled']);
        }
        $this->assertSame(['name-mine'], array_values(array_unique($this->pending('m365_mailbox'))));
    }

    public function test_a_schedule_picks_from_acronis_too(): void
    {
        $this->sync([$this->item('MAILBOX', 'a'), $this->item('MAILBOX', 'b')]);
        $sid = $this->admin->post('/api/test-schedules', ['name' => 'Mailboxes', 'test_type' => 'm365_mailbox', 'manual' => true, 'target_type' => 'servers', 'server_ids' => [$this->serverId], 'frequency' => 'daily', 'run_time' => '07:00', 'timezone' => 'UTC'])->assertStatus(201)->json('data.id');
        DB::table('test_schedules')->where('id', $sid)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(1, TestScheduling::runDue($this->companyId));
        $this->assertContains($this->pending('m365_mailbox')[0], ['name-a', 'name-b']);
    }

    // ---------------------------------------------------------------- one tenant, several workloads

    public function test_a_tenant_links_to_several_workloads_and_shows_one_status_from_all_of_them(): void
    {
        $this->sync([$this->item('MAILBOX', 'a', 'ok', ['completedAt' => $this->ago(5)]), $this->item('DRIVE', 'd', 'ok', ['completedAt' => $this->ago(1)]), $this->item('TEAM', 't', 'ok', ['completedAt' => $this->ago(3)])]);
        foreach (['m365:mailbox', 'm365:onedrive', 'm365:teams'] as $w) {
            $this->link($w)->assertStatus(201);
        }
        $this->link('m365:teams')->assertStatus(409);
        $server = DB::table('servers')->find($this->serverId);
        $this->assertSame('success', $server->last_backup_status);
        $this->assertEqualsWithDelta(now()->subHours(5)->getTimestamp(), strtotime($server->last_backup_time.' UTC'), 120, 'the tenant was last fully backed up when its oldest workload last succeeded');
        $api = $this->admin->get("/api/servers/{$this->serverId}")->json('data');
        $this->assertCount(3, $api['workloads']);
        $this->assertSame(3, DB::table('server_links')->where('server_id', $this->serverId)->count());
    }

    public function test_one_failed_workload_fails_the_tenant_with_a_single_problem_that_names_it(): void
    {
        $this->sync([$this->item('MAILBOX', 'a'), ...array_map(fn ($i) => $this->item('TEAM', "ok$i"), range(1, 3)), ...array_map(fn ($i) => $this->item('TEAM', "bad$i", 'error'), range(1, 2))]);
        $this->link('m365:mailbox')->assertStatus(201);
        $this->link('m365:teams')->assertStatus(201);
        $this->admin->post("/api/integrations/{$this->integrationId}/sync")->assertOk();
        $this->assertSame('failed', DB::table('servers')->find($this->serverId)->last_backup_status);
        $f = DB::table('backup_failures')->where('server_id', $this->serverId)->get();
        $this->assertCount(1, $f);
        $this->assertStringContainsString('Microsoft Teams', $f[0]->message);
        $this->assertStringNotContainsString('Microsoft 365 mailboxes', $f[0]->message);
    }

    public function test_a_server_links_to_a_device_or_to_workloads_never_both(): void
    {
        $this->sync([$this->item('MAILBOX', 'a')]);
        DB::table('server_links')->insert(['company_id' => $this->companyId, 'server_id' => $this->serverId, 'integration_id' => $this->integrationId, 'external_id' => 'machine-1', 'external_name' => 'M1', 'created_at' => now(), 'updated_at' => now()]);
        $this->link('m365:mailbox')->assertStatus(409);
    }

    public function test_the_link_list_offers_workloads_to_tenants_and_machines_to_the_rest(): void
    {
        $this->sync([$this->item('MAILBOX', 'a')]);
        $devices = collect($this->admin->get("/api/integrations/{$this->integrationId}/devices")->json('data'));
        $this->assertTrue($devices->every(fn ($d) => isset($d['workload'])), 'each device says whether it is a workload, so the form can offer the right ones');
        $this->assertSame(['m365:mailbox'], $devices->where('workload', true)->pluck('id')->all());
    }

    public function test_a_workload_keeps_one_backup_record_for_each_day_not_one_for_each_sync(): void
    {
        $this->sync([$this->item('MAILBOX', 'a', 'ok', ['completedAt' => $this->ago(3)])]);
        $this->link('m365:mailbox')->assertStatus(201);
        $this->assertSame(1, DB::table('backup_jobs')->where('server_id', $this->serverId)->count());

        // Later the same day Acronis backs up another mailbox, so the workload's last success moves on. Still one record, brought up to date.
        $this->sync([$this->item('MAILBOX', 'b', 'ok', ['completedAt' => $this->ago(1)])]);
        $this->admin->post("/api/integrations/{$this->integrationId}/sync")->assertOk();
        $jobs = DB::table('backup_jobs')->where('server_id', $this->serverId)->get();
        $this->assertCount(1, $jobs);
        $this->assertEqualsWithDelta(now()->subHours(1)->getTimestamp(), strtotime($jobs[0]->completed_time.' UTC'), 120, 'it carries the latest time');
        $this->assertSame('m365:mailbox', $jobs[0]->platform_ref);
        $this->assertStringContainsString('2 ok', $jobs[0]->status_message);
    }

    public function test_each_workload_has_its_own_record_and_a_new_day_a_new_one(): void
    {
        $this->sync([$this->item('MAILBOX', 'a', 'ok', ['completedAt' => $this->ago(2)]), $this->item('DRIVE', 'd', 'ok', ['completedAt' => $this->ago(2)])]);
        $this->link('m365:mailbox')->assertStatus(201);
        $this->link('m365:onedrive')->assertStatus(201);
        $this->assertSame(['m365:mailbox', 'm365:onedrive'], DB::table('backup_jobs')->where('server_id', $this->serverId)->orderBy('platform_ref')->pluck('platform_ref')->all());

        // The record is of yesterday now; the workload's next backup, today, is a new record.
        DB::table('backup_jobs')->where('platform_ref', 'm365:mailbox')->update(['completed_time' => now()->subDay()]);
        $this->sync([$this->item('MAILBOX', 'a2', 'ok', ['completedAt' => $this->ago(1)])]);
        $this->admin->post("/api/integrations/{$this->integrationId}/sync")->assertOk();
        $this->assertSame(2, DB::table('backup_jobs')->where('platform_ref', 'm365:mailbox')->count());
        $this->assertSame(1, DB::table('backup_jobs')->where('platform_ref', 'm365:onedrive')->count());
    }

    public function test_a_teams_test_picks_a_team_acronis_backed_up_and_never_a_failed_one(): void
    {
        $this->sync([$this->item('TEAM', 'good'), $this->item('TEAM', 'bad', 'error'), $this->item('MAILBOX', 'm1')]);
        foreach (range(1, 3) as $i) {
            $id = $this->admin->post("/api/servers/{$this->serverId}/manual-test", ['test_type' => 'teams_channel'])->assertStatus(201)->json('data.id');
            DB::table('backup_test_executions')->where('id', $id)->update(['test_status' => 'cancelled']);
        }
        $this->assertSame(['name-good'], array_values(array_unique($this->pending('teams_channel'))), 'a team, not a mailbox, and not one whose backup failed');
    }
}
