<?php

namespace Tests\Feature;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** One dashboard across every company, for platform admins: counts only, those needing attention first. */
class PlatformDashboardTest extends TestCase
{
    private ApiClient $platform;

    private ApiClient $acmeAdmin;

    private int $acme;

    private int $zenith;

    protected function setUp(): void
    {
        parent::setUp();
        $root = $this->makeCompany('root');
        $this->acme = $this->makeCompany('acme');
        $this->zenith = $this->makeCompany('zenith');
        $rid = $this->makeUser($root, 'root', 'company_admin');
        DB::table('users')->where('id', $rid)->update(['is_platform_admin' => 1]);
        $this->makeUser($this->acme, 'acmeadmin', 'company_admin');
        $this->platform = $this->login('root');
        $this->acmeAdmin = $this->login('acmeadmin');
    }

    private function server(int $company, string $host, ?string $status = null): int
    {
        return DB::table('servers')->insertGetId(['company_id' => $company, 'hostname' => $host, 'os_type' => 'linux', 'criticality' => 'standard', 'backup_methods' => '["rsync"]', 'active' => 1,
            'last_backup_status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function job(int $company, int $server, string $status): int
    {
        return DB::table('backup_jobs')->insertGetId(['company_id' => $company, 'server_id' => $server, 'backup_method' => 'rsync', 'status' => $status, 'completed_time' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function rows(): Collection
    {
        return collect($this->platform->get('/api/platform/dashboard')->assertOk()->json('data.companies'))->keyBy('slug');
    }

    public function test_only_a_platform_admin_can_see_it(): void
    {
        $this->acmeAdmin->get('/api/platform/dashboard')->assertStatus(403);
    }

    public function test_every_active_company_is_listed_with_its_own_figures(): void
    {
        $s1 = $this->server($this->acme, 'acme-web', 'failed');
        $s2 = $this->server($this->acme, 'acme-db', 'success');
        foreach (['success', 'success', 'failed'] as $st) {
            $this->job($this->acme, $s1, $st);
        }
        $this->job($this->zenith, $this->server($this->zenith, 'zen-1', 'success'), 'success');

        $rows = $this->rows();
        $this->assertSame(['acme', 'root', 'zenith'], $rows->keys()->sort()->values()->all());
        $a = $rows['acme'];
        $this->assertSame([2, 1], [$a['servers']['total'], $a['servers']['failing']]);
        $this->assertSame([3, 1], [$a['backups_30d']['finished'], $a['backups_30d']['failed']]);
        $this->assertSame(66.7, $a['backups_30d']['success_rate_pct']);
        $z = $rows['zenith'];
        $this->assertEquals([1, 0, 100], [$z['servers']['total'], $z['servers']['failing'], $z['backups_30d']['success_rate_pct']]);
        $this->assertNotNull($s2);
    }

    public function test_a_company_with_problems_is_flagged_with_the_reasons_and_listed_first(): void
    {
        $bad = $this->server($this->acme, 'acme-web', 'failed');
        $this->job($this->acme, $bad, 'failed');
        DB::table('backup_failures')->insert(['company_id' => $this->acme, 'server_id' => $bad, 'source' => 'acronis', 'backup_method' => 'acronis', 'kind' => 'failed', 'severity' => 'high', 'message' => 'x', 'active' => 1, 'resolved' => 0,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->job($this->zenith, $this->server($this->zenith, 'zen-1', 'success'), 'success');

        $list = $this->platform->get('/api/platform/dashboard')->json('data.companies');
        $this->assertSame('acme', $list[0]['slug'], 'the company that needs attention leads');
        $this->assertTrue($list[0]['attention']);
        $this->assertContains('1 server failing', $list[0]['attention_reasons']);
        $this->assertContains('1 serious open failure', $list[0]['attention_reasons']);
        $this->assertFalse(collect($list)->firstWhere('slug', 'zenith')['attention']);
        $totals = $this->platform->get('/api/platform/dashboard')->json('data.totals');
        $this->assertSame([3, 1, 1], [$totals['companies'], $totals['needing_attention'], $totals['open_backup_problems']]);
    }

    public function test_it_gives_counts_and_never_a_name_or_a_failure_text_from_inside_a_company(): void
    {
        $s = $this->server($this->acme, 'secret-hostname-01', 'failed');
        DB::table('backup_failures')->insert(['company_id' => $this->acme, 'server_id' => $s, 'source' => 'acronis', 'backup_method' => 'acronis', 'kind' => 'failed', 'severity' => 'high', 'message' => 'confidential detail',
            'active' => 1, 'resolved' => 0, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $body = $this->platform->get('/api/platform/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('secret-hostname-01', $body);
        $this->assertStringNotContainsString('confidential detail', $body);
    }

    public function test_a_disabled_company_is_left_out_and_a_company_with_nothing_is_not_flagged(): void
    {
        DB::table('companies')->where('id', $this->zenith)->update(['is_active' => 0]);
        $rows = $this->rows();
        $this->assertFalse($rows->has('zenith'));
        $this->assertFalse($rows['acme']['attention'], 'no servers, nothing to be wrong');
        $this->assertNull($rows['acme']['health_score']);
    }

    public function test_it_does_not_leave_the_platform_admin_working_inside_a_company(): void
    {
        $this->platform->get('/api/platform/dashboard')->assertOk();
        $me = $this->platform->get('/api/me')->json();
        $this->assertFalse((bool) ($me['acting_in_other_company'] ?? false));
    }
}
