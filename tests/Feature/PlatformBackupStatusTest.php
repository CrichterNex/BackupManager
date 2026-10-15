<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** The Backups page lists what the backup platform says about each linked server. */
class PlatformBackupStatusTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $tech;

    private int $companyId;

    private int $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('statco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $tid = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->integration = $this->integration($this->companyId, 'Acronis EU');
        $this->techId = $tid;
    }

    private int $techId;

    private function integration(int $company, string $name): int
    {
        return DB::table('integrations')->insertGetId(['company_id' => $company, 'platform' => 'acronis', 'name' => $name, 'base_url' => 'https://x.test', 'credentials' => encrypt(['a' => 'b']),
            'active' => true, 'stale_after_hours' => 36, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function linked(string $host, array $link, int $integration = 0, ?int $company = null, bool $active = true): int
    {
        $company ??= $this->companyId;
        $sid = DB::table('servers')->insertGetId(['company_id' => $company, 'hostname' => $host, 'os_type' => 'windows', 'criticality' => 'standard', 'backup_methods' => '["acronis"]', 'active' => $active,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('server_links')->insert($link + ['company_id' => $company, 'server_id' => $sid, 'integration_id' => $integration ?: $this->integration, 'external_name' => strtoupper($host), 'created_at' => now(), 'updated_at' => now()]);

        return $sid;
    }

    private function rows(?ApiClient $who = null)
    {
        return collect(($who ?? $this->admin)->get('/api/platform-backups')->assertOk()->json('data'))->keyBy('hostname');
    }

    public function test_each_linked_device_shows_the_platforms_status_and_times_and_bms_s_verdict(): void
    {
        $this->linked('good01', ['external_id' => 'd1', 'last_status' => 'idle', 'last_run_at' => now()->subHours(3), 'last_success_at' => now()->subHours(3), 'synced_at' => now()->subMinutes(5)]);
        $this->linked('bad01', ['external_id' => 'd2', 'last_status' => 'critical', 'last_run_at' => now()->subHour(), 'last_success_at' => now()->subDays(2), 'synced_at' => now()]);
        $this->linked('quiet01', ['external_id' => 'd3', 'last_status' => 'ok', 'last_run_at' => now()->subDays(4), 'last_success_at' => now()->subDays(4), 'synced_at' => now()]);
        $this->linked('new01', ['external_id' => 'd4', 'sync_error' => 'This device was not found on the platform.']);

        $r = $this->rows();
        $this->assertSame(['success', 'failed', 'warning', 'unknown'], [$r['good01']['state'], $r['bad01']['state'], $r['quiet01']['state'], $r['new01']['state']]);
        $this->assertSame(['idle', 'critical'], [$r['good01']['platform_status'], $r['bad01']['platform_status']], 'the platform\'s own word is kept');
        $this->assertSame(['device', 'Acronis EU', 'GOOD01'], [$r['good01']['kind'], $r['good01']['connection'], $r['good01']['backup']]);
        $this->assertNotNull($r['good01']['last_success_at']);
        $this->assertSame('This device was not found on the platform.', $r['new01']['sync_error']);
    }

    public function test_a_microsoft_365_workload_shows_its_counts(): void
    {
        $sid = $this->linked('tenant01', ['external_id' => 'm365:mailbox', 'external_name' => 'Microsoft 365 mailboxes', 'last_status' => 'success', 'last_run_at' => now()->subHour(), 'last_success_at' => now()->subHour(), 'synced_at' => now()]);
        foreach ([['a', 'ok'], ['b', 'ok'], ['c', 'error']] as [$id, $result]) {
            DB::table('platform_workload_items')->insert(['company_id' => $this->companyId, 'integration_id' => $this->integration, 'workload' => 'mailbox', 'resource_id' => $id, 'result' => $result,
                'error_code' => $result === 'error' ? 'timedOut' : null, 'name' => $id, 'completed_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $row = $this->rows()['tenant01'];
        $this->assertSame(['workload', 'success', 'Microsoft 365 mailboxes'], [$row['kind'], $row['state'], $row['backup']]);
        $this->assertSame('2 ok, 0 with warnings, 1 failed of 3 items (timedOut)', $row['detail']);
        $this->assertNull($row['platform_status']);
        $this->assertSame($sid, $row['server_id']);
    }

    public function test_a_technician_sees_only_their_servers_and_other_companies_never_appear(): void
    {
        $mine = $this->linked('mine01', ['external_id' => 'd1', 'last_status' => 'ok']);
        $this->linked('theirs01', ['external_id' => 'd2', 'last_status' => 'ok']);
        DB::table('server_assignments')->insert(['company_id' => $this->companyId, 'server_id' => $mine, 'user_id' => $this->techId]);
        $other = $this->makeCompany('rival');
        $this->linked('rival-box', ['external_id' => 'd9', 'last_status' => 'ok'], $this->integration($other, 'Rival Acronis'), $other);

        $this->assertSame(['mine01'], $this->rows($this->tech)->keys()->all());
        $this->assertEqualsCanonicalizing(['mine01', 'theirs01'], $this->rows()->keys()->all(), 'an admin sees the whole company and nobody else\'s');
    }

    public function test_a_removed_server_is_left_out_and_with_nothing_linked_the_list_is_empty(): void
    {
        $this->assertSame([], $this->admin->get('/api/platform-backups')->assertOk()->json('data'));
        $this->linked('gone01', ['external_id' => 'd1', 'last_status' => 'ok'], 0, null, false);
        $this->assertSame([], $this->admin->get('/api/platform-backups')->json('data'));
    }

    // ---------------------------------------------------------------- one list: jobs BMS manages and platform rows

    private function record(int $server, string $method, array $over = []): int
    {
        return DB::table('backup_jobs')->insertGetId($over + ['company_id' => $this->companyId, 'server_id' => $server, 'backup_method' => $method, 'status' => 'success', 'completed_time' => now()->subHour(),
            'is_record' => true, 'status_message' => 'Reported by Acronis EU', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_platform_row_carries_what_the_same_row_needs_to_run_a_test(): void
    {
        $sid = $this->linked('app01', ['external_id' => 'd1', 'last_status' => 'idle', 'last_run_at' => now()->subHours(2), 'last_success_at' => now()->subHours(2)]);
        DB::table('servers')->where('id', $sid)->update(['last_test_status' => 'passed', 'last_test_time' => now()->subDay()]);
        $old = $this->record($sid, 'acronis', ['completed_time' => now()->subDays(2)]);
        $new = $this->record($sid, 'acronis');
        $row = $this->rows()['app01'];
        $this->assertSame(['acronis', $new, 'passed'], [$row['method'], $row['job_id'], $row['test_status']], 'the newest record, so a test lands on the latest backup');
        $this->assertNotSame($old, $row['job_id']);
        $this->assertNotNull($row['last_test_time']);
    }

    public function test_a_platform_row_with_no_record_yet_has_no_job_to_test_against(): void
    {
        $this->linked('fresh01', ['external_id' => 'd1', 'last_status' => 'idle']);
        $this->assertNull($this->rows()['fresh01']['job_id']);
    }

    public function test_a_workload_row_picks_the_record_of_its_own_workload(): void
    {
        $sid = $this->linked('tenant02', ['external_id' => 'm365:teams', 'external_name' => 'Microsoft Teams', 'last_status' => 'success']);
        $this->record($sid, 'acronis_m365', ['platform_ref' => 'm365:mailbox']);
        $teams = $this->record($sid, 'acronis_m365', ['platform_ref' => 'm365:teams']);
        $row = $this->rows()['tenant02'];
        $this->assertSame(['acronis_m365', $teams], [$row['method'], $row['job_id']]);
    }

    public function test_the_job_list_can_leave_out_the_records_kept_for_platform_backups(): void
    {
        $sid = $this->linked('mixed01', ['external_id' => 'd1', 'last_status' => 'idle']);
        $this->record($sid, 'acronis');
        $this->record($sid, 'acronis', ['completed_time' => now()->subDay()]);
        $mine = $this->admin->post('/api/backup-jobs', ['server_id' => $sid, 'backup_method' => 'acronis'])->assertStatus(201)->json('data.id');
        $this->assertCount(3, $this->admin->get('/api/backup-jobs')->json('data'), 'everything, as before');
        $managed = $this->admin->get('/api/backup-jobs?managed=1')->json('data');
        $this->assertSame([$mine], array_column($managed, 'id'));
        $this->assertCount(3, $this->admin->get('/api/backup-jobs?managed=0')->json('data'));
        $this->admin->get('/api/backup-jobs?managed=maybe')->assertStatus(400);
    }

    public function test_what_the_sync_and_a_test_by_hand_make_is_marked_as_a_record(): void
    {
        $id = $this->admin->post('/api/servers', ['hostname' => 'tenant03', 'os_type' => 'microsoft_365', 'backup_methods' => ['acronis_m365']])->assertStatus(201)->json('data.id');
        $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201);
        $this->assertSame([1], DB::table('backup_jobs')->where('server_id', $id)->pluck('is_record')->map(fn ($v) => (int) $v)->all(), 'the stand-in is not a job BMS manages');
        $real = $this->admin->post('/api/servers', ['hostname' => 'plain01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $jid = $this->admin->post('/api/backup-jobs', ['server_id' => $real, 'backup_method' => 'rsync'])->json('data.id');
        $this->assertSame(0, (int) DB::table('backup_jobs')->where('id', $jid)->value('is_record'));
    }
}
