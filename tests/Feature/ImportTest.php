<?php

namespace Tests\Feature;

use App\Services\Integrations\IntegrationImport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\ApiClient;
use Tests\TestCase;

/** Importing servers from the devices a platform connection lists. Acronis is faked with documented response shapes. */
class ImportTest extends TestCase
{
    private const DC = 'https://eu2-cloud.acronis.com';

    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $rival;

    private int $companyId;

    private int $existingServerId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->companyId = $this->makeCompany('importco');
        $rivalCompany = $this->makeCompany('rivalimp');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($rivalCompany, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->rival = $this->login('rival');
        $this->existingServerId = $this->admin->post('/api/servers', ['hostname' => 'file-01', 'os_type' => 'windows', 'backup_methods' => ['rsync']])->json('data.id');
    }

    private function recent(int $hours): string
    {
        return CarbonImmutable::now()->subHours($hours)->toIso8601ZuluString();
    }

    private function machine(string $id, string $name, string $status, ?string $run, ?string $success): array
    {
        return [
            'aggregate' => ['names' => ['Daily plan'], 'status' => $status],
            'context' => ['id' => $id, 'external_id' => "ext-$id", 'name' => $name, 'type' => 'resource.machine'],
            'policies' => [['type' => 'policy.backup.machine', 'last_run' => $run, 'last_success_run' => $success]],
        ];
    }

    /** A connection to a console that lists four machines: two protected, one with no plan, one matching a server we have. */
    private function connection(): int
    {
        Http::swap(new HttpFactory);
        Http::fake([
            self::DC.'/api/2/idp/token' => Http::response(['access_token' => 'tok', 'expires_on' => time() + 3600], 200),
            self::DC.'/api/resource_management/v4/resource_statuses*' => Http::response(['items' => [
                $this->machine('m1', 'DB-SERVER-01', 'idle', $this->recent(2), $this->recent(2)),
                $this->machine('m2', 'My Laptop (Sales)!', 'idle', $this->recent(3), $this->recent(3)),
                ['aggregate' => ['names' => [], 'status' => 'not_protected'], 'context' => ['id' => 'm3', 'name' => 'unprotected-box', 'type' => 'resource.machine'], 'policies' => []],
                $this->machine('m4', 'FILE-01', 'idle', $this->recent(2), $this->recent(2)),
            ]], 200),
        ]);

        return $this->admin->post('/api/integrations', ['platform' => 'acronis', 'name' => 'Acronis EU2', 'base_url' => self::DC, 'client_id' => 'cid-1234', 'client_secret' => 'secret-value'])->json('data.id');
    }

    private function import(int $id, array $ids, array $over = [])
    {
        return $this->admin->post("/api/integrations/$id/import", $over + ['external_ids' => $ids, 'os_type' => 'windows']);
    }

    public function test_the_device_list_says_which_are_protected_and_what_they_would_be_called(): void
    {
        $id = $this->connection();
        $by = array_column($this->admin->get("/api/integrations/$id/devices")->json('data'), null, 'id');
        $this->assertTrue($by['m1']['protected']);
        $this->assertFalse($by['m3']['protected'], 'no plan applied');
        $this->assertSame('My-Laptop-Sales', $by['m2']['suggested_hostname']);
        $this->assertSame($this->existingServerId, $by['m4']['matches_server_id'], 'a server called file-01 already exists');
        $this->assertNull($by['m1']['matches_server_id']);
    }

    public function test_picked_devices_become_linked_servers_with_their_real_status(): void
    {
        $id = $this->connection();
        $res = $this->import($id, ['m1', 'm2']);
        $this->assertSame(200, $res->status());
        $this->assertSame(2, $res->json('data.created'));
        $server = DB::table('servers')->where('hostname', 'DB-SERVER-01')->first();
        $this->assertSame('windows', $server->os_type);
        $this->assertSame(['acronis'], json_decode($server->backup_methods, true));
        $this->assertSame('success', $server->last_backup_status, 'status is read straight away');
        $this->assertSame(1, DB::table('server_links')->where('server_id', $server->id)->count());
        $this->assertSame(1, DB::table('servers')->where('hostname', 'My-Laptop-Sales')->count());
        $this->assertSame(0, DB::table('servers')->where('hostname', 'unprotected-box')->count(), 'only what was picked');
    }

    public function test_a_device_matching_an_existing_server_is_linked_not_duplicated(): void
    {
        $id = $this->connection();
        $res = $this->import($id, ['m4'], ['os_type' => 'linux']);
        $this->assertSame(1, $res->json('data.linked_existing'));
        $this->assertSame(0, $res->json('data.created'));
        $this->assertSame(1, DB::table('servers')->where('hostname', 'file-01')->count());
        $this->assertSame(1, DB::table('server_links')->where('server_id', $this->existingServerId)->count());
        $server = $this->admin->get("/api/servers/{$this->existingServerId}")->json('data');
        $this->assertSame('windows', $server['os_type'], 'an existing server keeps its own settings');
        $this->assertContains('acronis', $server['backup_methods']);
        $this->assertContains('rsync', $server['backup_methods']);
    }

    public function test_linking_to_existing_servers_can_be_switched_off(): void
    {
        $id = $this->connection();
        $res = $this->import($id, ['m4'], ['link_existing' => false]);
        $this->assertSame(1, $res->json('data.skipped'));
        $this->assertStringContainsString('already exists', $res->json('data.results.0.reason'));
        $this->assertSame(0, DB::table('server_links')->count());
    }

    public function test_importing_twice_does_nothing_the_second_time(): void
    {
        $id = $this->connection();
        $this->import($id, ['m1']);
        $again = $this->import($id, ['m1']);
        $this->assertSame(0, $again->json('data.created'));
        $this->assertSame('Already linked to a server.', $again->json('data.results.0.reason'));
        $this->assertSame(1, DB::table('servers')->where('hostname', 'DB-SERVER-01')->count());
    }

    public function test_the_subscription_server_limit_stops_an_import_part_way(): void
    {
        $id = $this->connection();
        DB::table('companies')->where('id', $this->companyId)->update(['max_servers' => 2]); // file-01 already counts as one
        $res = $this->import($id, ['m1', 'm2']);
        $this->assertSame(1, $res->json('data.created'));
        $this->assertSame(1, $res->json('data.skipped'));
        $this->assertStringContainsString('Server limit reached', $res->json('data.results.1.reason'));
    }

    public function test_unknown_devices_are_reported_not_invented(): void
    {
        $id = $this->connection();
        $res = $this->import($id, ['ghost']);
        $this->assertSame('skipped', $res->json('data.results.0.outcome'));
        $this->assertStringContainsString('not found on the platform', $res->json('data.results.0.reason'));
    }

    public function test_import_is_admin_only_company_scoped_and_validated(): void
    {
        $id = $this->connection();
        $body = ['external_ids' => ['m1'], 'os_type' => 'linux'];
        $this->assertSame(403, $this->tech->post("/api/integrations/$id/import", $body)->status());
        $this->assertSame(404, $this->rival->post("/api/integrations/$id/import", $body)->status());
        $this->assertSame(400, $this->import($id, ['m1'], ['os_type' => 'beos'])->status());
        $this->assertSame(400, $this->import($id, [])->status());
        $this->assertSame(400, $this->import($id, array_map('strval', range(1, 501)))->status());
        $this->assertSame(0, DB::table('server_links')->count());
    }

    public function test_an_import_is_audited(): void
    {
        $id = $this->connection();
        $this->import($id, ['m1', 'm2']);
        $entry = collect($this->admin->get('/api/audit?action=integration_import')->json('data'))->first();
        $this->assertSame(2, $entry['details']['created']);
        $this->assertCount(2, $entry['details']['server_ids']);
    }

    public function test_device_names_become_valid_hostnames(): void
    {
        $this->assertSame('Server-01-prod', IntegrationImport::hostnameFor('Server 01 (prod)'));
        $this->assertSame('a.b_c-d', IntegrationImport::hostnameFor('a.b_c-d'));
        $this->assertNull(IntegrationImport::hostnameFor('!!!'));
        $this->assertNull(IntegrationImport::hostnameFor('   '));
    }
}
