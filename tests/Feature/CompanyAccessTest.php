<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Letting a user work in other companies, each with its own role, and the platform-admin switch. */
class CompanyAccessTest extends TestCase
{
    private ApiClient $root;   // platform admin, home company A

    private ApiClient $msp;    // technician in A, granted access to B

    private ApiClient $bAdmin;

    private ApiClient $cAdmin;

    private int $a;

    private int $b;

    private int $c;

    private int $mspId;

    private int $rootId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->makeCompany('ops');
        $this->b = $this->makeCompany('custb');
        $this->c = $this->makeCompany('custc');
        $this->rootId = $this->makeUser($this->a, 'root', 'company_admin');
        DB::table('users')->where('id', $this->rootId)->update(['is_platform_admin' => 1]);
        $this->mspId = $this->makeUser($this->a, 'msp', 'company_technician');
        $this->makeUser($this->b, 'badmin', 'company_admin');
        $this->makeUser($this->c, 'cadmin', 'company_admin');
        $this->root = $this->login('root');
        $this->msp = $this->login('msp');
        $this->bAdmin = $this->login('badmin');
        $this->cAdmin = $this->login('cadmin');
    }

    private function grant(int $userId, array $grants)
    {
        return $this->root->put("/api/platform/users/$userId/access", ['grants' => $grants]);
    }

    private function inB(string $role = 'company_technician'): ApiClient
    {
        $this->assertSame(200, $this->grant($this->mspId, [['company_id' => $this->b, 'role' => $role]])->status());

        return $this->msp->inCompany($this->b);
    }

    // --- granting ---

    public function test_a_platform_admin_grants_access_and_the_user_can_see_it(): void
    {
        $this->inB();
        $access = $this->msp->get('/api/me')->json('data.access');
        $this->assertSame([['id' => $this->b, 'name' => 'CUSTB', 'slug' => 'custb', 'role' => 'company_technician']], $access);
        $this->assertSame('company_technician', $this->msp->get('/api/me')->json('data.role'));
        $this->assertSame('ops', $this->msp->get('/api/me')->json('data.company_slug'), 'still starts in the home company');
    }

    public function test_the_granted_role_is_the_one_that_counts_inside_the_company(): void
    {
        $tech = $this->inB('company_technician');
        $this->assertSame(200, $tech->get('/api/servers')->status());
        $this->assertSame(403, $tech->post('/api/servers', ['hostname' => 'x', 'os_type' => 'linux'])->status(), 'a technician cannot add servers');
        $this->assertSame(403, $tech->get('/api/users')->status());

        $mgr = $this->inB('company_manager');
        $this->assertSame(200, $mgr->get('/api/reports')->status(), 'a manager can read reports');
        $this->assertSame(403, $mgr->post('/api/servers', ['hostname' => 'x', 'os_type' => 'linux'])->status());

        $admin = $this->inB('company_admin');
        $this->assertSame(201, $admin->post('/api/servers', ['hostname' => 'made-by-msp', 'os_type' => 'linux'])->status());
        $this->assertSame(1, DB::table('servers')->where('company_id', $this->b)->where('hostname', 'made-by-msp')->count());
        $this->assertSame(0, DB::table('servers')->where('company_id', $this->a)->count(), 'nothing lands in the home company');
    }

    public function test_the_role_is_per_request_and_never_saved_to_the_account(): void
    {
        $this->inB('company_admin');
        $this->msp->inCompany($this->b)->get('/api/me');
        $this->assertSame('company_technician', DB::table('users')->where('id', $this->mspId)->value('role'));
        $this->assertSame('company_technician', $this->msp->get('/api/me')->json('data.role'), 'back to the home role without the header');
        $this->assertSame(403, $this->msp->get('/api/users')->status(), 'and home permissions apply again');
    }

    public function test_no_grant_means_no_entry_and_a_grant_for_one_company_is_not_for_another(): void
    {
        $this->assertSame(403, $this->msp->inCompany($this->b)->get('/api/servers')->status());
        $this->inB();
        $this->assertSame(403, $this->msp->inCompany($this->c)->get('/api/servers')->status());
        $this->assertSame(404, $this->msp->inCompany(99999)->get('/api/servers')->status());
        $this->assertSame(200, $this->msp->inCompany($this->b)->get('/api/servers')->status());
    }

    public function test_a_granted_technician_sees_only_servers_assigned_to_them(): void
    {
        $server = $this->bAdmin->post('/api/servers', ['hostname' => 'b-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->bAdmin->post('/api/servers', ['hostname' => 'b-02', 'os_type' => 'linux']);
        $tech = $this->inB('company_technician');
        $this->assertSame([], $tech->get('/api/servers')->json('data'));
        $this->assertSame(200, $this->bAdmin->put("/api/servers/$server/assignments", ['user_ids' => [$this->mspId]])->status(), 'the company\'s admin can assign a granted technician');
        $this->assertSame(['b-01'], collect($tech->get('/api/servers')->json('data'))->pluck('hostname')->all());
    }

    public function test_only_granted_technicians_can_be_assigned_not_other_roles(): void
    {
        $server = $this->bAdmin->post('/api/servers', ['hostname' => 'b-01', 'os_type' => 'linux'])->json('data.id');
        $this->inB('company_manager');
        $this->assertSame(400, $this->bAdmin->put("/api/servers/$server/assignments", ['user_ids' => [$this->mspId]])->status());
    }

    public function test_the_company_sees_who_has_access_but_cannot_edit_them(): void
    {
        $this->inB('company_manager');
        $users = collect($this->bAdmin->get('/api/users')->json('data'));
        $msp = $users->firstWhere('username', 'msp');
        $this->assertTrue($msp['granted_access']);
        $this->assertSame('company_manager', $msp['role']);
        $this->assertNull($msp['email'], 'an outsider\'s contact details are not shown');
        $this->assertSame(404, $this->bAdmin->patch("/api/users/{$this->mspId}", ['role' => 'company_admin'])->status(), 'not theirs to edit');
        $this->assertSame('company_technician', DB::table('users')->where('id', $this->mspId)->value('role'));
    }

    // --- audit ---

    public function test_grants_and_entries_are_recorded_in_the_target_companys_own_log(): void
    {
        $tech = $this->inB('company_technician');
        $this->grant($this->mspId, [['company_id' => $this->b, 'role' => 'company_manager']]);
        $this->assertSame(200, $this->msp->post("/api/me/companies/{$this->b}/enter")->status());
        $tech->get('/api/servers');
        $actions = collect($this->bAdmin->get('/api/audit?limit=100')->json('data'));
        foreach (['access_granted', 'access_role_changed', 'company_access_entered'] as $a) {
            $this->assertNotNull($actions->firstWhere('action', $a), $a);
        }
        $this->assertSame('root', $actions->firstWhere('action', 'access_granted')['username']);
        $this->assertSame('msp', $actions->firstWhere('action', 'company_access_entered')['username']);
        $this->assertSame('company_manager', $actions->firstWhere('action', 'access_role_changed')['details']['role']);
    }

    public function test_changes_made_through_a_grant_say_so(): void
    {
        $admin = $this->inB('company_admin');
        $admin->post('/api/servers', ['hostname' => 'b-via-grant', 'os_type' => 'linux']);
        $row = collect($this->bAdmin->get('/api/audit?action=server_created')->json('data'))->first();
        $this->assertSame('msp', $row['username']);
        $this->assertTrue($row['details']['via_access_grant']);
        $this->assertArrayNotHasKey('via_platform_admin', $row['details']);
    }

    // --- revoking, validation, who may do it ---

    public function test_revoking_takes_effect_immediately_and_is_logged(): void
    {
        $tech = $this->inB();
        $this->assertSame(200, $tech->get('/api/servers')->status());
        $this->grant($this->mspId, []);
        $this->assertSame(403, $tech->get('/api/servers')->status());
        $this->assertSame([], $this->msp->get('/api/me')->json('data.access'));
        $this->assertNotNull(collect($this->bAdmin->get('/api/audit?limit=100')->json('data'))->firstWhere('action', 'access_revoked'));
    }

    public function test_a_disabled_company_or_user_cannot_be_entered(): void
    {
        $tech = $this->inB();
        DB::table('companies')->where('id', $this->b)->update(['is_active' => 0]);
        $this->assertSame(403, $tech->get('/api/servers')->status());
        $this->assertSame(403, $this->msp->post("/api/me/companies/{$this->b}/enter")->status());
        $this->assertSame([], $this->msp->get('/api/me')->json('data.access'), 'a disabled company is not offered');
        DB::table('companies')->where('id', $this->b)->update(['is_active' => 1]);
        DB::table('users')->where('id', $this->mspId)->update(['active' => 0]);
        $this->assertSame(401, $tech->get('/api/servers')->status());
    }

    public function test_grants_are_validated(): void
    {
        $this->assertSame(400, $this->grant($this->mspId, [['company_id' => $this->b, 'role' => 'company_admin'], ['company_id' => $this->b, 'role' => 'company_manager']])->status(), 'listed twice');
        $this->assertSame(400, $this->grant($this->mspId, [['company_id' => $this->a, 'role' => 'company_admin']])->status(), 'their own company');
        $this->assertSame(404, $this->grant($this->mspId, [['company_id' => 99999, 'role' => 'company_admin']])->status());
        $this->assertSame(400, $this->grant($this->mspId, [['company_id' => $this->b, 'role' => 'superuser']])->status());
        $this->assertSame(404, $this->grant(99999, [])->status());
        $this->assertSame(400, $this->grant($this->rootId, [['company_id' => $this->b, 'role' => 'company_admin']])->status(), 'a platform admin already has everything');
        $this->assertSame(0, DB::table('company_access')->count());
    }

    public function test_only_platform_admins_can_grant_or_list_people_across_companies(): void
    {
        $body = ['grants' => [['company_id' => $this->b, 'role' => 'company_admin']]];
        foreach ([$this->bAdmin, $this->msp, $this->cAdmin] as $client) {
            $this->assertSame(403, $client->put("/api/platform/users/{$this->mspId}/access", $body)->status());
            $this->assertSame(403, $client->get('/api/platform/users')->status());
            $this->assertSame(403, $client->patch("/api/platform/users/{$this->mspId}/platform-admin", ['is_platform_admin' => true])->status());
        }
        $this->assertSame(0, DB::table('company_access')->count());
    }

    public function test_a_company_admin_cannot_use_a_grant_to_reach_other_companies_or_platform_routes(): void
    {
        $admin = $this->inB('company_admin');
        $this->assertSame(403, $admin->get('/api/platform/users')->status());
        $this->assertSame(403, $this->msp->inCompany($this->c)->get('/api/servers')->status());
    }

    public function test_the_platform_user_list_spans_companies_and_shows_access(): void
    {
        $this->inB('company_manager');
        $all = $this->root->get('/api/platform/users');
        $this->assertSame(200, $all->status());
        $by = array_column($all->json('data'), null, 'username');
        $this->assertSame('ops', $by['msp']['company']['slug']);
        $this->assertSame([['company_id' => $this->b, 'company' => 'CUSTB', 'role' => 'company_manager']], $by['msp']['access']);
        $this->assertArrayHasKey('badmin', $by);
        $this->assertStringNotContainsString('password', $all->getContent());
        $this->assertSame(['msp'], array_column($this->root->get('/api/platform/users?q=ms')->json('data'), 'username'));
        $this->assertSame(['badmin'], array_column($this->root->get("/api/platform/users?company_id={$this->b}")->json('data'), 'username'));
    }

    // --- the platform-admin switch ---

    public function test_a_platform_admin_can_make_a_company_admin_a_platform_admin_and_it_is_logged(): void
    {
        $res = $this->root->patch("/api/platform/users/{$this->bAdminId()}/platform-admin", ['is_platform_admin' => true]);
        $this->assertSame(200, $res->status());
        $this->assertTrue($res->json('data.is_platform_admin'));
        $b = $this->login('badmin');
        $this->assertSame(200, $b->inCompany($this->c)->get('/api/servers')->status(), 'now reaches every company');
        $this->assertNotNull(collect($this->bAdmin->get('/api/audit?limit=100')->json('data'))->firstWhere('action', 'platform_admin_granted'));
    }

    private function bAdminId(): int
    {
        return (int) DB::table('users')->where('username', 'badmin')->value('id');
    }

    public function test_only_active_company_admins_can_become_platform_admins(): void
    {
        $this->assertSame(400, $this->root->patch("/api/platform/users/{$this->mspId}/platform-admin", ['is_platform_admin' => true])->status(), 'a technician');
        DB::table('users')->where('id', $this->bAdminId())->update(['active' => 0]);
        $this->assertSame(400, $this->root->patch("/api/platform/users/{$this->bAdminId()}/platform-admin", ['is_platform_admin' => true])->status(), 'a disabled admin');
    }

    public function test_you_cannot_change_your_own_status_but_one_platform_admin_can_remove_another(): void
    {
        $this->assertSame(400, $this->root->patch("/api/platform/users/{$this->rootId}/platform-admin", ['is_platform_admin' => false])->status());
        $other = $this->bAdminId();
        $this->root->patch("/api/platform/users/$other/platform-admin", ['is_platform_admin' => true]);
        $this->assertSame(200, $this->root->patch("/api/platform/users/$other/platform-admin", ['is_platform_admin' => false])->status());
        $this->assertSame(0, (int) DB::table('users')->where('id', $other)->value('is_platform_admin'));
        $this->assertSame(1, DB::table('users')->where('is_platform_admin', 1)->where('active', 1)->count(), 'the caller is always left');
        $this->assertNotNull(collect($this->bAdmin->get('/api/audit?limit=100')->json('data'))->firstWhere('action', 'platform_admin_revoked'));
    }

    public function test_making_someone_a_platform_admin_clears_their_per_company_grants(): void
    {
        $id = $this->bAdminId();
        $this->grant($id, [['company_id' => $this->c, 'role' => 'company_manager']]);
        $this->assertSame(1, DB::table('company_access')->where('user_id', $id)->count());
        $this->root->patch("/api/platform/users/$id/platform-admin", ['is_platform_admin' => true]);
        $this->assertSame(0, DB::table('company_access')->where('user_id', $id)->count(), 'redundant once they can reach everything');
    }
}
