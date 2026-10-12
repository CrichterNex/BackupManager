<?php

namespace Tests\Feature;

use App\Services\Engine\Engine;
use App\Services\Integrations\AcronisClient;
use App\Services\Integrations\IntegrationSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\ApiClient;
use Tests\TestCase;

/** Acronis is faked with responses shaped like its documentation. A real console is the final check (bms:acronis-probe). */
class IntegrationTest extends TestCase
{
    private const DC = 'https://eu2-cloud.acronis.com';

    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $rival;

    private int $companyId;

    private int $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->companyId = $this->makeCompany('platformco');
        $rivalCompany = $this->makeCompany('rivalco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $techId = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($rivalCompany, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->rival = $this->login('rival');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'file-01', 'os_type' => 'windows', 'backup_methods' => ['acronis']])->json('data.id');
        $this->admin->put("/api/servers/{$this->serverId}/assignments", ['user_ids' => [$techId]]);
    }

    private function machine(string $id, string $name, string $status, ?string $run, ?string $success, string $policyType = 'policy.backup.machine'): array
    {
        return [
            'aggregate' => ['names' => ['Daily plan'], 'status' => $status],
            'context' => ['id' => $id, 'external_id' => "ext-$id", 'name' => $name, 'user_defined_name' => null, 'tenant_id' => 't1', 'type' => 'resource.machine'],
            'policies' => [['type' => $policyType, 'last_run' => $run, 'last_success_run' => $success]],
        ];
    }

    private function fakeAcronis(array $items, int $statusCode = 200): void
    {
        // A fresh client each time: Laravel keeps earlier fakes and they would win over these.
        Http::swap(new HttpFactory);
        Http::fake([
            self::DC.'/api/2/idp/token' => Http::response(['access_token' => 'tok-abc', 'expires_on' => time() + 3600, 'token_type' => 'bearer'], 200),
            self::DC.'/api/resource_management/v4/resource_statuses*' => Http::response(['items' => $items, 'paging' => ['cursors' => ['total' => count($items)]]], $statusCode),
        ]);
    }

    private function connect(array $over = [])
    {
        return $this->admin->post('/api/integrations', $over + ['platform' => 'acronis', 'name' => 'Acronis EU2', 'base_url' => self::DC, 'client_id' => 'client-1234-abcd', 'client_secret' => 'super-secret-value']);
    }

    private function recent(int $hours): string
    {
        return CarbonImmutable::now()->subHours($hours)->toIso8601ZuluString();
    }

    // --- connections ---

    public function test_a_connection_is_checked_stored_encrypted_and_never_shown_again(): void
    {
        $this->fakeAcronis([$this->machine('m1', 'FILE-01', 'idle', $this->recent(2), $this->recent(2))]);
        $res = $this->connect();
        $this->assertSame(201, $res->status());
        $this->assertSame(1, $res->json('devices_visible'));
        $this->assertSame('…abcd', $res->json('data.client_id_hint'));
        $this->assertStringNotContainsString('super-secret-value', $res->getContent());
        $this->assertStringNotContainsString('client-1234-abcd', $res->getContent());

        $stored = DB::table('integrations')->value('credentials');
        $this->assertStringNotContainsString('super-secret-value', $stored, 'encrypted at rest');
        $this->assertStringNotContainsString('credentials', $this->admin->get('/api/integrations')->getContent());

        // The token request used HTTP Basic with the client credentials, as Acronis documents.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/2/idp/token')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('client-1234-abcd:super-secret-value'))
            && str_contains($r->body(), 'grant_type=client_credentials'));
    }

    public function test_bad_credentials_are_rejected_before_anything_is_saved(): void
    {
        Http::fake([self::DC.'/api/2/idp/token' => Http::response(['error' => 'invalid_client'], 401)]);
        $res = $this->connect();
        $this->assertSame(400, $res->status());
        $this->assertStringContainsString('rejected', $res->json('error'));
        $this->assertSame(0, DB::table('integrations')->count());
    }

    public function test_only_https_acronis_hosts_are_accepted_so_bms_cannot_be_aimed_at_the_network(): void
    {
        Http::swap(new HttpFactory);
        Http::fake();
        foreach (['http://eu2-cloud.acronis.com', 'https://localhost', 'https://169.254.169.254', 'https://acronis.com.evil.example', 'https://eu2-cloud.acronis.com:8443', 'https://eu2-cloud.acronis.com/x?y=1', 'https://user:pw@eu2-cloud.acronis.com'] as $url) {
            $this->assertSame(400, $this->connect(['base_url' => $url])->status(), $url);
        }
        Http::assertNothingSent();
    }

    public function test_only_admins_manage_connections_and_other_companies_cannot_see_them(): void
    {
        $this->fakeAcronis([]);
        $id = $this->connect()->json('data.id');
        $this->assertSame(403, $this->tech->get('/api/integrations')->status());
        $this->assertSame(403, $this->tech->post('/api/integrations', [])->status());
        $this->assertSame([], $this->rival->get('/api/integrations')->json('data'));
        $this->assertSame(404, $this->rival->post("/api/integrations/$id/test")->status());
        $this->assertSame(404, $this->rival->del("/api/integrations/$id")->status());
        $this->assertSame(404, $this->rival->get("/api/integrations/$id/devices")->status());
    }

    // --- linking ---

    public function test_a_server_is_linked_to_a_device_picked_from_the_platforms_list(): void
    {
        $this->fakeAcronis([$this->machine('m1', 'FILE-01', 'idle', $this->recent(2), $this->recent(2)), $this->machine('m2', 'OTHER', 'idle', null, null)]);
        $id = $this->connect()->json('data.id');
        $devices = $this->admin->get("/api/integrations/$id/devices")->json('data');
        $this->assertSame(['FILE-01', 'OTHER'], array_column($devices, 'name'));

        $link = $this->admin->post("/api/servers/{$this->serverId}/links", ['integration_id' => $id, 'external_id' => 'm1']);
        $this->assertSame(201, $link->status());
        $this->assertSame('FILE-01', $link->json('data.external_name'));
        $this->assertSame('idle', $link->json('data.last_status'), 'status is shown as the platform reported it');
        $this->assertSame($this->serverId, $this->admin->get("/api/integrations/$id/devices")->json('data.0.linked_server_id'));
        $this->assertCount(1, $this->admin->get("/api/servers/{$this->serverId}")->json('data.links'));

        $this->assertSame(409, $this->admin->post("/api/servers/{$this->serverId}/links", ['integration_id' => $id, 'external_id' => 'm2'])->status(), 'one device per server per connection');
        $second = $this->admin->post('/api/servers', ['hostname' => 'file-02', 'os_type' => 'linux'])->json('data.id');
        $this->assertSame(409, $this->admin->post("/api/servers/$second/links", ['integration_id' => $id, 'external_id' => 'm1'])->status(), 'a device belongs to one server');
        $this->assertSame(400, $this->admin->post("/api/servers/$second/links", ['integration_id' => $id, 'external_id' => 'ghost'])->status(), 'must exist on the platform');
    }

    public function test_linking_follows_server_permissions_and_company_boundaries(): void
    {
        $this->fakeAcronis([$this->machine('m1', 'FILE-01', 'idle', $this->recent(2), $this->recent(2))]);
        $id = $this->connect()->json('data.id');
        $this->assertSame(403, $this->tech->post("/api/servers/{$this->serverId}/links", ['integration_id' => $id, 'external_id' => 'm1'])->status());
        $this->assertSame(404, $this->rival->post("/api/servers/{$this->serverId}/links", ['integration_id' => $id, 'external_id' => 'm1'])->status());
        $this->admin->post("/api/servers/{$this->serverId}/links", ['integration_id' => $id, 'external_id' => 'm1']);
        $this->assertSame(200, $this->tech->get("/api/servers/{$this->serverId}/links")->status(), 'an assigned technician can see the link');
        $linkId = $this->admin->get("/api/servers/{$this->serverId}/links")->json('data.0.id');
        $this->assertSame(204, $this->admin->del("/api/servers/{$this->serverId}/links/$linkId")->status());
        $this->assertCount(0, $this->admin->get("/api/servers/{$this->serverId}/links")->json('data'));
    }

    // --- status ---

    private function linked(array $machine): int
    {
        $this->fakeAcronis([$machine]);
        $id = $this->connect()->json('data.id');
        $this->admin->post("/api/servers/{$this->serverId}/links", ['integration_id' => $id, 'external_id' => $machine['context']['id']]);

        return $id;
    }

    public function test_a_recent_successful_backup_marks_the_server_healthy_and_records_the_run(): void
    {
        $this->linked($this->machine('m1', 'FILE-01', 'idle', $this->recent(3), $this->recent(3)));
        $server = $this->admin->get("/api/servers/{$this->serverId}")->json('data');
        $this->assertSame('success', $server['last_backup_status']);
        $this->assertSame(1, DB::table('backup_jobs')->where('server_id', $this->serverId)->where('backup_method', 'acronis')->where('status', 'success')->count());
        $this->assertStringContainsString('Reported by Acronis EU2', DB::table('backup_jobs')->value('status_message'));
    }

    public function test_a_failed_latest_run_marks_the_server_failing_and_alerts_admins_once(): void
    {
        $id = $this->linked($this->machine('m1', 'FILE-01', 'error', $this->recent(1), $this->recent(30)));
        $this->assertSame('failed', $this->admin->get("/api/servers/{$this->serverId}")->json('data.last_backup_status'));
        $this->assertSame('failing', $this->admin->get("/api/servers/{$this->serverId}")->json('data.status'));
        $alerts = fn () => DB::table('notifications')->where('event_type', 'backup_failed')->count();
        $this->assertSame(1, $alerts());
        $this->admin->post("/api/integrations/$id/sync");
        $this->admin->post("/api/integrations/$id/sync");
        $this->assertSame(1, $alerts(), 'still failing is not a new alert');
        $this->assertSame(1, DB::table('backup_jobs')->where('status', 'failed')->count(), 'the same run is not recorded twice');
    }

    public function test_an_old_success_is_a_warning_not_a_pass(): void
    {
        $this->linked($this->machine('m1', 'FILE-01', 'idle', $this->recent(80), $this->recent(80)));
        $this->assertSame('warning', $this->admin->get("/api/servers/{$this->serverId}")->json('data.last_backup_status'));
        $this->assertSame(1, DB::table('notifications')->where('subject', 'Backup is overdue')->count());
    }

    public function test_no_backup_data_is_unknown_and_changes_nothing(): void
    {
        $this->linked($this->machine('m1', 'FILE-01', 'not_protected', null, null));
        $this->assertNull($this->admin->get("/api/servers/{$this->serverId}")->json('data.last_backup_status'));
        $this->assertSame(0, DB::table('backup_jobs')->count());
    }

    public function test_only_backup_policies_count_never_antimalware_or_patching(): void
    {
        $this->linked($this->machine('m1', 'FILE-01', 'idle', $this->recent(1), $this->recent(1), 'policy.security.antimalware'));
        $this->assertNull($this->admin->get("/api/servers/{$this->serverId}")->json('data.last_backup_status'));
    }

    public function test_a_device_that_disappears_from_the_platform_is_flagged_not_assumed_fine(): void
    {
        $id = $this->linked($this->machine('m1', 'FILE-01', 'idle', $this->recent(2), $this->recent(2)));
        $this->fakeAcronis([]);
        $this->admin->post("/api/integrations/$id/sync");
        $link = $this->admin->get("/api/servers/{$this->serverId}/links")->json('data.0');
        $this->assertStringContainsString('not found on the platform', $link['sync_error']);
    }

    public function test_a_platform_outage_is_reported_once_and_the_last_known_status_is_kept(): void
    {
        $id = $this->linked($this->machine('m1', 'FILE-01', 'idle', $this->recent(2), $this->recent(2)));
        Http::swap(new HttpFactory);
        Http::fake([self::DC.'/api/2/idp/token' => Http::response('', 503)]);
        Cache::flush();
        $this->assertFalse($this->admin->post("/api/integrations/$id/sync")->json('success'));
        $this->admin->post("/api/integrations/$id/sync");
        $this->assertSame(1, DB::table('notifications')->where('event_type', 'integration_error')->count());
        $this->assertSame('success', $this->admin->get("/api/servers/{$this->serverId}")->json('data.last_backup_status'), 'an outage is not a backup failure');
        $this->assertSame('error', $this->admin->get('/api/integrations')->json('data.0.last_sync_status'));
    }

    public function test_the_engine_syncs_due_connections_and_skips_recent_ones(): void
    {
        $this->linked($this->machine('m1', 'FILE-01', 'idle', $this->recent(2), $this->recent(2)));
        $this->assertSame(0, IntegrationSync::runDue($this->companyId, 15), 'just synced when linked');
        DB::table('integrations')->update(['last_sync_at' => now()->subMinutes(20)]);
        $this->assertSame(1, IntegrationSync::runDue($this->companyId, 15));
        DB::table('integrations')->update(['last_sync_at' => now()->subMinutes(20)]);
        $this->assertSame(1, Engine::tick()['synced']);
    }

    // --- parsing ---

    public function test_platform_times_are_read_whether_text_or_numbers(): void
    {
        $this->assertSame('2026-03-01T10:00:00+00:00', AcronisClient::parseTime('2026-03-01T12:00:00+02:00')->toIso8601String());
        $this->assertSame('2023-11-14T22:13:20+00:00', AcronisClient::parseTime(1700000000)->toIso8601String());
        $this->assertNull(AcronisClient::parseTime(null));
        $this->assertNull(AcronisClient::parseTime('not a time'));
    }

    public function test_state_rules(): void
    {
        $now = CarbonImmutable::now();
        $this->assertSame('success', IntegrationSync::stateFor($now->subHours(2), $now->subHours(2), 36));
        $this->assertSame('failed', IntegrationSync::stateFor($now->subHour(), $now->subDay(), 36));
        $this->assertSame('failed', IntegrationSync::stateFor($now->subHour(), null, 36));
        $this->assertSame('warning', IntegrationSync::stateFor($now->subHours(50), $now->subHours(50), 36));
        $this->assertSame('unknown', IntegrationSync::stateFor(null, null, 36));
    }
}
