<?php

namespace Tests\Feature;

use App\Services\Engine\Engine;
use App\Services\Integrations\IntegrationSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\ApiClient;
use Tests\TestCase;

/** Hands-off population: each sync adds protected devices as servers, when the connection has auto-import on. */
class AutoImportTest extends TestCase
{
    private const DC = 'https://eu2-cloud.acronis.com';

    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $rival;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->companyId = $this->makeCompany('autoco');
        $rivalCompany = $this->makeCompany('rivalauto');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($rivalCompany, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->rival = $this->login('rival');
    }

    private function recent(int $hours): string
    {
        return CarbonImmutable::now()->subHours($hours)->toIso8601ZuluString();
    }

    private function machine(string $id, string $name): array
    {
        return [
            'aggregate' => ['names' => ['Daily plan'], 'status' => 'idle'],
            'context' => ['id' => $id, 'name' => $name, 'type' => 'resource.machine'],
            'policies' => [['type' => 'policy.backup.machine', 'last_run' => $this->recent(2), 'last_success_run' => $this->recent(2)]],
        ];
    }

    private function unprotected(string $id, string $name): array
    {
        return ['aggregate' => ['names' => [], 'status' => 'not_protected'], 'context' => ['id' => $id, 'name' => $name, 'type' => 'resource.machine'], 'policies' => []];
    }

    private function console(array $items): void
    {
        Http::swap(new HttpFactory);
        Http::fake([
            self::DC.'/api/2/idp/token' => Http::response(['access_token' => 'tok', 'expires_on' => time() + 3600], 200),
            self::DC.'/api/resource_management/v4/resource_statuses*' => Http::response(['items' => $items], 200),
        ]);
    }

    private function connect(array $over = []): int
    {
        return $this->admin->post('/api/integrations', $over + ['platform' => 'acronis', 'name' => 'Acronis EU2', 'base_url' => self::DC, 'client_id' => 'cid-1234', 'client_secret' => 'secret-value'])->json('data.id');
    }

    private function sync(int $id): void
    {
        $this->admin->post("/api/integrations/$id/sync");
    }

    public function test_it_is_off_by_default_and_nothing_is_imported(): void
    {
        $this->console([$this->machine('m1', 'APP-01')]);
        $id = $this->connect();
        $this->sync($id);
        $this->assertFalse($this->admin->get('/api/integrations')->json('data.0.auto_import'));
        $this->assertSame(0, DB::table('servers')->count());
    }

    public function test_when_on_a_sync_adds_protected_devices_as_servers_with_their_status(): void
    {
        $this->console([$this->machine('m1', 'APP-01'), $this->machine('m2', 'APP-02'), $this->unprotected('m3', 'laptop-xyz')]);
        $id = $this->connect(['auto_import' => true, 'auto_import_os' => 'linux']);
        $this->sync($id);
        $this->assertSame(['APP-01', 'APP-02'], DB::table('servers')->orderBy('hostname')->pluck('hostname')->all(), 'protected devices only');
        $this->assertSame('linux', DB::table('servers')->value('os_type'), 'the OS the admin chose');
        $this->assertSame(2, DB::table('server_links')->count());
        $this->assertSame(['success', 'success'], DB::table('servers')->pluck('last_backup_status')->all(), 'status read in the same sync');
    }

    public function test_later_syncs_add_only_new_devices(): void
    {
        $this->console([$this->machine('m1', 'APP-01')]);
        $id = $this->connect(['auto_import' => true]);
        $this->sync($id);
        $this->sync($id);
        $this->assertSame(1, DB::table('servers')->count());
        $this->console([$this->machine('m1', 'APP-01'), $this->machine('m2', 'NEW-02')]);
        $this->sync($id);
        $this->assertSame(['APP-01', 'NEW-02'], DB::table('servers')->orderBy('hostname')->pluck('hostname')->all());
    }

    public function test_an_existing_server_with_the_same_name_is_left_alone(): void
    {
        $existing = $this->admin->post('/api/servers', ['hostname' => 'app-01', 'os_type' => 'windows', 'backup_methods' => ['rsync']])->json('data.id');
        $this->console([$this->machine('m1', 'APP-01')]);
        $id = $this->connect(['auto_import' => true]);
        $this->sync($id);
        $this->assertSame(1, DB::table('servers')->count());
        $this->assertSame(0, DB::table('server_links')->where('server_id', $existing)->count(), 'never auto-linked to something it did not create');
        $this->assertSame(0, DB::table('notifications')->where('event_type', 'servers_imported')->count(), 'nothing added, nothing announced');
    }

    public function test_one_sync_adds_at_most_the_configured_number(): void
    {
        config(['bms.integration_auto_import_max_per_sync' => 2]);
        $this->console(array_map(fn ($n) => $this->machine("m$n", "BULK-0$n"), range(1, 5)));
        $id = $this->connect(['auto_import' => true]);
        $this->sync($id);
        $this->assertSame(2, DB::table('servers')->count());
        $this->sync($id);
        $this->assertSame(4, DB::table('servers')->count(), 'the rest follow on later syncs');
    }

    public function test_the_subscription_limit_is_respected_and_repeats_stay_quiet(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['max_servers' => 1]);
        $this->console([$this->machine('m1', 'APP-01'), $this->machine('m2', 'APP-02')]);
        $id = $this->connect(['auto_import' => true]);
        $this->sync($id);
        $this->sync($id);
        $this->assertSame(1, DB::table('servers')->count());
        $this->assertSame(1, DB::table('notifications')->where('event_type', 'servers_imported')->count(), 'announced once, not on every sync');
    }

    public function test_admins_are_told_what_was_added_and_it_is_audited_as_automatic(): void
    {
        $this->console([$this->machine('m1', 'APP-01'), $this->machine('m2', 'APP-02')]);
        $id = $this->connect(['auto_import' => true]);
        $this->sync($id);
        $note = DB::table('notifications')->where('event_type', 'servers_imported')->first();
        $this->assertStringContainsString('APP-01', $note->message);
        $this->assertStringContainsString('2 new server(s)', $note->subject);
        $entry = DB::table('audit_trail')->where('action', 'integration_auto_import')->first();
        $this->assertNull($entry->user_id, 'done by the system, not a person');
        $this->assertSame($this->companyId, (int) $entry->company_id);
    }

    public function test_it_also_runs_from_the_engine(): void
    {
        $this->console([$this->machine('m1', 'APP-01')]);
        $id = $this->connect(['auto_import' => true]);
        DB::table('integrations')->where('id', $id)->update(['last_sync_at' => now()->subHour()]);
        $this->assertSame(1, Engine::tick()['synced']);
        $this->assertSame(1, DB::table('servers')->count());
        $this->assertSame(0, IntegrationSync::runDue($this->companyId, 15), 'and not again until due');
    }

    public function test_turning_it_on_later_is_an_admin_only_audited_change(): void
    {
        $this->console([$this->machine('m1', 'APP-01')]);
        $id = $this->connect();
        $this->assertSame(403, $this->tech->patch("/api/integrations/$id", ['auto_import' => true])->status());
        $this->assertSame(404, $this->rival->patch("/api/integrations/$id", ['auto_import' => true])->status());
        $this->assertSame(400, $this->admin->patch("/api/integrations/$id", ['auto_import_os' => 'beos'])->status());
        $this->assertSame(400, $this->admin->patch("/api/integrations/$id", [])->status());
        $res = $this->admin->patch("/api/integrations/$id", ['auto_import' => true, 'auto_import_os' => 'linux']);
        $this->assertTrue($res->json('data.auto_import'));
        $this->assertSame('linux', $res->json('data.auto_import_os'));
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'integration_updated')->count());
    }
}
