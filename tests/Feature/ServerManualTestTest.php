<?php

namespace Tests\Feature;

use App\Services\TestScheduling;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\ApiClient;
use Tests\TestCase;

/** A test by hand starts from the server, so a Microsoft 365 tenant needs no backup job. */
class ServerManualTestTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $tech;

    private int $companyId;

    private int $tenants = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('tenantco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
    }

    private function tenant(): int
    {
        return $this->admin->post('/api/servers', ['hostname' => 'tenant365x'.(++$this->tenants), 'os_type' => 'microsoft_365', 'backup_methods' => ['acronis_m365']])->json('data.id');
    }

    public function test_a_microsoft_365_server_with_no_backup_job_can_be_tested_and_gets_a_stand_in_that_is_not_a_backup(): void
    {
        $id = $this->tenant();
        $this->assertSame(0, DB::table('backup_jobs')->where('server_id', $id)->count());

        $t = $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201)->json('data');
        $this->assertSame('m365_mailbox', $t['test_type']);

        $job = DB::table('backup_jobs')->where('server_id', $id)->get();
        $this->assertCount(1, $job);
        $this->assertSame(['acronis_m365', 'scheduled', null], [$job[0]->backup_method, $job[0]->status, $job[0]->completed_time], 'a job that never ran');
        $this->assertStringContainsString('No backup has been reported', $job[0]->status_message);
        $this->assertNull(DB::table('servers')->find($id)->last_backup_status, 'it does not pretend a backup happened');
    }

    public function test_a_second_test_reuses_the_same_job_instead_of_making_another(): void
    {
        $id = $this->tenant();
        $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201);
        $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(409);
        $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'recovery_point'])->assertStatus(201);
        $this->assertSame(1, DB::table('backup_jobs')->where('server_id', $id)->count());
    }

    public function test_a_real_backup_job_is_used_when_there_is_one(): void
    {
        $id = $this->tenant();
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $id, 'backup_method' => 'acronis_m365'])->json('data.id');
        $t = $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(201)->json('data');
        $this->assertSame($job, $t['backup_job_id']);
        $this->assertSame(1, DB::table('backup_jobs')->where('server_id', $id)->count());
    }

    public function test_a_technician_can_only_do_it_for_a_server_they_are_assigned_to(): void
    {
        $id = $this->tenant();
        $this->tech->post("/api/servers/$id/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(404);
        $this->assertSame(0, DB::table('backup_jobs')->where('server_id', $id)->count(), 'a refused request leaves nothing behind');
    }

    public function test_an_unknown_server_or_test_is_refused(): void
    {
        $id = $this->tenant();
        $this->admin->post('/api/servers/999999/manual-test', ['test_type' => 'm365_mailbox'])->assertStatus(404);
        $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'nonsense'])->assertStatus(400);
        $this->assertSame(0, DB::table('backup_jobs')->where('server_id', $id)->count(), 'a refused request leaves no stand-in job');
    }

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @return list<array{0: string}> */
    public static function newTests(): array
    {
        return [['onedrive_file'], ['sharepoint_site'], ['teams_channel']];
    }

    public function test_the_onedrive_and_sharepoint_tests_are_known_by_hand_only_tests_with_their_own_checklists(): void
    {
        $types = collect($this->admin->get('/api/test-types')->json('data'))->keyBy('type');
        foreach (self::newTests() as [$type]) {
            $this->assertFalse($types[$type]['automated'], "$type is done by a person");
            $this->assertStringContainsString('never', $types[$type]['details']);
        }
        $id = $this->tenant();
        $one = $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'onedrive_file'])->assertStatus(201)->json('data');
        $this->assertSame('The OneDrive to test', $one['target_label']);
        $this->assertContains('restore', array_column($one['steps'], 'id'));
        $two = $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'sharepoint_site'])->assertStatus(201)->json('data');
        $this->assertSame('The SharePoint site to test', $two['target_label']);
        $this->assertContains('count', array_column($two['steps'], 'id'));
    }

    public function test_each_needs_its_notes_and_screenshots_before_it_can_pass_and_then_passes(): void
    {
        Storage::fake('local');
        $id = $this->tenant();
        foreach (self::newTests() as [$type]) {
            $tid = $this->admin->post("/api/servers/$id/manual-test", ['test_type' => $type])->assertStatus(201)->json('data.id');
            $this->admin->post("/api/manual-tests/$tid/start")->assertOk();
            $steps = $this->admin->get("/api/manual-tests/$tid")->json('data.steps');
            $this->admin->put("/api/manual-tests/$tid", ['checklist' => array_map(fn ($s) => ['id' => $s['id'], 'done' => true], $steps)])->assertOk();
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400);

            foreach ($steps as $s) {
                if ($s['required'] && $s['evidence'] === 'note') {
                    $this->admin->post("/api/manual-tests/$tid/evidence", ['kind' => 'note', 'step_id' => $s['id'], 'label' => 'Note', 'text' => 'Recorded.'])->assertStatus(201);
                }
            }
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400);

            foreach ($steps as $s) {
                for ($n = 0; $n < ($s['files'] ?? 0); $n++) {
                    $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->token, 'Accept' => 'application/json'])
                        ->post("/api/manual-tests/$tid/evidence-file", ['file' => UploadedFile::fake()->createWithContent('shot'.$n.'.png', base64_decode(self::PNG)), 'step_id' => $s['id'], 'label' => 'Screenshot '.$n])->assertStatus(201);
                }
            }
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400);   // the details of the item are missing

            $this->findings($tid)->assertOk();
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertOk();
            $this->assertSame('success', DB::table('backup_test_executions')->find($tid)->test_status, $type);
        }
    }

    private function schedule(string $type, string $target, array $extra = []): int
    {
        $sid = $this->admin->post('/api/test-schedules', ['name' => "By hand $type", 'test_type' => $type, 'manual' => true, 'target_type' => $target, 'frequency' => 'daily', 'run_time' => '07:00', 'timezone' => 'UTC'] + $extra)
            ->assertStatus(201)->json('data.id');
        DB::table('test_schedules')->where('id', $sid)->update(['next_run_at' => now()->subMinute()]);

        return $sid;
    }

    public function test_a_schedule_for_the_365_tests_covers_a_tenant_that_has_no_backup_job(): void
    {
        $id = $this->tenant();
        $this->assertSame(0, DB::table('backup_jobs')->where('server_id', $id)->count());
        $this->schedule('onedrive_file', 'servers', ['server_ids' => [$id]]);
        $this->assertSame(1, TestScheduling::runDue($this->companyId));
        $t = DB::table('backup_test_executions')->first();
        $this->assertSame(['onedrive_file', 1], [$t->test_type, (int) $t->manual]);
        $this->assertSame($id, (int) DB::table('backup_jobs')->find($t->backup_job_id)->server_id);
        $this->assertSame('scheduled', DB::table('backup_jobs')->find($t->backup_job_id)->status, 'the stand-in, not a made-up backup');
    }

    public function test_an_every_server_schedule_for_a_365_test_reaches_tenants_only_and_does_not_stack_up(): void
    {
        $tenant = $this->tenant();
        $linux = $this->admin->post('/api/servers', ['hostname' => 'filesrv', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        DB::table('backup_jobs')->insert(['company_id' => $this->companyId, 'server_id' => $linux, 'backup_method' => 'rsync', 'status' => 'success', 'completed_time' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $sid = $this->schedule('sharepoint_site', 'all');
        $this->assertSame(1, TestScheduling::runDue($this->companyId));
        $this->assertSame([$tenant], DB::table('backup_test_executions as t')->join('backup_jobs as j', 'j.id', '=', 't.backup_job_id')->pluck('j.server_id')->map(fn ($v) => (int) $v)->all());

        DB::table('test_schedules')->where('id', $sid)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(0, TestScheduling::runDue($this->companyId), 'one is already waiting, so another is not added');
    }

    public function test_the_automatic_kind_of_schedule_still_needs_a_job_it_can_run_on_and_a_tenant_job_is_used_when_there_is_one(): void
    {
        $id = $this->tenant();
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $id, 'backup_method' => 'acronis_m365'])->json('data.id');
        DB::table('backup_jobs')->where('id', $job)->update(['status' => 'success', 'completed_time' => now()]);
        $this->schedule('m365_mailbox', 'servers', ['server_ids' => [$id]]);
        $this->assertSame(1, TestScheduling::runDue($this->companyId));
        $this->assertSame($job, (int) DB::table('backup_test_executions')->value('backup_job_id'));
        $this->assertSame(1, DB::table('backup_jobs')->where('server_id', $id)->count(), 'no stand-in when a real job exists');
    }

    /** The details a tester records for the item the test asked for, valid unless changed. */
    private function findings(int $id, array $over = [])
    {
        $sel = $this->admin->get("/api/manual-tests/$id")->json('data.selection');
        $point = now()->subHours(2);
        $f = ['recovery_point_date' => $point->format('Y-m-d'), 'recovery_point_time' => $point->format('H:i'), 'item_day' => $sel['day'], 'item_time' => '09:15', 'position_used' => $sel['position'],
            'item_subject' => 'Budget 2026', 'item_size_kb' => 42, 'attachment' => 'opens'];
        $this->assertNotSame('', $sel['text']);

        return $this->admin->put("/api/manual-tests/$id/findings", $over + $f + ($this->admin->get("/api/manual-tests/$id")->json('data.test_type') === 'sharepoint_site' ? ['permissions' => 'kept'] : []));
    }

    private function startedWithEverythingTicked(string $type): int
    {
        $id = $this->tenant();
        $tid = $this->admin->post("/api/servers/$id/manual-test", ['test_type' => $type])->assertStatus(201)->json('data.id');
        $this->admin->post("/api/manual-tests/$tid/start")->assertOk();
        $steps = $this->admin->get("/api/manual-tests/$tid")->json('data.steps');
        $this->admin->put("/api/manual-tests/$tid", ['checklist' => array_map(fn ($s) => ['id' => $s['id'], 'done' => true], $steps)])->assertOk();
        Storage::fake('local');
        foreach ($steps as $s) {
            for ($n = 0; $n < ($s['files'] ?? 0); $n++) {
                $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->token, 'Accept' => 'application/json'])
                    ->post("/api/manual-tests/$tid/evidence-file", ['file' => UploadedFile::fake()->createWithContent('shot'.$n.'.png', base64_decode(self::PNG)), 'step_id' => $s['id'], 'label' => 'Shot'])->assertStatus(201);
            }
        }

        return $tid;
    }

    public function test_a_onedrive_and_a_sharepoint_test_ask_for_a_random_item_by_day_and_position_like_the_mailbox_test(): void
    {
        $id = $this->tenant();
        $words = ['m365_mailbox' => ['email', 'Inbox'], 'onedrive_file' => ['file', 'OneDrive'], 'sharepoint_site' => ['document', 'document libraries'], 'teams_channel' => ['message', 'General channel']];
        foreach ($words as $type => [$noun, $where]) {
            $t = $this->admin->post("/api/servers/$id/manual-test", ['test_type' => $type])->assertStatus(201)->json('data');
            $sel = $t['selection'];
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d$/', $sel['day'], $type);
            $this->assertLessThanOrEqual((int) config('bms.manual_pick_max_position'), $sel['position']);
            $this->assertStringContainsString($noun, $sel['text']);
            $this->assertStringContainsString($where, $sel['text']);
            $this->assertStringContainsString('counting from the earliest that day', $sel['text']);
            $this->assertStringContainsString('say what you used', $sel['fallback']);
            $this->assertNotEmpty($t['fields'], "$type has details to record");
            DB::table('backup_test_executions')->where('id', $t['id'])->update(['test_status' => 'cancelled']);
        }
    }

    public function test_a_later_day_a_later_position_or_a_different_item_without_a_reason_is_refused_for_both(): void
    {
        foreach (['onedrive_file', 'sharepoint_site', 'teams_channel'] as $type) {
            $tid = $this->startedWithEverythingTicked($type);
            $sel = $this->admin->get("/api/manual-tests/$tid")->json('data.selection');
            $this->findings($tid, ['item_day' => date('Y-m-d', strtotime($sel['day'].' +1 day'))])->assertOk();
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400)->assertJsonFragment(['error' => 'You were asked for an item from '.$sel['day'].'. An item from a later day is not allowed.']);

            $this->findings($tid, ['position_used' => $sel['position'] + 1])->assertOk();
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400);
            $this->findings($tid, ['position_used' => max(1, $sel['position'] - 1 ?: 1), 'item_day' => date('Y-m-d', strtotime($sel['day'].' -1 day'))])->assertOk();
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400)->assertJsonFragment(['error' => 'You were asked to open: '.$sel['text'].' If you used a different item, say why and what you used in the details.']);

            $this->findings($tid, ['item_day' => date('Y-m-d', strtotime($sel['day'].' -1 day')), 'fallback_note' => 'That day had none, used the day before'])->assertOk();
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertOk();
        }
    }

    public function test_a_file_that_does_not_open_or_permissions_not_restored_cannot_be_passed(): void
    {
        $tid = $this->startedWithEverythingTicked('onedrive_file');
        $this->findings($tid, ['attachment' => 'fails'])->assertOk();
        $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400);

        $sp = $this->startedWithEverythingTicked('sharepoint_site');
        $this->findings($sp, ['permissions' => 'lost'])->assertOk();
        $this->admin->post("/api/manual-tests/$sp/complete", ['result' => 'passed'])->assertStatus(400)->assertJsonFragment(['error' => 'Permissions that were not restored are a failed restore. Complete the test as failed.']);
    }

    public function test_a_recovery_point_that_is_too_old_or_in_the_future_is_refused_for_both(): void
    {
        foreach (['onedrive_file', 'sharepoint_site', 'teams_channel'] as $type) {
            $tid = $this->startedWithEverythingTicked($type);
            $old = now()->subDays(10);
            $this->findings($tid, ['recovery_point_date' => $old->format('Y-m-d'), 'recovery_point_time' => '10:00', 'item_day' => $this->admin->get("/api/manual-tests/$tid")->json('data.selection.day')])->assertOk();
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400);
            $this->findings($tid, ['recovery_point_date' => now()->addDays(2)->format('Y-m-d')])->assertOk();
            $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400);
        }
    }

    public function test_a_schedule_gives_a_onedrive_a_sharepoint_and_a_teams_test_the_same_item_choice(): void
    {
        $id = $this->tenant();
        foreach (['onedrive_file', 'sharepoint_site', 'teams_channel'] as $type) {
            $this->schedule($type, 'servers', ['server_ids' => [$id]]);
        }
        $this->assertSame(3, TestScheduling::runDue($this->companyId));
        foreach (DB::table('backup_test_executions')->get() as $t) {
            $sel = json_decode($t->selection, true);
            $this->assertNotEmpty($sel['day'] ?? null, "{$t->test_type} was given something to find");
            $this->assertGreaterThanOrEqual(1, $sel['position']);
        }
    }

    public function test_the_teams_test_has_its_own_checklist_and_details_and_no_size_to_record(): void
    {
        $id = $this->tenant();
        $t = $this->admin->post("/api/servers/$id/manual-test", ['test_type' => 'teams_channel'])->assertStatus(201)->json('data');
        $this->assertSame('The Microsoft Team to test', $t['target_label']);
        $this->assertContains('locate', array_column($t['steps'], 'id'));
        $fields = array_column($t['fields'], 'id');
        $this->assertContains('position_used', $fields);
        $this->assertNotContains('item_size_kb', $fields, 'a message has no size');
        $this->assertStringContainsString('posted on', $t['selection']['text']);
        $this->assertStringContainsString('General channel', $t['selection']['text']);
        $types = collect($this->admin->get('/api/test-types')->json('data'))->keyBy('type');
        $this->assertFalse($types['teams_channel']['automated']);
        $this->assertSame('Microsoft Teams message', $types['teams_channel']['title']);
    }

    public function test_a_teams_message_that_is_missing_or_from_a_later_day_cannot_be_passed(): void
    {
        $tid = $this->startedWithEverythingTicked('teams_channel');
        $sel = $this->admin->get("/api/manual-tests/$tid")->json('data.selection');
        $this->findings($tid, ['attachment' => 'fails'])->assertOk();
        $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400)->assertJsonFragment(['error' => 'A message that does not open, or is damaged, is a failed restore. Complete the test as failed.']);
        $this->findings($tid, ['item_day' => date('Y-m-d', strtotime($sel['day'].' +1 day'))])->assertOk();
        $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertStatus(400);
        $this->findings($tid)->assertOk();
        $this->admin->post("/api/manual-tests/$tid/complete", ['result' => 'passed'])->assertOk();
    }
}
