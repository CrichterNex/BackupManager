<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\ApiClient;
use Tests\TestCase;

/** Virtual machines Acronis protects without an agent are devices too: listed, imported and read like machines. */
class VmDeviceTest extends TestCase
{
    private const DC = 'https://eu2-cloud.acronis.com';

    private ApiClient $admin;

    private int $companyId;

    private int $integration;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->companyId = $this->makeCompany('vmco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
    }

    private function item(string $id, string $name, string $type, ?string $plan, string $status = 'idle', ?string $ok = null): array
    {
        $ok ??= now()->subHours(3)->toIso8601ZuluString();

        return ['aggregate' => $plan ? ['status' => $status, 'names' => $plan] : ['status' => 'no_policies_applied'],
            'context' => ['id' => $id, 'external_id' => "ext-$id", 'name' => $name, 'user_defined_name' => null, 'tenant_id' => 't1', 'type' => $type],
            'policies' => $plan ? [['type' => 'policy.backup.machine', 'last_run' => $ok, 'last_success_run' => $ok]] : []];
    }

    /** Answers by the page asked for, so the same list comes back however many times it is read. */
    private function fake(array $items, array $pages = []): void
    {
        $pages = $pages ?: [$items];
        Http::swap(new HttpFactory);
        Http::fake([
            self::DC.'/api/2/idp/token' => Http::response(['access_token' => 'tok', 'expires_on' => time() + 3600, 'token_type' => 'bearer'], 200),
            self::DC.'/api/resource_management/v4/resource_statuses*' => function ($request) use ($pages) {
                $n = (int) str_replace('c', '', (string) ($request->data()['after'] ?? 'c-1')) + 1;
                $n = isset($request->data()['after']) ? $n : 0;

                return Http::response(['items' => $pages[$n] ?? [], 'paging' => ['cursors' => $n < count($pages) - 1 ? ['after' => 'c'.$n] : []]], 200);
            },
            self::DC.'/api/task_manager/v2/activities*' => Http::response(['items' => [], 'paging' => ['cursors' => []]], 200),
        ]);
    }

    private function connect(array $items, array $pages = []): void
    {
        $this->fake($items, $pages);
        $this->integration = $this->admin->post('/api/integrations', ['platform' => 'acronis', 'name' => 'Acronis EU2', 'base_url' => self::DC, 'client_id' => 'client-1234', 'client_secret' => 'secret-value'])->assertStatus(201)->json('data.id');
    }

    private function devices()
    {
        return collect($this->admin->get("/api/integrations/{$this->integration}/devices")->assertOk()->json('data'))->keyBy('name');
    }

    private const ALL = ['machine', 'vm', 'host', 'group'];

    private function mixed(): array
    {
        return [
            $this->item('g1', 'All devices', 'resource.group.all', null),
            $this->item('h1', 'HYPERV-HOST', 'resource.virtual_host.mshyperv', null),
            $this->item('v1', 'VM-APP01', 'resource.virtual_machine.mshyperv', 'Full Machine'),
            $this->item('v2', 'VM-IDLE02', 'resource.virtual_machine.mshyperv', null),
            $this->item('m1', 'SRV-FILE01', 'resource.machine', 'Full Machine'),
        ];
    }

    public function test_virtual_machines_are_listed_with_the_machines_and_hosts_and_groups_are_not(): void
    {
        $this->connect($this->mixed());
        $d = $this->devices();
        $this->assertEqualsCanonicalizing(['SRV-FILE01', 'VM-APP01', 'VM-IDLE02'], $d->keys()->all(), 'no hypervisor host, no group');
        $this->assertSame(['machine', 'vm', 'vm'], [$d['SRV-FILE01']['kind'], $d['VM-APP01']['kind'], $d['VM-IDLE02']['kind']]);
        $this->assertSame([true, false], [$d['VM-APP01']['protected'], $d['VM-IDLE02']['protected']], 'a VM with no plan is not protected, like a machine with none');
        $this->assertSame(['Full Machine'], $d['VM-APP01']['plans']);
    }

    public function test_an_item_that_names_no_type_is_still_read_as_a_machine(): void
    {
        $x = $this->item('m9', 'OLDSTYLE', 'resource.machine', 'Plan');
        unset($x['context']['type']);
        $this->connect([$x]);
        $this->assertSame('machine', $this->devices()['OLDSTYLE']['kind']);
    }

    public function test_every_page_is_read_and_machines_come_first(): void
    {
        $this->connect([], [[$this->item('v1', 'VM-A', 'resource.virtual_machine.mshyperv', 'P')], [$this->item('m1', 'MACH-B', 'resource.machine', 'P')]]);
        $list = collect($this->admin->get("/api/integrations/{$this->integration}/devices")->json('data'));
        $this->assertSame(['MACH-B', 'VM-A'], $list->pluck('name')->sort()->values()->all());
        $this->assertCount(2, $list);
    }

    public function test_a_vm_can_be_imported_and_its_status_is_read_like_a_machines(): void
    {
        $this->connect($this->mixed());
        $r = $this->admin->post("/api/integrations/{$this->integration}/import", ['external_ids' => ['v1'], 'os_type' => 'windows'])->assertOk()->json('data');
        $this->assertSame(1, $r['created']);
        $server = DB::table('servers')->where('hostname', 'VM-APP01')->first();
        $this->assertNotNull($server);
        $this->assertSame('success', $server->last_backup_status, 'the sync read the VM\'s backup, so it shows its status straight away');
        $this->assertSame('v1', DB::table('server_links')->where('server_id', $server->id)->value('external_id'));
    }

    public function test_a_vm_with_the_same_name_as_a_machine_does_not_make_a_second_server(): void
    {
        $this->connect([$this->item('m1', 'SRV-DUP', 'resource.machine', 'P'), $this->item('v1', 'SRV-DUP', 'resource.virtual_machine.mshyperv', 'P')]);
        $r = $this->admin->post("/api/integrations/{$this->integration}/import", ['external_ids' => ['v1', 'm1'], 'os_type' => 'windows'])->assertOk()->json('data');
        $this->assertSame(1, DB::table('servers')->where('hostname', 'SRV-DUP')->count());
        $this->assertSame(1, $r['created'] + $r['linked_existing'], 'one of the two is linked, the other is skipped with a reason');
        $this->assertSame(1, $r['skipped']);
    }

    public function test_the_automatic_import_brings_in_protected_vms_and_skips_unprotected_ones(): void
    {
        $this->connect($this->mixed());
        $this->admin->patch("/api/integrations/{$this->integration}", ['auto_import' => true, 'auto_import_os' => 'windows'])->assertOk();
        $this->admin->post("/api/integrations/{$this->integration}/sync")->assertOk();
        $names = DB::table('servers')->pluck('hostname')->sort()->values()->all();
        $this->assertSame(['SRV-FILE01', 'VM-APP01'], $names, 'the VM with no plan and the host are not imported');
    }
}
