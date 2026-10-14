<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\PlatformWorkloads;
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

    public function test_each_item_is_kept_once_with_its_latest_result_and_named_only_when_it_has_a_problem(): void
    {
        $this->sync([
            $this->item('MAILBOX', 'a'), $this->item('MAILBOX', 'b', 'warning'), $this->item('TEAM', 't1', 'error'), $this->item('DRIVE', 'd1'), $this->item('SITE', 's1', 'cancelled'),
            $this->item('MAILBOX', 'c', 'ok', ['type' => self::CLEANUP]),
            $this->item('MAILBOX', 'e', 'ok', ['state' => 'started', 'result' => null]),
        ]);
        $this->assertSame(5, DB::table('platform_workload_items')->count(), 'the cleanup of old backups and a backup still running are not results');
        $rows = DB::table('platform_workload_items')->get()->keyBy('resource_id');
        $this->assertNull($rows['a']->name, 'no name kept for a good result');
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
        $this->assertSame(['ok', null], [$row->result, $row->name], 'the new result is kept and the name goes with the problem');
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
}
