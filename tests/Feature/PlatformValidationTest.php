<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\Engine\Engine;
use App\Services\PlatformValidation;
use App\Services\TestScheduling;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\ApiClient;
use Tests\TestCase;

/**
 * Acronis validation: what is read from the platform's activity log, and how a device is judged by its newest validation run. The shapes follow what a real
 * console returned: a run (a parent), the validation of each backup within it (children, linked by parentUUID), credential errors that are not damage.
 */
class PlatformValidationTest extends TestCase
{
    private const DC = 'https://eu2-cloud.acronis.com';

    private const RUN = '58F58E51-A7F4-408E-8619-90D9C67AE8C9';

    private const CHILD = '489B4D82-0676-4F49-BC9F-C6F504A10721';

    private ApiClient $admin;

    private int $companyId;

    private int $serverId;

    private int $integrationId;

    private int $jobId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->companyId = $this->makeCompany('valco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'sabtapi', 'os_type' => 'windows', 'backup_methods' => ['acronis', 'rsync']])->json('data.id');
        $this->jobId = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'acronis'])->json('data.id');
        DB::table('backup_jobs')->where('id', $this->jobId)->update(['status' => 'success', 'completed_time' => now()->subHour()]);
    }

    // ---------------------------------------------------------------- helpers

    private function ago(float $hours): string
    {
        return CarbonImmutable::now()->subMinutes((int) ($hours * 60))->toIso8601ZuluString();
    }

    /** An activity as the task manager returns it. */
    private function activity(string $uuid, string $type, string $title, string $state, ?string $result, array $extra = []): array
    {
        return array_replace_recursive([
            'uuid' => $uuid, 'type' => $type, 'state' => $state, 'createdAt' => $this->ago(2), 'startedAt' => $this->ago(2), 'completedAt' => $state === 'completed' ? $this->ago(1) : null,
            'resource' => ['id' => '3743F648-7C68-4633-A22A-6D342CEE807F', 'type' => 'instance', 'name' => 'sabtapi'],
            'policy' => ['id' => 'p1', 'type' => 'backup', 'name' => 'CNTSTORE2: //cntstore2.example/acronis/'],
            'context' => ['title' => $title, 'parentUUID' => $uuid],
            'result' => $result === null ? null : ['code' => $result],
        ], $extra);
    }

    private function vrun(string $uuid, string $state = 'completed', string $result = 'ok', array $extra = []): array
    {
        return $this->activity($uuid, self::RUN, 'Validating backups and reporting statuses', $state, $state === 'started' ? null : $result, $extra);
    }

    private function child(string $uuid, string $parent, string $result = 'ok', ?string $errorCode = null): array
    {
        $a = $this->activity($uuid, self::CHILD, 'Validating', 'completed', $result, ['context' => ['parentUUID' => $parent]]);
        if ($errorCode) {
            $a['result']['error'] = ['domain' => 'environment', 'code' => $errorCode, 'context' => ['resourceAddress' => '//cntstore2.example/acronis/']];
        }

        return $a;
    }

    private function machine(): array
    {
        return [
            'aggregate' => ['names' => ['Daily plan'], 'status' => 'idle'],
            'context' => ['id' => '3743F648-7C68-4633-A22A-6D342CEE807F', 'external_id' => 'ext-1', 'name' => 'SABTAPI', 'user_defined_name' => null, 'tenant_id' => 't1', 'type' => 'resource.machine'],
            'policies' => [['type' => 'policy.backup.machine', 'last_run' => $this->ago(1), 'last_success_run' => $this->ago(1)]],
        ];
    }

    /** @param  list<array>  $pages  one list of activities per page; every page but the last claims there is more */
    private function fake(array $pages): void
    {
        Http::swap(new HttpFactory);
        $seq = [];
        foreach ($pages as $i => $items) {
            $seq[] = Http::response(['items' => $items, 'paging' => ['cursors' => $i < count($pages) - 1 ? ['after' => 'cursor-'.($i + 1)] : []]], 200);
        }
        Http::fake([
            self::DC.'/api/2/idp/token' => Http::response(['access_token' => 'tok', 'expires_on' => time() + 3600, 'token_type' => 'bearer'], 200),
            self::DC.'/api/resource_management/v4/resource_statuses*' => Http::response(['items' => [$this->machine()], 'paging' => ['cursors' => ['total' => 1]]], 200),
            self::DC.'/api/task_manager/v2/activities*' => Http::sequence($seq)->whenEmpty(Http::response(['items' => [], 'paging' => ['cursors' => []]], 200)),
        ]);
    }

    /** Connect, link the server and read the given activities. */
    private function ingest(array $items): void
    {
        $this->fake([$items]);
        if (! isset($this->integrationId)) {
            $this->integrationId = $this->admin->post('/api/integrations', ['platform' => 'acronis', 'name' => 'Acronis EU2', 'base_url' => self::DC, 'client_id' => 'client-1234', 'client_secret' => 'secret-value'])->json('data.id');
            $this->admin->post("/api/servers/{$this->serverId}/links", ['integration_id' => $this->integrationId, 'external_id' => '3743F648-7C68-4633-A22A-6D342CEE807F'])->assertStatus(201);
        }
        $this->admin->post("/api/integrations/{$this->integrationId}/sync")->assertOk();
    }

    private function evaluate(?int $maxAge = null): array
    {
        return CompanyContext::run($this->companyId, fn () => PlatformValidation::evaluate($this->serverId, $maxAge));
    }

    /** Rows straight into the table, for judging without going through a sync. */
    private function row(string $kind, string $uuid, array $over = []): void
    {
        DB::table('platform_checks')->insert($over + ['company_id' => $this->companyId, 'integration_id' => $this->integration(), 'server_id' => $this->serverId, 'uuid' => $uuid,
            'parent_uuid' => null, 'kind' => $kind, 'state' => 'completed', 'result' => 'ok', 'started_at' => now()->subHours(2), 'completed_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function integration(): int
    {
        if (! isset($this->integrationId)) {
            $this->fake([[]]);
            $this->integrationId = $this->admin->post('/api/integrations', ['platform' => 'acronis', 'name' => 'Acronis EU2', 'base_url' => self::DC, 'client_id' => 'client-1234', 'client_secret' => 'secret-value'])->json('data.id');
        }

        return $this->integrationId;
    }

    private function run2(string $uuid, array $over = []): void
    {
        $this->row('validation_run', $uuid, $over);
    }

    private function kid(string $uuid, string $parent, string $result = 'ok', ?string $code = null, ?string $where = null): void
    {
        $this->row('validation', $uuid, ['parent_uuid' => $parent, 'result' => $result, 'error_code' => $code, 'detail' => $where]);
    }

    // ---------------------------------------------------------------- reading what Acronis recorded

    public function test_a_sync_keeps_validation_runs_and_the_validation_of_each_backup_linked_to_the_server(): void
    {
        $this->ingest([
            $this->vrun('run-1'), $this->child('c-1', 'run-1'), $this->child('c-2', 'run-1', 'error', 'ArchivePasswordIsIncorrectOld'),
            $this->activity('other-1', 'D332948D-A7A9-4E07-B76C-253DCF6E17FB', "Backup plan 'x'", 'completed', 'ok'),
        ]);
        $rows = DB::table('platform_checks')->orderBy('id')->get();
        $this->assertCount(3, $rows, 'only validation is kept, not the backup activities around it');
        $this->assertSame(['validation_run', 'validation', 'validation'], $rows->pluck('kind')->all());
        $this->assertSame([$this->serverId], $rows->pluck('server_id')->unique()->all(), 'matched to the server by the device id, whatever the letter case');
        $this->assertSame(['run-1', 'run-1'], [$rows[1]->parent_uuid, $rows[2]->parent_uuid]);
        $this->assertSame('ArchivePasswordIsIncorrectOld', $rows[2]->error_code);
        $this->assertSame('//cntstore2.example/acronis/', $rows[2]->detail, 'where it failed');
        $this->assertStringContainsString('CNTSTORE2', $rows[0]->plan_name);
    }

    public function test_reading_the_same_activity_again_updates_it_and_never_duplicates_it(): void
    {
        $this->ingest([$this->vrun('run-1', 'started')]);
        $this->assertSame(['started', null], [DB::table('platform_checks')->value('state'), DB::table('platform_checks')->value('result')]);
        $this->ingest([$this->vrun('run-1', 'completed', 'warning')]);
        $this->ingest([$this->vrun('run-1', 'completed', 'warning')]);
        $this->assertSame(1, DB::table('platform_checks')->count());
        $this->assertSame(['completed', 'warning'], [DB::table('platform_checks')->value('state'), DB::table('platform_checks')->value('result')]);
    }

    public function test_replication_is_kept_but_belongs_to_no_device(): void
    {
        $this->ingest([$this->activity('rep-1', '4B40E257-1B18-47F2-AA94-72A4B1D6E4DF', 'Replication', 'completed', 'ok', ['resource' => ['id' => 'VAULT-ID', 'name' => 'VAULT']])]);
        $r = DB::table('platform_checks')->first();
        $this->assertSame(['replication', null], [$r->kind, $r->server_id], 'it runs on the vault, not on a protected device');
    }

    public function test_a_long_history_is_read_in_pages_and_the_next_read_carries_on_from_where_it_stopped(): void
    {
        $page1 = [$this->vrun('run-1')];
        for ($i = 0; $i < 999; $i++) {
            $page1[] = $this->activity("noise-$i", 'D332948D-A7A9-4E07-B76C-253DCF6E17FB', 'Backing up', 'completed', 'ok');
        }
        $this->fake([$page1, [$this->child('c-1', 'run-1')]]);
        $this->integrationId = $this->admin->post('/api/integrations', ['platform' => 'acronis', 'name' => 'Acronis EU2', 'base_url' => self::DC, 'client_id' => 'client-1234', 'client_secret' => 'secret-value'])->json('data.id');
        $this->admin->post("/api/servers/{$this->serverId}/links", ['integration_id' => $this->integrationId, 'external_id' => '3743F648-7C68-4633-A22A-6D342CEE807F']);
        $this->assertSame(2, DB::table('platform_checks')->count(), 'both pages were read');
        $this->assertSame('cursor-1', DB::table('integrations')->value('activity_cursor'), 'it will start from the last page next time');
        $this->admin->post("/api/integrations/{$this->integrationId}/sync")->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'task_manager') && ($r->data()['after'] ?? null) === 'cursor-1');
    }

    public function test_a_problem_reading_validation_never_stops_the_device_statuses_syncing(): void
    {
        $this->fake([[]]);
        Http::fake([self::DC.'/api/task_manager/v2/activities*' => Http::response(['error' => 'boom'], 500)]);
        $this->integrationId = $this->admin->post('/api/integrations', ['platform' => 'acronis', 'name' => 'Acronis EU2', 'base_url' => self::DC, 'client_id' => 'client-1234', 'client_secret' => 'secret-value'])->json('data.id');
        $this->admin->post("/api/servers/{$this->serverId}/links", ['integration_id' => $this->integrationId, 'external_id' => '3743F648-7C68-4633-A22A-6D342CEE807F'])->assertStatus(201);
        $this->admin->post("/api/integrations/{$this->integrationId}/sync")->assertOk();
        $this->assertSame('ok', DB::table('integrations')->value('last_sync_status'));
        $this->assertSame('success', DB::table('servers')->find($this->serverId)->last_backup_status);
    }

    // ---------------------------------------------------------------- judging a device

    public function test_the_error_codes_are_told_apart(): void
    {
        foreach (['ArchivePasswordIsIncorrectOld' => 'access', 'LocationCredentialsNotCorrect' => 'access', 'ArchiveCorrupted' => 'integrity', 'ChecksumMismatch' => 'integrity', 'SomethingNew' => 'other', null => 'other'] as $code => $expect) {
            $this->assertSame($expect, PlatformValidation::classify($code === '' ? null : ($code ?: null)), (string) $code);
        }
    }

    public function test_a_device_with_no_validation_is_skipped_never_passed(): void
    {
        $r = $this->evaluate();
        $this->assertArrayHasKey('skip', $r);
        $this->assertStringContainsString('has not reported any validation', $r['skip']);
    }

    public function test_a_clean_run_passes_even_while_still_running(): void
    {
        $this->run2('run-1', ['state' => 'started', 'result' => null, 'completed_at' => null]);
        foreach (range(1, 8) as $i) {
            $this->kid("c-$i", 'run-1');
        }
        $r = $this->evaluate();
        $this->assertTrue($r['success']);
        $this->assertStringContainsString('validated 8 backups, no errors', $r['summary']);
        $this->assertStringContainsString('still running', $r['summary']);
    }

    public function test_last_nights_credential_errors_fail_as_access_denied_and_say_so_plainly(): void
    {
        $this->run2('night', ['result' => 'warning']);
        foreach (range(1, 55) as $i) {
            $this->kid("e-$i", 'night', 'error', 'ArchivePasswordIsIncorrectOld', '//cntstore2.example/acronis/');
        }
        foreach (range(1, 3) as $i) {
            $this->kid("o-$i", 'night');
        }
        $r = $this->evaluate();
        $this->assertFalse($r['success']);
        $this->assertSame(['access_denied', 'medium'], [$r['failure_type'], $r['severity']], 'not corruption: the backup was never opened');
        $this->assertStringContainsString('55 validation errors of 58', $r['error_message']);
        $this->assertStringContainsString('ArchivePasswordIsIncorrectOld (x55)', $r['error_message']);
        $this->assertStringContainsString('not confirmed either way', $r['error_message']);
        $this->assertStringContainsString('//cntstore2.example/acronis/', $r['error_message']);
        $this->assertStringNotContainsString('damaged', $r['error_message']);
    }

    public function test_a_run_that_fails_before_validating_anything_is_judged_by_its_own_error(): void
    {
        $this->run2('run-1', ['result' => 'error', 'error_code' => 'LocationCredentialsNotCorrect', 'detail' => '//cptstore2.example/acronis/']);
        $r = $this->evaluate();
        $this->assertSame(['access_denied', 'medium'], [$r['failure_type'], $r['severity']]);
        $this->assertStringContainsString('//cptstore2.example/acronis/', $r['error_message']);
    }

    public function test_the_newest_run_decides_so_last_nights_errors_do_not_count_against_a_clean_run_today(): void
    {
        $this->run2('night', ['started_at' => now()->subHours(14), 'result' => 'warning']);
        $this->kid('bad', 'night', 'error', 'ArchivePasswordIsIncorrectOld');
        $this->run2('today', ['started_at' => now()->subHours(2), 'state' => 'started', 'result' => null]);
        $this->kid('good-1', 'today');
        $this->kid('good-2', 'today');
        $r = $this->evaluate();
        $this->assertTrue($r['success']);
        $this->assertStringContainsString('validated 2 backups', $r['summary']);
    }

    public function test_damage_is_reported_as_corruption_and_outranks_everything_else(): void
    {
        $this->run2('run-1');
        $this->kid('a', 'run-1', 'error', 'ArchivePasswordIsIncorrectOld');
        $this->kid('b', 'run-1', 'error', 'ArchiveCorrupted');
        $r = $this->evaluate();
        $this->assertSame(['corruption', 'critical'], [$r['failure_type'], $r['severity']]);
        $this->assertStringContainsString('found it damaged', $r['error_message']);
    }

    public function test_an_error_nobody_explained_is_a_general_failure_not_a_guess_at_damage(): void
    {
        $this->run2('run-1');
        $this->kid('a', 'run-1', 'error', 'SomethingNewFromAcronis');
        $r = $this->evaluate();
        $this->assertSame(['restore_failed', 'high'], [$r['failure_type'], $r['severity']]);
        $this->assertStringContainsString('did not say what was wrong', $r['error_message']);
    }

    public function test_a_warning_still_counts_as_validated(): void
    {
        $this->run2('run-1');
        $this->kid('a', 'run-1', 'warning');
        $this->kid('b', 'run-1');
        $this->assertTrue($this->evaluate()['success']);
    }

    public function test_a_run_that_has_started_but_finished_nothing_is_skipped(): void
    {
        $this->run2('run-1', ['state' => 'started', 'result' => null, 'completed_at' => null]);
        $this->assertStringContainsString('nothing has finished yet', $this->evaluate()['skip']);
    }

    public function test_validation_that_stopped_running_fails_after_the_allowed_time(): void
    {
        $this->run2('old', ['started_at' => now()->subHours(100)]);
        $this->kid('a', 'old');
        $r = $this->evaluate();
        $this->assertSame(['file_missing', 'medium'], [$r['failure_type'], $r['severity']]);
        $this->assertStringContainsString('more than 72 hours ago', $r['error_message']);
        $this->assertTrue($this->evaluate(200)['success'], 'the allowance can be changed');
    }

    // ---------------------------------------------------------------- the test itself

    private function queue(): int
    {
        return $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'platform_validation'])->assertStatus(202)->json('data.id');
    }

    public function test_the_engine_records_a_pass_a_failure_with_a_review_item_and_a_skip_each_for_what_it_is(): void
    {
        $this->run2('run-1');
        $this->kid('a', 'run-1');
        $pass = $this->queue();
        Engine::runDueTests($this->companyId);
        $t = DB::table('backup_test_executions')->find($pass);
        $this->assertSame('success', $t->test_status);
        $this->assertStringContainsString('validated 1 backup', $t->summary);
        $this->assertSame('passed', DB::table('servers')->find($this->serverId)->last_test_status);

        DB::table('platform_checks')->delete();
        $this->run2('night', ['result' => 'warning']);
        $this->kid('e', 'night', 'error', 'ArchivePasswordIsIncorrectOld');
        $fail = $this->queue();
        Engine::runDueTests($this->companyId);
        $t = DB::table('backup_test_executions')->find($fail);
        $this->assertSame('failed', $t->test_status);
        $f = DB::table('backup_test_failures')->where('test_execution_id', $fail)->first();
        $this->assertSame(['access_denied', 'medium'], [$f->failure_type, $f->severity], 'raised for review like any failed test');
        $this->assertSame(0, DB::table('backup_test_executions')->where('retry_of', $fail)->count(), 'not retried: waiting will not fix a credential');

        DB::table('platform_checks')->delete();
        $skip = $this->queue();
        Engine::runDueTests($this->companyId);
        $this->assertSame('skipped', DB::table('backup_test_executions')->find($skip)->test_status);
    }

    public function test_it_applies_to_acronis_backups_only(): void
    {
        $rsync = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'rsync'])->json('data.id');
        $id = $this->admin->post("/api/backup-jobs/$rsync/test", ['test_type' => 'platform_validation'])->assertStatus(202)->json('data.id');
        Engine::runDueTests($this->companyId);
        $t = DB::table('backup_test_executions')->find($id);
        $this->assertSame('skipped', $t->test_status);
        $this->assertStringContainsString('Acronis backups only', $t->error_message);
    }

    public function test_a_schedule_for_it_covers_the_acronis_backups_only_and_a_person_can_do_it_for_any(): void
    {
        $rsync = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverId, 'backup_method' => 'rsync'])->json('data.id');
        DB::table('backup_jobs')->where('id', $rsync)->update(['status' => 'success']);
        $body = ['name' => 'Acronis validation', 'test_type' => 'platform_validation', 'target_type' => 'servers', 'server_ids' => [$this->serverId], 'frequency' => 'daily', 'run_time' => '07:00', 'timezone' => 'UTC'];
        $sid = $this->admin->post('/api/test-schedules', $body)->assertStatus(201)->json('data.id');
        $this->assertSame(['acronis'], $this->admin->get('/api/test-schedules')->json('data.0.methods'));
        DB::table('test_schedules')->where('id', $sid)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(1, TestScheduling::runDue($this->companyId));
        $this->assertSame($this->jobId, (int) DB::table('backup_test_executions')->value('backup_job_id'));
        $this->admin->post('/api/test-schedules', ['name' => 'By hand', 'manual' => true] + $body)->assertStatus(201);
    }

    public function test_it_is_known_to_the_company_settings_and_the_help(): void
    {
        $types = collect($this->admin->get('/api/test-types')->json('data'))->keyBy('type');
        $this->assertTrue($types['platform_validation']['automated']);
        $this->assertStringContainsString('not as damage', $types['platform_validation']['details']);
        $this->assertStringContainsString('Acronis backups only', $types['platform_validation']['details']);
        $this->assertTrue(collect($this->admin->get('/api/test-settings')->json('data'))->firstWhere('type', 'platform_validation')['active']);
        $this->admin->put('/api/test-settings', ['tests' => ['platform_validation' => false]])->assertOk();
        $this->admin->post("/api/backup-jobs/{$this->jobId}/test", ['test_type' => 'platform_validation'])->assertStatus(409);
    }

    public function test_a_test_by_hand_has_its_own_checklist(): void
    {
        $id = $this->admin->post("/api/backup-jobs/{$this->jobId}/manual-test", ['test_type' => 'platform_validation'])->assertStatus(201)->json('data.id');
        $t = $this->admin->get("/api/manual-tests/$id")->json('data');
        $this->assertSame('The Acronis device to check', $t['target_label']);
        $this->assertNotEmpty($t['steps']);
        $this->assertSame(Server::class, Server::class);
    }

    public function test_the_server_says_whether_a_validation_plan_is_confirmed_and_that_acronis_does_the_validating(): void
    {
        $none = $this->admin->get("/api/servers/{$this->serverId}")->json('data.validation');
        $this->assertFalse($none['confirmed']);
        $this->assertStringContainsString('done by Acronis, not BMS', $none['message']);

        $this->run2('run-1');
        $this->kid('a', 'run-1');
        $yes = $this->admin->get("/api/servers/{$this->serverId}")->json('data.validation');
        $this->assertTrue($yes['confirmed']);
        $this->assertSame(1, $yes['runs_30d']);
        $this->assertStringContainsString('Acronis runs the validation', $yes['message']);
    }
}
