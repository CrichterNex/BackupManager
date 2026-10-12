<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    private ApiClient $platform;

    private ApiClient $admin;

    private int $homeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeId = $this->makeCompany('operator');
        $this->makeUser($this->homeId, 'root', 'company_admin');
        DB::table('users')->where('username', 'root')->update(['is_platform_admin' => 1]);
        $other = $this->makeCompany('customer');
        $this->makeUser($other, 'custadmin', 'company_admin');
        $this->platform = $this->login('root');
        $this->admin = $this->login('custadmin');
    }

    private function body(array $over = []): array
    {
        return $over + ['name' => 'Fresh Co', 'slug' => 'fresh-co', 'admin_username' => 'freshadmin', 'admin_email' => 'a@fresh.example'];
    }

    public function test_a_platform_admin_creates_a_company_with_its_first_admin(): void
    {
        $res = $this->platform->post('/api/platform/companies', $this->body(['subscription_tier' => 'pro', 'max_servers' => 5]));
        $this->assertSame(201, $res->status());
        $this->assertSame('fresh-co', $res->json('data.slug'));
        $password = $res->json('admin.temporary_password');
        $this->assertGreaterThanOrEqual(12, strlen($password));

        // The new admin can sign in, lands in the new company, and sees nothing of anyone else's.
        $new = $this->login('freshadmin', $password);
        $this->assertSame('fresh-co', $new->get('/api/me')->json('data.company_slug'));
        $this->assertSame([], $new->get('/api/servers')->json('data'));
        $this->assertSame(['freshadmin'], collect($new->get('/api/users')->json('data'))->pluck('username')->all());
        $this->assertSame(403, $new->get('/api/platform/companies')->status(), 'the new admin is not a platform admin');
    }

    public function test_an_admin_supplied_password_is_used_and_not_echoed_back(): void
    {
        $res = $this->platform->post('/api/platform/companies', $this->body(['admin_password' => 'chosen-password-123']));
        $this->assertSame(201, $res->status());
        $this->assertNull($res->json('admin.temporary_password'));
        $this->assertStringNotContainsString('chosen-password-123', $res->getContent());
        $this->assertSame(200, $this->postJson('/api/auth/login', ['username' => 'freshadmin', 'password' => 'chosen-password-123'])->status());
    }

    public function test_ordinary_company_admins_cannot_use_the_platform_routes(): void
    {
        $this->assertSame(403, $this->admin->get('/api/platform/companies')->status());
        $this->assertSame(403, $this->admin->post('/api/platform/companies', $this->body())->status());
        $this->assertSame(0, DB::table('companies')->where('slug', 'fresh-co')->count());
    }

    public function test_the_flag_cannot_be_set_through_the_users_api(): void
    {
        $me = $this->admin->get('/api/me')->json('data.id');
        $this->admin->patch("/api/users/$me", ['is_platform_admin' => true, 'email' => 'x@y.io']);
        $this->admin->post('/api/users', ['username' => 'sneaky', 'email' => 's@x.io', 'role' => 'company_admin', 'password' => 'a-long-password-1', 'is_platform_admin' => true]);
        $this->assertSame(0, DB::table('users')->where('username', 'custadmin')->value('is_platform_admin'));
        $this->assertSame(0, DB::table('users')->where('username', 'sneaky')->value('is_platform_admin'));
    }

    public function test_a_platform_admin_demoted_to_manager_loses_the_access(): void
    {
        $id = DB::table('users')->where('username', 'root')->value('id');
        DB::table('users')->where('id', $id)->update(['role' => 'company_manager']);
        $this->assertSame(403, $this->platform->get('/api/platform/companies')->status());
    }

    public function test_bad_and_duplicate_input_is_refused(): void
    {
        $this->assertSame(400, $this->platform->post('/api/platform/companies', $this->body(['slug' => 'Bad Slug!']))->status());
        $this->assertSame(400, $this->platform->post('/api/platform/companies', $this->body(['admin_password' => 'short']))->status());
        $this->assertSame(400, $this->platform->post('/api/platform/companies', $this->body(['subscription_tier' => 'platinum']))->status());
        $this->assertSame(201, $this->platform->post('/api/platform/companies', $this->body())->status());
        $this->assertSame(409, $this->platform->post('/api/platform/companies', $this->body(['admin_username' => 'other']))->status(), 'duplicate slug');
        $this->assertSame(409, $this->platform->post('/api/platform/companies', $this->body(['slug' => 'second-co', 'admin_username' => 'FRESHADMIN']))->status(), 'duplicate username');
        $this->assertSame(0, DB::table('companies')->where('slug', 'second-co')->count(), 'nothing half-created');
    }

    public function test_the_list_shows_counts_but_no_company_data(): void
    {
        $this->admin->post('/api/servers', ['hostname' => 'cust-01', 'os_type' => 'linux']);
        $res = $this->platform->get('/api/platform/companies');
        $this->assertSame(200, $res->status());
        $cust = collect($res->json('data'))->firstWhere('slug', 'customer');
        $this->assertSame(1, $cust['servers']);
        $this->assertSame(1, $cust['users']);
        $this->assertStringNotContainsString('cust-01', $res->getContent());
    }

    public function test_creation_is_audited_to_the_creating_admin(): void
    {
        $this->platform->post('/api/platform/companies', $this->body());
        $row = DB::table('audit_trail')->where('action', 'company_created')->first();
        $this->assertSame($this->homeId, $row->company_id);
        $this->assertSame('company', $row->resource_type);
    }

    public function test_me_reports_the_flag_so_the_ui_can_show_the_page(): void
    {
        $this->assertTrue($this->platform->get('/api/me')->json('data.platform_admin'));
        $this->assertFalse($this->admin->get('/api/me')->json('data.platform_admin'));
    }

    // --- working inside other companies ---

    private function customerId(): int
    {
        return (int) DB::table('companies')->where('slug', 'customer')->value('id');
    }

    public function test_by_default_a_platform_admin_sees_only_their_own_company(): void
    {
        $this->admin->post('/api/servers', ['hostname' => 'cust-01', 'os_type' => 'linux']);
        $this->assertSame([], $this->platform->get('/api/servers')->json('data'));
        $this->assertSame('operator', $this->platform->get('/api/me')->json('data.company_slug'));
    }

    public function test_a_platform_admin_can_work_inside_any_company(): void
    {
        $this->admin->post('/api/servers', ['hostname' => 'cust-01', 'os_type' => 'linux']);
        $inside = $this->platform->inCompany($this->customerId());
        $this->assertSame(['cust-01'], collect($inside->get('/api/servers')->json('data'))->pluck('hostname')->all());
        $this->assertSame(['custadmin'], collect($inside->get('/api/users')->json('data'))->pluck('username')->all());
        $me = $inside->get('/api/me')->json('data');
        $this->assertSame('customer', $me['company_slug']);
        $this->assertTrue($me['acting_in_other_company']);
        $this->assertSame('operator', $me['home_company']['slug']);
    }

    public function test_changes_made_inside_land_in_that_company_and_are_audited_there(): void
    {
        $inside = $this->platform->inCompany($this->customerId());
        $this->assertSame(201, $inside->post('/api/servers', ['hostname' => 'made-by-platform', 'os_type' => 'linux'])->status());
        $this->assertSame(1, DB::table('servers')->where('hostname', 'made-by-platform')->where('company_id', $this->customerId())->count());
        $this->assertSame(0, DB::table('servers')->where('hostname', 'made-by-platform')->where('company_id', $this->homeId)->count());

        // The customer's own admin can see who did it, in the customer's own audit log.
        $entry = collect($this->admin->get('/api/audit?action=server_created')->json('data'))->firstWhere('username', 'root');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['details']['via_platform_admin']);
        $this->assertSame($this->homeId, $entry['details']['home_company_id']);
        $this->assertCount(0, collect($this->platform->get('/api/audit?action=server_created')->json('data')), 'not in the home trail');
    }

    public function test_entering_a_company_is_recorded_in_its_audit_log(): void
    {
        $this->assertSame(200, $this->platform->post('/api/platform/companies/'.$this->customerId().'/enter')->status());
        $rows = $this->admin->get('/api/audit?action=platform_access_started')->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('root', $rows[0]['username']);
        $this->platform->post('/api/platform/companies/'.$this->homeId.'/enter');
        $this->assertSame(0, DB::table('audit_trail')->where('action', 'platform_access_started')->where('company_id', $this->homeId)->count(), 'entering your own company is not an event');
        $this->assertSame(404, $this->platform->post('/api/platform/companies/99999/enter')->status());
        $this->assertSame(403, $this->admin->post('/api/platform/companies/'.$this->homeId.'/enter')->status());
    }

    public function test_nobody_else_can_use_the_acting_header_and_it_is_refused_not_ignored(): void
    {
        $this->admin->post('/api/servers', ['hostname' => 'cust-01', 'os_type' => 'linux']);
        $home = (int) DB::table('companies')->where('slug', 'operator')->value('id');
        $this->assertSame(403, $this->admin->inCompany($home)->get('/api/servers')->status());
        $this->assertSame(403, $this->admin->inCompany($home)->get('/api/me')->status());
        $id = DB::table('users')->where('username', 'root')->value('id');
        DB::table('users')->where('id', $id)->update(['role' => 'company_manager']);
        $this->assertSame(403, $this->platform->inCompany($this->customerId())->get('/api/servers')->status());
    }

    public function test_an_unknown_acting_company_is_404_not_a_silent_fallback(): void
    {
        $this->assertSame(404, $this->platform->inCompany(99999)->get('/api/servers')->status());
    }

    public function test_the_context_resets_between_requests(): void
    {
        $this->admin->post('/api/servers', ['hostname' => 'cust-01', 'os_type' => 'linux']);
        $this->assertCount(1, $this->platform->inCompany($this->customerId())->get('/api/servers')->json('data'));
        $this->assertCount(0, $this->platform->get('/api/servers')->json('data'), 'the next request is back in the home company');
    }

    public function test_the_operator_command_grants_and_revokes_the_flag(): void
    {
        $this->artisan('bms:platform-admin custadmin')->expectsOutputToContain('now a platform admin')->assertSuccessful();
        $this->assertSame(1, (int) DB::table('users')->where('username', 'custadmin')->value('is_platform_admin'));
        $this->artisan('bms:platform-admin custadmin --revoke')->assertSuccessful();
        $this->assertSame(0, (int) DB::table('users')->where('username', 'custadmin')->value('is_platform_admin'));
        $this->artisan('bms:platform-admin nobody')->assertFailed();
    }
}
