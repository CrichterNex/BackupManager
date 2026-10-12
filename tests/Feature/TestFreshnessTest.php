<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** A test result is a point in time: a pass only counts as current for a while. */
class TestFreshnessTest extends TestCase
{
    private ApiClient $admin;

    private int $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        $company = $this->makeCompany('freshco');
        $this->makeUser($company, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'fresh-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
    }

    private function state(string $backup, ?string $test, ?int $testDaysAgo): void
    {
        DB::table('servers')->where('id', $this->serverId)->update([
            'last_backup_status' => $backup, 'last_backup_time' => now()->subHour(),
            'last_test_status' => $test, 'last_test_time' => $testDaysAgo === null ? null : now()->subDays($testDaysAgo),
        ]);
    }

    private function healthOf(): string
    {
        return $this->admin->get("/api/servers/{$this->serverId}")->json('data.status');
    }

    public function test_a_recent_pass_is_healthy(): void
    {
        $this->state('success', 'passed', 2);
        $this->assertSame('healthy', $this->healthOf());
    }

    public function test_an_old_pass_is_overdue_not_healthy(): void
    {
        $this->state('success', 'passed', 20);
        $this->assertSame('test_overdue', $this->healthOf(), 'nothing has checked the backup for 20 days');
    }

    public function test_the_limit_is_configurable_and_exact(): void
    {
        $this->state('success', 'passed', 20);
        config(['bms.test_fresh_days' => 30]);
        $this->assertSame('healthy', $this->healthOf());
        config(['bms.test_fresh_days' => 14]);
        $this->state('success', 'passed', 13);
        $this->assertSame('healthy', $this->healthOf());
        $this->state('success', 'passed', 15);
        $this->assertSame('test_overdue', $this->healthOf());
    }

    public function test_a_failure_always_wins_over_age(): void
    {
        $this->state('success', 'failed', 40);
        $this->assertSame('failing', $this->healthOf());
        $this->state('failed', 'passed', 1);
        $this->assertSame('failing', $this->healthOf());
    }

    public function test_never_tested_is_pending_and_the_list_filter_and_sort_agree_with_the_detail(): void
    {
        $this->state('success', null, null);
        $this->assertSame('pending_test', $this->healthOf());
        $this->state('success', 'passed', 30);
        $this->assertSame([$this->serverId], collect($this->admin->get('/api/servers?status=test_overdue')->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->admin->get('/api/servers?status=healthy')->json('data'));
        $this->assertSame(200, $this->admin->get('/api/servers?sort_by=status&sort_order=asc')->status());
        $this->assertSame('test_overdue', $this->admin->get('/api/servers')->json('data.0.status'), 'the list uses the same rule as the detail');
    }
}
