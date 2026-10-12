<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\BackupJob;
use App\Models\BackupTestExecution;
use App\Models\BackupTestFailure;
use App\Models\Report;
use App\Models\Server;
use App\Services\TestResults;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\ApiClient;
use Tests\TestCase;

class IsolationTest extends TestCase
{
    private ApiClient $a;

    private ApiClient $b;

    private int $companyA;

    private int $companyB;

    private int $serverA;

    private int $jobA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyA = $this->makeCompany('alpha');
        $this->companyB = $this->makeCompany('bravo');
        $this->makeUser($this->companyA, 'alice', 'company_admin');
        $this->makeUser($this->companyB, 'bob', 'company_admin');
        $this->a = $this->login('alice');
        $this->b = $this->login('bob');
        $this->serverA = $this->a->post('/api/servers', ['hostname' => 'web-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->jobA = $this->a->post('/api/backup-jobs', ['server_id' => $this->serverA, 'backup_method' => 'rsync', 'schedule' => '0 2 * * *'])->json('data.id');
        $testId = $this->a->post("/api/backup-jobs/{$this->jobA}/test", ['test_type' => 'synthetic_file'])->json('data.id');
        $this->asCompany($this->companyA, fn () => TestResults::record($testId, ['success' => false, 'failure_type' => 'hash_mismatch', 'hash_original' => 'a', 'hash_restored' => 'b']));
    }

    // --- API ---

    public function test_company_b_sees_none_of_company_a_data(): void
    {
        $this->assertSame([], $this->b->get('/api/servers')->json('data'));
        $this->assertSame([], $this->b->get('/api/backup-jobs')->json('data'));
        $this->assertSame([], $this->b->get('/api/tests')->json('data'));
        $this->assertSame([], $this->b->get('/api/test-failures')->json('data'));
        $this->assertTrue(collect($this->b->get('/api/audit')->json('data'))->every(fn ($r) => $r['resource_type'] !== 'server'));
    }

    public function test_direct_access_to_company_a_records_by_id_is_404_for_company_b(): void
    {
        $this->assertSame(404, $this->b->get("/api/servers/{$this->serverA}")->status());
        $this->assertSame(404, $this->b->get("/api/backup-jobs/{$this->jobA}")->status());
        $this->assertSame(404, $this->b->patch("/api/servers/{$this->serverA}", ['notes' => 'x'])->status());
        $this->assertSame(404, $this->b->del("/api/backup-jobs/{$this->jobA}")->status());
        $this->assertSame(404, $this->b->post("/api/backup-jobs/{$this->jobA}/test")->status());
        $failureId = BackupTestFailure::withoutGlobalScopes()->value('id');
        $this->assertSame(404, $this->b->post("/api/test-failures/$failureId/resolve", ['resolution_type' => 'known_issue', 'backup_usable' => true, 'notes' => 'x'])->status());
        $this->assertFalse((bool) DB::table('backup_test_failures')->where('id', $failureId)->value('resolved'));
    }

    public function test_cannot_create_a_job_on_another_companys_server(): void
    {
        $this->b->post('/api/servers', ['hostname' => 'b-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']]);
        $this->assertSame(404, $this->b->post('/api/backup-jobs', ['server_id' => $this->serverA, 'backup_method' => 'rsync'])->status());
    }

    public function test_user_list_is_limited_to_own_company_and_cross_company_creation_is_refused(): void
    {
        $this->assertSame(['alice'], collect($this->a->get('/api/users')->json('data'))->pluck('username')->all());
        $res = $this->a->post('/api/users', ['username' => 'mallory', 'email' => 'm@x.io', 'role' => 'company_admin', 'password' => 'a-long-password-1', 'company_id' => $this->companyB]);
        $this->assertSame(403, $res->status());
    }

    public function test_dashboard_path_for_another_company_is_403(): void
    {
        $this->assertSame(403, $this->a->get("/api/companies/{$this->companyB}/dashboard")->status());
        $this->assertSame(200, $this->a->get("/api/companies/{$this->companyA}/dashboard")->status());
    }

    public function test_the_same_hostname_is_allowed_in_different_companies(): void
    {
        $this->assertSame(201, $this->b->post('/api/servers', ['hostname' => 'web-01', 'os_type' => 'linux'])->status());
    }

    public function test_users_cannot_be_edited_across_companies(): void
    {
        $bobId = $this->b->get('/api/me')->json('data.id');
        $this->assertSame(404, $this->a->patch("/api/users/$bobId", ['email' => 'hijack@x.io'])->status());
        $this->assertSame('bob@example.com', DB::table('users')->where('id', $bobId)->value('email'));
    }

    // --- model layer (replaces Postgres row-level security) ---

    public function test_tenant_models_return_nothing_when_no_company_is_set(): void
    {
        foreach ([Server::class, BackupJob::class, BackupTestExecution::class, BackupTestFailure::class, AuditEntry::class, Report::class] as $model) {
            $this->assertSame(0, $model::count(), $model);
        }
        $this->assertGreaterThan(0, Server::withoutGlobalScopes()->count(), 'the data exists, it is only hidden');
    }

    public function test_an_unfiltered_query_still_cannot_cross_tenants(): void
    {
        $this->b->post('/api/servers', ['hostname' => 'b-only', 'os_type' => 'linux']);
        $seenByB = $this->asCompany($this->companyB, fn () => Server::all()->pluck('hostname')->all());
        $this->assertSame(['b-only'], $seenByB);
        $this->assertSame(0, $this->asCompany($this->companyB, fn () => BackupJob::count()));
    }

    public function test_writes_into_another_company_are_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->asCompany($this->companyB, fn () => Server::create(['company_id' => $this->companyA, 'hostname' => 'x', 'os_type' => 'linux', 'backup_methods' => []]));
    }

    public function test_writes_with_no_company_are_refused(): void
    {
        $this->expectException(LogicException::class);
        Server::create(['hostname' => 'x', 'os_type' => 'linux', 'backup_methods' => []]);
    }

    // --- audit trail ---

    public function test_the_audit_trail_cannot_be_updated_or_deleted(): void
    {
        try {
            DB::table('audit_trail')->update(['action' => 'x']);
            $this->fail('update should be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        try {
            DB::table('audit_trail')->delete();
            $this->fail('delete should be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }
}
