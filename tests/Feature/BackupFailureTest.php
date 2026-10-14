<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\BackupFailures;
use App\Services\Engine\Engine;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\ApiClient;
use Tests\TestCase;

/** Backup problems that have to be reviewed, and what a recovery after missed backups does and does not erase. */
class BackupFailureTest extends TestCase
{
    private const DC = 'https://eu2-cloud.acronis.com';

    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $manager;

    private ApiClient $rival;

    private int $companyId;

    private int $serverId;

    private int $integrationId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->companyId = $this->makeCompany('failco');
        $other = $this->makeCompany('rivalfail');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $techId = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($this->companyId, 'tech2', 'company_technician');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $this->makeUser($other, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->manager = $this->login('mgr');
        $this->rival = $this->login('rival');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'file-01', 'os_type' => 'windows', 'backup_methods' => ['acronis']])->json('data.id');
        $this->admin->put("/api/servers/{$this->serverId}/assignments", ['user_ids' => [$techId]]);
    }

    // ---------------------------------------------------------------- helpers

    private function ago(int $hours): string
    {
        return CarbonImmutable::now()->subHours($hours)->toIso8601ZuluString();
    }

    private function machine(string $status, ?string $run, ?string $success): array
    {
        return [
            'aggregate' => ['names' => ['Daily plan'], 'status' => $status],
            'context' => ['id' => 'm1', 'external_id' => 'ext-m1', 'name' => 'FILE-01', 'user_defined_name' => null, 'tenant_id' => 't1', 'type' => 'resource.machine'],
            'policies' => [['type' => 'policy.backup.machine', 'last_run' => $run, 'last_success_run' => $success]],
        ];
    }

    private function fake(array $machine): void
    {
        Http::swap(new HttpFactory);
        Http::fake([
            self::DC.'/api/2/idp/token' => Http::response(['access_token' => 'tok', 'expires_on' => time() + 3600, 'token_type' => 'bearer'], 200),
            self::DC.'/api/resource_management/v4/resource_statuses*' => Http::response(['items' => [$machine], 'paging' => ['cursors' => ['total' => 1]]], 200),
        ]);
    }

    /** Acronis reports this state for the server now. The first call connects and links it. */
    private function acronis(string $status, ?string $run, ?string $success): void
    {
        $this->fake($this->machine($status, $run, $success));
        if (! isset($this->integrationId)) {
            $this->integrationId = $this->admin->post('/api/integrations', ['platform' => 'acronis', 'name' => 'Acronis EU2', 'base_url' => self::DC, 'client_id' => 'client-1234', 'client_secret' => 'secret-value'])->json('data.id');
            $this->admin->post("/api/servers/{$this->serverId}/links", ['integration_id' => $this->integrationId, 'external_id' => 'm1'])->assertStatus(201);

            return;
        }
        $this->admin->post("/api/integrations/{$this->integrationId}/sync")->assertOk();
    }

    private function open(?ApiClient $who = null, string $query = ''): array
    {
        return ($who ?? $this->admin)->get('/api/backup-failures'.$query)->assertOk()->json('data');
    }

    private function alerts(): int
    {
        return DB::table('notifications')->where('event_type', 'backup_failed')->count();
    }

    // ---------------------------------------------------------------- what gets flagged

    public function test_a_failed_run_opens_one_item_to_review_and_a_repeat_does_not_open_another(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(30));
        $items = $this->open();
        $this->assertCount(1, $items);
        $f = $items[0];
        $this->assertSame(['failed', 'high', 'acronis', 'file-01', true, false], [$f['kind'], $f['severity'], $f['source'], $f['hostname'], $f['active'], $f['resolved']]);
        $this->assertStringContainsString('did not succeed', $f['message']);
        $this->assertStringContainsString('Last successful backup', $f['message']);
        $this->assertSame(1, $this->alerts());

        $this->acronis('error', $this->ago(1), $this->ago(30));
        $this->acronis('error', $this->ago(1), $this->ago(30));
        $this->assertCount(1, $this->open(), 'the same problem is one item, however often it is seen');
        $this->assertSame(1, $this->alerts(), 'and one alert');
    }

    public function test_a_device_acronis_calls_critical_is_flagged_even_when_its_last_run_succeeded(): void
    {
        $this->acronis('critical', $this->ago(2), $this->ago(2));
        $f = $this->open()[0];
        $this->assertSame(['critical', 'high'], [$f['kind'], $f['severity']]);
        $this->assertStringContainsString('reports this device as critical', $f['message']);
    }

    public function test_an_overdue_backup_and_an_acronis_warning_are_flagged_at_their_own_severity(): void
    {
        $this->acronis('idle', $this->ago(80), $this->ago(80));
        $this->assertSame(['overdue', 'medium'], [$this->open()[0]['kind'], $this->open()[0]['severity']]);
        $this->acronis('warning', $this->ago(2), $this->ago(2));
        $f = $this->open()[0];
        $this->assertSame(['warning', 'low'], [$f['kind'], $f['severity']], 'the problem changed: it is now only a warning');
        $this->assertCount(1, $this->open());
    }

    public function test_a_healthy_device_opens_nothing(): void
    {
        $this->acronis('idle', $this->ago(2), $this->ago(2));
        $this->assertSame([], $this->open());
    }

    public function test_the_worst_problem_is_the_one_reported(): void
    {
        $this->acronis('critical', $this->ago(1), $this->ago(30));
        $this->assertSame('failed', $this->open()[0]['kind'], 'a failed run is worse than a critical status');
    }

    public function test_a_problem_getting_worse_updates_the_same_item_and_alerts_again(): void
    {
        $this->acronis('idle', $this->ago(80), $this->ago(80));
        $this->assertSame(1, $this->alerts());
        $this->acronis('error', $this->ago(1), $this->ago(80));
        $this->assertCount(1, $this->open());
        $this->assertSame('failed', $this->open()[0]['kind']);
        $this->assertSame(2, $this->alerts());
        $this->assertSame(1, DB::table('notifications')->where('subject', 'like', '%got worse%')->count());
    }

    // ---------------------------------------------------------------- recovery and the gap

    public function test_a_problem_that_goes_away_stays_open_until_someone_reviews_it(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(30));
        $this->acronis('idle', $this->ago(0), $this->ago(0));
        $items = $this->open();
        $this->assertCount(1, $items, 'recovering is not the same as reviewed');
        $this->assertFalse($items[0]['active']);
        $this->assertNotNull($items[0]['recovered_at']);
        $this->assertFalse($items[0]['resolved']);
        $this->assertSame('success', $this->admin->get("/api/servers/{$this->serverId}")->json('data.last_backup_status'), 'the backup itself is fine again');
    }

    public function test_a_recovery_after_skipped_backups_records_how_many_were_missed(): void
    {
        // Last good backup 78 hours ago, daily backups: the ones due at about 24, 48 and 72 hours were skipped, and the one at 78 is the one that worked.
        $this->acronis('error', $this->ago(1), $this->ago(78));
        $during = $this->open()[0];
        $this->assertSame([77 + 1, 2], [$during['gap_hours'], $during['missed_runs']], 'measured so far while it is still failing');

        $this->acronis('idle', $this->ago(0), $this->ago(0));
        $f = $this->open()[0];
        $this->assertSame([78, 2], [$f['gap_hours'], $f['missed_runs']], 'two backups were skipped and a later success does not make up for them');
        $this->assertFalse($f['active']);
    }

    public function test_one_failed_run_that_is_fixed_by_the_next_is_not_counted_as_a_missed_backup(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(25));
        $this->acronis('idle', $this->ago(0), $this->ago(0));
        $f = $this->open()[0];
        $this->assertSame(['25', 0], [(string) $f['gap_hours'], $f['missed_runs']], 'a day between good backups is normal for a daily backup');
    }

    public function test_the_gap_arithmetic(): void
    {
        $t = CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC');
        $cases = [[24, 86400, 0], [48, 86400, 1], [72, 86400, 2], [30, 21600, 4], [5, 86400, 0], [0, 86400, 0]];
        foreach ($cases as [$hours, $interval, $missed]) {
            $g = BackupFailures::gap($t, $t->addHours($hours), $interval);
            $this->assertSame([$hours, $missed], [$g['gap_hours'], $g['missed_runs']], "$hours hours at an interval of $interval");
        }
        $this->assertSame(['gap_hours' => null, 'missed_runs' => 0], BackupFailures::gap(null, $t, 86400), 'no good backup on record: nothing to measure');
    }

    public function test_how_often_a_backup_is_expected_comes_from_the_schedule_or_the_devices_own_history(): void
    {
        CompanyContext::run($this->companyId, function () {
            $server = Server::find($this->serverId);
            $this->assertSame(86400, BackupFailures::intervalSeconds($server, 'acronis'), 'a day when nothing says otherwise');
            $this->assertSame(21600, BackupFailures::intervalSeconds($server, 'acronis', '0 */6 * * *'), 'every 6 hours from a schedule');
            $this->assertSame(3600, BackupFailures::intervalSeconds($server, 'acronis', '*/5 * * * *'), 'never less than an hour');
            foreach ([0, 6, 12, 18, 24, 30] as $h) {
                DB::table('backup_jobs')->insert(['company_id' => $this->companyId, 'server_id' => $this->serverId, 'backup_method' => 'acronis', 'status' => 'success', 'completed_time' => now()->subHours($h), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->assertSame(21600, BackupFailures::intervalSeconds($server, 'acronis'), 'every 6 hours from its own history');
        });
    }

    public function test_a_failure_that_comes_back_before_anyone_reviewed_the_last_one_is_the_same_item(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(30));
        $this->acronis('idle', $this->ago(0), $this->ago(0));
        $this->acronis('error', $this->ago(0), $this->ago(2));
        $items = $this->open();
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['active']);
        $this->assertNull($items[0]['recovered_at']);
        $this->assertSame(1, DB::table('notifications')->where('subject', 'like', '%came back%')->count());
    }

    // ---------------------------------------------------------------- reviewing

    public function test_a_reviewed_problem_that_is_still_there_is_not_raised_again_unless_it_gets_worse(): void
    {
        $this->acronis('idle', $this->ago(80), $this->ago(80));
        $id = $this->open()[0]['id'];
        $this->admin->post("/api/backup-failures/$id/resolve", ['resolution_type' => 'known_issue', 'notes' => 'The server is decommissioned next week.'])->assertOk();
        $alerts = $this->alerts();
        $this->acronis('idle', $this->ago(81), $this->ago(81));
        $this->assertSame([], $this->open(), 'looked at, still overdue, no worse: left alone');
        $this->assertSame($alerts, $this->alerts());

        $this->acronis('error', $this->ago(1), $this->ago(81));
        $this->assertCount(1, $this->open(), 'but a failed run is worse and opens a new item');
        $this->assertNotSame($id, $this->open()[0]['id']);
    }

    public function test_a_reviewed_problem_that_cleared_and_returns_opens_a_new_item(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(30));
        $id = $this->open()[0]['id'];
        $this->admin->post("/api/backup-failures/$id/resolve", ['resolution_type' => 'fixed', 'notes' => 'Restarted the agent.'])->assertOk();
        $this->acronis('idle', $this->ago(0), $this->ago(0));
        $this->acronis('error', $this->ago(0), $this->ago(20));
        $this->assertCount(1, $this->open());
        $this->assertNotSame($id, $this->open()[0]['id']);
    }

    public function test_reviewing_needs_notes_and_a_real_resolution_and_is_recorded(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(30));
        $id = $this->open()[0]['id'];
        $this->admin->post("/api/backup-failures/$id/resolve", ['resolution_type' => 'fixed'])->assertStatus(400);
        $this->admin->post("/api/backup-failures/$id/resolve", ['resolution_type' => 'fixed', 'notes' => ''])->assertStatus(400);
        $this->admin->post("/api/backup-failures/$id/resolve", ['resolution_type' => 'nonsense', 'notes' => 'x'])->assertStatus(400);
        $r = $this->tech->post("/api/backup-failures/$id/resolve", ['resolution_type' => 'platform_issue', 'notes' => 'Acronis had an outage that night.'])->assertOk();
        $this->assertSame(['tech', true], [$r->json('data.resolved_username'), $r->json('data.resolved')]);
        $this->admin->post("/api/backup-failures/$id/resolve", ['resolution_type' => 'fixed', 'notes' => 'again'])->assertStatus(409)->assertJsonPath('error_code', 'FAILURE_002');
        $e = collect($this->admin->get('/api/audit?limit=50')->json('data'))->firstWhere('action', 'backup_failure_reviewed');
        $this->assertSame(['platform_issue', 'file-01', true], [$e['details']['resolution'], $e['details']['server'], $e['details']['still_active']]);
        $this->assertSame([], $this->open());
        $this->assertCount(1, $this->open(null, '?resolved=true'));
    }

    public function test_only_admins_and_assigned_technicians_can_review_and_nobody_across_companies(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(30));
        $id = $this->open()[0]['id'];
        $body = ['resolution_type' => 'fixed', 'notes' => 'done'];
        $this->manager->post("/api/backup-failures/$id/resolve", $body)->assertStatus(403);
        $this->rival->post("/api/backup-failures/$id/resolve", $body)->assertStatus(404);
        $unassigned = $this->login('tech2');
        $unassigned->post("/api/backup-failures/$id/resolve", $body)->assertStatus(404);
        $this->assertSame([], $this->open($unassigned), 'a technician only sees servers they are assigned to');
        $this->assertCount(1, $this->open($this->manager), 'a manager can see them');
        $this->assertSame([], $this->open($this->rival));
    }

    public function test_the_list_filters_by_kind_and_puts_the_worst_first(): void
    {
        $this->acronis('idle', $this->ago(80), $this->ago(80));
        CompanyContext::run($this->companyId, function () {
            $s = Server::find($this->serverId);
            BackupFailures::report($s, 'agent', 'rsync', 'failed', 'The backup action exited with an error.');
            BackupFailures::report($s, 'agent', 'robocopy', 'warning', 'x');
        });
        $this->assertSame(['failed', 'overdue', 'warning'], array_column($this->open(), 'kind'));
        $this->assertSame(['overdue'], array_column($this->open(null, '?kind=overdue'), 'kind'));
        $this->admin->get('/api/backup-failures?kind=nonsense')->assertStatus(400);
    }

    // ---------------------------------------------------------------- an agent backup that fails

    public function test_a_failed_agent_backup_opens_an_item_and_a_later_success_recovers_it_with_the_gap(): void
    {
        CompanyContext::run($this->companyId, function () {
            $s = Server::find($this->serverId);
            $f = BackupFailures::report($s, 'agent', 'rsync', 'failed', 'Exit code 1.', null, ['last_good_at' => CarbonImmutable::now()->subHours(50), 'interval' => 86400]);
            $this->assertSame([50, 1], [$f->gap_hours, $f->missed_runs]);
            $this->assertSame(1, $this->alerts());
            BackupFailures::report($s, 'agent', 'rsync', null, null, null, ['back_at' => CarbonImmutable::now(), 'interval' => 86400]);
        });
        $f = $this->open()[0];
        $this->assertSame(['agent', 'rsync', false, 50, 1], [$f['source'], $f['backup_method'], $f['active'], $f['gap_hours'], $f['missed_runs']]);
    }

    // ---------------------------------------------------------------- chasing and counting

    public function test_unreviewed_high_severity_problems_escalate_after_four_and_eight_hours_and_low_ones_do_not(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(30));
        DB::table('backup_failures')->update(['first_seen_at' => now()->subHours(5)]);
        $this->assertSame(1, Engine::escalateFailures($this->companyId));
        $this->assertSame(0, Engine::escalateFailures($this->companyId), 'once per level');
        DB::table('backup_failures')->update(['first_seen_at' => now()->subHours(9)]);
        $this->assertSame(1, Engine::escalateFailures($this->companyId));
        $this->assertSame(4, DB::table('notifications')->where('event_type', 'failure_escalated')->count(), 'two levels, each told to an admin and a manager');
        $this->assertSame(2, (int) DB::table('backup_failures')->value('escalation_level'));

        DB::table('backup_failures')->delete();
        $this->acronis('warning', $this->ago(2), $this->ago(2));
        DB::table('backup_failures')->update(['first_seen_at' => now()->subHours(20)]);
        $this->assertSame(0, Engine::escalateFailures($this->companyId), 'a warning is not chased');
    }

    public function test_a_reviewed_problem_is_not_chased(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(30));
        $id = $this->open()[0]['id'];
        $this->admin->post("/api/backup-failures/$id/resolve", ['resolution_type' => 'fixed', 'notes' => 'done'])->assertOk();
        DB::table('backup_failures')->update(['first_seen_at' => now()->subHours(20)]);
        $this->assertSame(0, Engine::escalateFailures($this->companyId));
    }

    public function test_the_dashboard_counts_what_waits_for_review_and_what_was_missed(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(78));
        $d = $this->admin->get('/api/dashboard')->assertOk()->json('data');
        $this->assertSame(1, $d['unresolved_backup_failures']);
        $this->assertSame(2, $d['backups_30d']['missed']);
        $this->assertSame(1, $this->tech->get('/api/dashboard')->json('data.unresolved_backup_failures'));
        $this->assertSame(0, $this->rival->get('/api/dashboard')->json('data.unresolved_backup_failures'));
        $id = $this->open()[0]['id'];
        $this->admin->post("/api/backup-failures/$id/resolve", ['resolution_type' => 'fixed', 'notes' => 'done'])->assertOk();
        $d = $this->admin->get('/api/dashboard')->json('data');
        $this->assertSame(0, $d['unresolved_backup_failures']);
        $this->assertSame(2, $d['backups_30d']['missed'], 'reviewing a problem does not un-miss the backups');
    }

    public function test_the_refresh_command_opens_items_for_problems_already_recorded(): void
    {
        $this->acronis('error', $this->ago(1), $this->ago(30));
        DB::table('backup_failures')->delete();
        $this->assertSame([], $this->open());
        $this->artisan('bms:backup-failures-refresh')->assertSuccessful();
        $this->assertCount(1, $this->open());
        $this->artisan('bms:backup-failures-refresh')->assertSuccessful();
        $this->assertCount(1, $this->open(), 'running it again changes nothing');
    }
}
