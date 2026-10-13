<?php

namespace Tests\Feature;

use App\Models\BackupTestExecution;
use App\Models\TestSchedule;
use App\Services\Engine\Engine;
use App\Services\TestScheduling;
use App\Support\CompanyContext;
use App\Support\Constants;
use App\Support\ManualChecklists;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\ApiClient;
use Tests\TestCase;

/** Microsoft 365 tenants, and tests a person performs by hand with a checklist and evidence. */
class ManualTestsTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const SHA_A = 'a3f5c1d2e4b6a8c0d2e4f6a8b0c2d4e6f8a0b2c4d6e8f0a2b4c6d8e0f2a4b6c8';

    private const SHA_B = 'b3f5c1d2e4b6a8c0d2e4f6a8b0c2d4e6f8a0b2c4d6e8f0a2b4c6d8e0f2a4b6c8';

    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $tech2;

    private ApiClient $manager;

    private ApiClient $rival;

    private int $companyId;

    private int $tenantId;

    private int $jobId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->companyId = $this->makeCompany('manualco');
        $other = $this->makeCompany('rivalmanual');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $techId = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->makeUser($this->companyId, 'tech2', 'company_technician');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $this->makeUser($other, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->tech2 = $this->login('tech2');
        $this->manager = $this->login('mgr');
        $this->rival = $this->login('rival');
        $this->tenantId = $this->admin->post('/api/servers', ['hostname' => 'contoso-m365', 'os_type' => 'microsoft_365', 'backup_methods' => ['acronis_m365'],
            'test_targets' => ['  alice@contoso.com ', 'bob@contoso.com', 'alice@contoso.com', '', 'carol@contoso.com']])->assertStatus(201)->json('data.id');
        $this->jobId = $this->admin->post('/api/backup-jobs', ['server_id' => $this->tenantId, 'backup_method' => 'acronis_m365'])->assertStatus(201)->json('data.id');
        $this->admin->put("/api/servers/{$this->tenantId}/assignments", ['user_ids' => [$techId]])->assertOk();
    }

    // ---------------------------------------------------------------- the tenant kind

    public function test_a_microsoft_365_tenant_is_stored_with_a_tidy_list_of_things_to_sample(): void
    {
        $s = $this->admin->get("/api/servers/{$this->tenantId}")->json('data');
        $this->assertSame('microsoft_365', $s['os_type']);
        $this->assertSame(['alice@contoso.com', 'bob@contoso.com', 'carol@contoso.com'], $s['test_targets'], 'trimmed, de-duplicated, blanks dropped');
    }

    public function test_a_tenant_can_only_use_microsoft_365_methods_and_has_no_ip_and_a_machine_cannot_have_mailboxes(): void
    {
        $this->admin->post('/api/servers', ['hostname' => 'bad1', 'os_type' => 'microsoft_365', 'backup_methods' => ['rsync']])->assertStatus(400);
        $this->admin->post('/api/servers', ['hostname' => 'bad2', 'os_type' => 'microsoft_365', 'backup_methods' => ['acronis_m365'], 'ip_address' => '10.0.0.1'])->assertStatus(400);
        $this->admin->post('/api/servers', ['hostname' => 'bad3', 'os_type' => 'linux', 'backup_methods' => ['acronis_m365']])->assertStatus(400);
        $this->admin->post('/api/servers', ['hostname' => 'bad4', 'os_type' => 'linux', 'test_targets' => ['a@b.c']])->assertStatus(400);
        $this->admin->post('/api/servers', ['hostname' => 'ok1', 'os_type' => 'microsoft_365', 'backup_methods' => ['acronis_m365', 'microsoft_native_365']])->assertStatus(201);
        $this->admin->patch("/api/servers/{$this->tenantId}", ['backup_methods' => ['robocopy']])->assertStatus(400);
    }

    public function test_an_older_server_with_an_odd_combination_can_still_be_edited_for_anything_else(): void
    {
        $id = DB::table('servers')->insertGetId(['company_id' => $this->companyId, 'hostname' => 'legacy', 'os_type' => 'linux', 'backup_methods' => json_encode(['acronis_m365']), 'created_at' => now(), 'updated_at' => now()]);
        $this->admin->patch("/api/servers/$id", ['notes' => 'still editable'])->assertOk();
    }

    public function test_a_tenant_has_no_agent_and_is_not_offered_one(): void
    {
        $this->admin->post("/api/servers/{$this->tenantId}/agent")->assertStatus(409)->assertJsonPath('error_code', 'SERVER_005');
        $listed = collect($this->admin->get('/api/agents')->json('data.servers_without_agent'))->pluck('hostname');
        $this->assertFalse($listed->contains('contoso-m365'));
    }

    public function test_a_machine_with_an_agent_cannot_be_turned_into_a_tenant(): void
    {
        $id = $this->admin->post('/api/servers', ['hostname' => 'box', 'os_type' => 'linux'])->json('data.id');
        $this->admin->post("/api/servers/$id/agent")->assertStatus(201);
        $this->admin->patch("/api/servers/$id", ['os_type' => 'microsoft_365'])->assertStatus(409);
    }

    // ---------------------------------------------------------------- creating and finding manual tests

    private function create(string $type = 'm365_mailbox', array $extra = [], ?ApiClient $who = null): int
    {
        return ($who ?? $this->admin)->post("/api/backup-jobs/{$this->jobId}/manual-test", ['test_type' => $type] + $extra)->assertStatus(201)->json('data.id');
    }

    private function show(int $id, ?ApiClient $who = null): array
    {
        return ($who ?? $this->admin)->get("/api/manual-tests/$id")->assertOk()->json('data');
    }

    private function tickAll(int $id, ?ApiClient $who = null): void
    {
        $ids = array_map(fn ($s) => ['id' => $s['id'], 'done' => true], $this->show($id)['steps']);
        ($who ?? $this->tech)->put("/api/manual-tests/$id", ['checklist' => $ids])->assertOk();
    }

    /** Attach whatever the checklist insists on, so a test can be passed. */
    private function shot(int $id, string $stepId, ?ApiClient $who = null): void
    {
        $this->upload($who ?? $this->tech, $id, $this->realFile('shot.png', base64_decode(self::PNG)), ['step_id' => $stepId])->assertStatus(201);
    }

    /** A screenshot on every step that asks for one. */
    private function proof(int $id, ?ApiClient $who = null): void
    {
        foreach ($this->show($id)['steps'] as $s) {
            for ($i = $s['screenshots_attached']; $i < ($s['files'] ?? 0); $i++) {
                $this->shot($id, $s['id'], $who);
            }
        }
    }

    /** The item's record, consistent with what the test asked for. Pass overrides to break one thing. */
    private function findings(int $id, array $over = [], ?ApiClient $who = null)
    {
        $sel = $this->show($id)['selection'];
        $point = now()->subHours(2);
        $f = ['recovery_point_date' => $point->format('Y-m-d'), 'recovery_point_time' => $point->format('H:i'), 'item_day' => $sel['day'] ?? now()->subDays(3)->format('Y-m-d'),
            'item_time' => '09:15', 'position_used' => $sel['position'] ?? 1, 'item_subject' => 'Quarterly figures', 'item_size_kb' => 42, 'attachment' => 'opens'];

        return ($who ?? $this->tech)->put("/api/manual-tests/$id/findings", $over + $f);
    }

    private function giveRequiredEvidence(int $id, ?ApiClient $who = null): void
    {
        $who ??= $this->tech;
        $this->proof($id, $who);
        if ($this->show($id)['fields']) {
            $this->findings($id, [], $who)->assertOk();
        }
        foreach ($this->show($id)['steps'] as $s) {
            if ($s['required'] && $s['evidence'] === 'note') {
                $who->post("/api/manual-tests/$id/evidence", ['kind' => 'note', 'step_id' => $s['id'], 'label' => 'Note', 'text' => 'Done.'])->assertStatus(201);
            }
            if ($s['required'] && $s['evidence'] === 'hash') {
                $who->post("/api/manual-tests/$id/evidence", ['kind' => 'hash', 'step_id' => $s['id'], 'label' => 'Hash', 'algorithm' => 'sha256', 'original' => self::SHA_A, 'restored' => self::SHA_A])->assertStatus(201);
            }
        }
    }

    public function test_a_manual_test_picks_a_sample_from_the_tenants_list_and_rotates_through_them(): void
    {
        $first = $this->show($this->create())['target'];
        $this->assertContains($first, ['alice@contoso.com', 'bob@contoso.com', 'carol@contoso.com']);
        $this->admin->post('/api/manual-tests/'.DB::table('backup_test_executions')->value('id').'/cancel', ['reason' => 'next']);
        $seen = [$first];
        for ($i = 0; $i < 2; $i++) {
            $id = $this->create();
            $seen[] = $this->show($id)['target'];
            $this->admin->post("/api/manual-tests/$id/cancel", ['reason' => 'next'])->assertOk();
        }
        $this->assertCount(3, array_unique($seen), 'each mailbox is picked before any is repeated');
    }

    public function test_the_tester_can_name_the_target_and_only_one_manual_test_of_a_type_is_open_per_job(): void
    {
        $id = $this->create('m365_mailbox', ['target' => 'dave@contoso.com']);
        $this->assertSame('dave@contoso.com', $this->show($id)['target']);
        $this->admin->post("/api/backup-jobs/{$this->jobId}/manual-test", ['test_type' => 'm365_mailbox'])->assertStatus(409)->assertJsonPath('error_code', 'TEST_003');
        $this->create('recovery_point');
    }

    public function test_it_shows_the_target_the_checklist_and_help_to_the_technician_who_may_see_the_server(): void
    {
        $id = $this->create();
        $t = $this->show($id, $this->tech);
        $this->assertSame('The mailbox to test', $t['target_label']);
        $this->assertSame('Microsoft 365 mailbox', $t['title']);
        $this->assertCount(count(ManualChecklists::for('m365_mailbox')['steps']), $t['steps']);
        $this->assertTrue($t['can_start']);
        $this->assertFalse($t['can_edit'], 'not started yet');
    }

    public function test_every_test_type_has_a_checklist_with_valid_evidence_kinds(): void
    {
        foreach (Constants::TEST_TYPES as $type) {
            $steps = ManualChecklists::for($type)['steps'];
            $this->assertNotEmpty($steps, $type);
            $this->assertCount(count($steps), array_unique(array_column($steps, 'id')), "$type: step ids are unique");
            foreach ($steps as $s) {
                $this->assertContains($s['evidence'], ['none', 'note', 'hash']);
                $this->assertNotSame('', $s['text']);
            }
        }
    }

    public function test_a_technician_not_assigned_to_the_server_cannot_see_or_touch_its_manual_tests(): void
    {
        $id = $this->create();
        $this->tech2->get("/api/manual-tests/$id")->assertStatus(404);
        $this->tech2->post("/api/manual-tests/$id/start")->assertStatus(404);
        $this->assertSame([], $this->tech2->get('/api/manual-tests')->json('data'));
        $this->assertCount(1, $this->tech->get('/api/manual-tests')->json('data'));
        $this->rival->get("/api/manual-tests/$id")->assertStatus(404);
        $this->assertSame([], $this->rival->get('/api/manual-tests')->json('data'));
    }

    public function test_managers_can_read_but_not_do_anything(): void
    {
        $id = $this->create();
        $this->manager->get("/api/manual-tests/$id")->assertOk();
        $this->manager->get('/api/manual-tests')->assertOk();
        $this->manager->post("/api/manual-tests/$id/start")->assertStatus(403);
        $this->manager->post("/api/backup-jobs/{$this->jobId}/manual-test", ['test_type' => 'recovery_point'])->assertStatus(403);
    }

    public function test_the_engine_never_picks_up_a_manual_test_and_it_does_not_block_the_automatic_one(): void
    {
        $server = $this->admin->post('/api/servers', ['hostname' => 'fs-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $server, 'backup_method' => 'rsync'])->json('data.id');
        DB::table('backup_jobs')->where('id', $job)->update(['status' => 'success']);
        $manual = $this->admin->post("/api/backup-jobs/$job/manual-test", ['test_type' => 'random_restore'])->json('data.id');
        $this->assertSame(0, Engine::runDueTests($this->companyId));
        $this->assertSame('pending', DB::table('backup_test_executions')->find($manual)->test_status, 'the engine leaves it for a person');
        $this->assertSame(1, Engine::scheduleDueTests(), 'a manual test does not stop the weekly automatic one');
        $this->admin->post("/api/backup-jobs/$job/test", ['test_type' => 'random_restore'])->assertStatus(409);
    }

    // ---------------------------------------------------------------- doing it

    public function test_starting_assigns_the_test_and_another_technician_cannot_barge_in_but_an_admin_can_take_over(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start")->assertOk()->assertJsonPath('data.test_status', 'running')->assertJsonPath('data.assigned_username', 'tech')->assertJsonPath('data.can_edit', true);
        $this->assertSame(DB::table('users')->where('username', 'tech')->value('id'), DB::table('backup_test_executions')->find($id)->executed_by);
        $this->admin->put("/api/servers/{$this->tenantId}/assignments", ['user_ids' => [DB::table('users')->where('username', 'tech')->value('id'), DB::table('users')->where('username', 'tech2')->value('id')]]);
        $this->tech2->post("/api/manual-tests/$id/start")->assertStatus(409)->assertJsonPath('error_code', 'TEST_006');
        $this->tech2->put("/api/manual-tests/$id", ['checklist' => [['id' => 'find', 'done' => true]]])->assertStatus(403);
        $this->admin->post("/api/manual-tests/$id/start")->assertOk()->assertJsonPath('data.assigned_username', 'admin');
    }

    public function test_the_checklist_cannot_be_changed_before_starting_or_with_made_up_steps(): void
    {
        $id = $this->create();
        $this->tech->put("/api/manual-tests/$id", ['checklist' => [['id' => 'find', 'done' => true]]])->assertStatus(409);
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tech->put("/api/manual-tests/$id", ['checklist' => [['id' => 'nonsense', 'done' => true]]])->assertStatus(400);
        $this->tech->put("/api/manual-tests/$id", ['checklist' => [['id' => 'find', 'done' => true], ['id' => 'point', 'done' => false]]])->assertOk();
        $done = collect($this->show($id)['steps'])->pluck('done', 'id');
        $this->assertTrue($done['find']);
        $this->assertFalse($done['point']);
    }

    public function test_fingerprints_must_be_real_hashes_and_are_tidied(): void
    {
        $id = $this->create('random_restore');
        $this->tech->post("/api/manual-tests/$id/start");
        $base = ['kind' => 'hash', 'label' => 'File', 'step_id' => 'compare', 'algorithm' => 'sha256'];
        foreach ([['original' => 'not-hex'], ['original' => 'abc123'], ['original' => self::SHA_A, 'restored' => 'zz'.substr(self::SHA_A, 2)], ['algorithm' => 'sha999', 'original' => self::SHA_A]] as $bad) {
            $this->tech->post("/api/manual-tests/$id/evidence", $bad + $base)->assertStatus(400);
        }
        $r = $this->tech->post("/api/manual-tests/$id/evidence", ['original' => strtoupper(self::SHA_A), 'restored' => ' '.self::SHA_A.' '] + $base)->assertStatus(201);
        $e = collect($r->json('data.steps'))->firstWhere('id', 'compare')['evidence_items'][0];
        $this->assertSame([self::SHA_A, self::SHA_A], [$e['value_a'], $e['value_b']], 'upper case and spaces are normalised so a match is a real match');
    }

    public function test_evidence_must_belong_to_a_real_step_and_notes_need_text(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tech->post("/api/manual-tests/$id/evidence", ['kind' => 'note', 'label' => 'x', 'text' => 'hi', 'step_id' => 'made-up'])->assertStatus(400);
        $this->tech->post("/api/manual-tests/$id/evidence", ['kind' => 'note', 'label' => 'x'])->assertStatus(400);
        $this->tech->post("/api/manual-tests/$id/evidence", ['kind' => 'note', 'label' => 'General', 'text' => 'Console was slow'])->assertStatus(201)->assertJsonPath('data.other_evidence.0.value_a', 'Console was slow');
    }

    public function test_evidence_can_be_removed_only_by_whoever_added_it_or_an_admin(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $eid = $this->tech->post("/api/manual-tests/$id/evidence", ['kind' => 'note', 'label' => 'x', 'text' => 'hi'])->json('data.other_evidence.0.id');
        $this->admin->del("/api/manual-tests/$id/evidence/$eid")->assertOk();
        $this->assertSame(0, DB::table('test_evidence')->count());
    }

    private function upload(ApiClient $who, int $id, UploadedFile $f, array $extra = [])
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer {$who->token}", 'Accept' => 'application/json'])->post("/api/manual-tests/$id/evidence-file", ['file' => $f] + $extra);
    }

    public function test_a_screenshot_can_be_attached_and_is_only_ever_downloaded_never_shown(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $r = $this->upload($this->tech, $id, UploadedFile::fake()->createWithContent('shot.png', base64_decode(self::PNG)), ['step_id' => 'restore', 'label' => 'Restore result'])->assertStatus(201);
        $e = collect($r->json('data.steps'))->firstWhere('id', 'restore')['evidence_items'][0];
        $this->assertSame('Restore result', $e['label']);
        $this->assertTrue($e['has_file']);
        $this->assertArrayNotHasKey('file_path', $e, 'the storage location is never exposed');
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $file = $this->tech->get("/api/manual-tests/$id/evidence/{$e['id']}/file")->assertOk();
        $this->assertStringContainsString('attachment', $file->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $file->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/octet-stream', $file->headers->get('Content-Type'));
    }

    /** A real file on disk, so the server looks at its content like it does for a genuine upload. (Laravel's fake files guess from the name.) */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bms');
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_files_that_are_not_what_they_claim_or_not_allowed_are_refused(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        foreach ([
            $this->realFile('evil.png', "<?php system('id');"),
            $this->realFile('page.html', '<script>alert(1)</script>'),
            $this->realFile('run.exe', 'MZ'),
            $this->realFile('notes.txt', base64_decode(self::PNG)),
            $this->realFile('shot.png', 'plain text pretending to be a picture'),
            $this->realFile('data.json', '#!/bin/sh
rm -rf /'),
        ] as $bad) {
            $this->upload($this->tech, $id, $bad)->assertStatus(400);
        }
        $this->upload($this->tech, $id, UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'))->assertStatus(400);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertSame(0, DB::table('test_evidence')->count());
        $this->upload($this->tech, $id, $this->realFile('real.png', base64_decode(self::PNG)))->assertStatus(201);
        $this->upload($this->tech, $id, $this->realFile('real.txt', 'just some notes'))->assertStatus(201);
    }

    public function test_only_people_who_may_see_the_test_can_download_its_files(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $eid = collect($this->upload($this->tech, $id, UploadedFile::fake()->createWithContent('a.txt', 'evidence'))->json('data.other_evidence'))->first()['id'];
        $this->tech2->get("/api/manual-tests/$id/evidence/$eid/file")->assertStatus(404);
        $this->rival->get("/api/manual-tests/$id/evidence/$eid/file")->assertStatus(404);
        $this->manager->get("/api/manual-tests/$id/evidence/$eid/file")->assertOk();
        $this->tech->get("/api/manual-tests/$id/evidence/99999/file")->assertStatus(404);
    }

    public function test_a_file_is_removed_from_storage_when_its_evidence_is_deleted(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $eid = collect($this->upload($this->tech, $id, UploadedFile::fake()->createWithContent('a.txt', 'evidence'))->json('data.other_evidence'))->first()['id'];
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->tech->del("/api/manual-tests/$id/evidence/$eid")->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    // ---------------------------------------------------------------- finishing

    public function test_a_test_cannot_be_passed_with_steps_unticked_or_required_evidence_missing(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $r = $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertStatus(400);
        $this->assertStringContainsString('Step 1 is not ticked', $r->json('error'));
        $this->tickAll($id);
        $r = $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertStatus(400);
        $this->assertStringContainsString('needs a screenshot', $r->json('error'));
        $this->assertSame('running', DB::table('backup_test_executions')->find($id)->test_status);
    }

    public function test_hashes_that_differ_can_never_be_a_pass(): void
    {
        $id = $this->create('random_restore');
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tickAll($id);
        $this->tech->post("/api/manual-tests/$id/evidence", ['kind' => 'note', 'step_id' => 'pick', 'label' => 'Path', 'text' => '/data/a.txt'])->assertStatus(201);
        $this->tech->post("/api/manual-tests/$id/evidence", ['kind' => 'hash', 'step_id' => 'compare', 'label' => 'a.txt', 'algorithm' => 'sha256', 'original' => self::SHA_A, 'restored' => self::SHA_B])->assertStatus(201);
        $this->shot($id, 'restore');
        $r = $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertStatus(400);
        $this->assertStringContainsString('do not match', $r->json('error'));
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'failed', 'notes' => 'The restored file differs from the original.'])->assertOk();
        $f = DB::table('backup_test_failures')->first();
        $this->assertSame('hash_mismatch', $f->failure_type, 'a mismatch with no type chosen is recorded as a hash mismatch');
        $this->assertSame(self::SHA_A, $f->expected_value);
        $this->assertSame(self::SHA_B, $f->actual_value);
    }

    public function test_a_complete_pass_is_recorded_through_the_normal_path_and_cannot_be_changed_afterwards(): void
    {
        $id = $this->create();
        $target = $this->show($id)['target'];
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tickAll($id);
        $this->giveRequiredEvidence($id);
        $r = $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed', 'notes' => 'All good.'])->assertOk();
        $this->assertSame('success', $r->json('data.test_status'));
        $t = DB::table('backup_test_executions')->find($id);
        $this->assertStringContainsString('Done by hand by tech', $t->summary);
        $this->assertStringContainsString($target, $t->summary);
        $this->assertSame('success', $t->test_status);
        $this->assertSame(1, (int) DB::table('backup_jobs')->where('id', $this->jobId)->where('test_status', 'passed')->count());
        $this->assertSame('passed', DB::table('servers')->find($this->tenantId)->last_test_status);
        $this->assertSame(0, DB::table('backup_test_failures')->count());
        $this->tech->put("/api/manual-tests/$id", ['checklist' => [['id' => 'find', 'done' => false]]])->assertStatus(409);
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertStatus(409);
        $this->assertSame('success', collect($this->admin->get('/api/tests')->json('data'))->firstWhere('id', $id)['test_status']);
        $row = collect($this->admin->get('/api/tests')->json('data'))->firstWhere('id', $id);
        $this->assertTrue($row['manual']);
        $this->assertSame($target, $row['target']);
        $this->assertSame('tech', $row['assigned_username']);
    }

    public function test_a_failed_manual_test_needs_notes_and_raises_a_failure_and_a_notification_like_any_other(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'failed'])->assertStatus(400);
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'failed', 'notes' => 'The mailbox had no recovery points.', 'failure_type' => 'file_missing', 'severity' => 'critical'])->assertOk();
        $f = DB::table('backup_test_failures')->first();
        $this->assertSame(['file_missing', 'critical'], [$f->failure_type, $f->severity]);
        $this->assertSame('The mailbox had no recovery points.', $f->error_message);
        $this->assertSame('failed', DB::table('backup_test_executions')->find($id)->test_status);
        $this->assertGreaterThan(0, DB::table('notifications')->where('event_type', 'test_failed')->count());
        $this->assertSame(0, DB::table('backup_test_executions')->where('retry_of', $id)->count(), 'a person already judged it, so there is no quiet retry');
    }

    public function test_cancelling_needs_a_reason_and_belongs_to_whoever_is_doing_it_or_an_admin(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/cancel", [])->assertStatus(400);
        $this->tech->post("/api/manual-tests/$id/start");
        $this->admin->put("/api/servers/{$this->tenantId}/assignments", ['user_ids' => [DB::table('users')->where('username', 'tech')->value('id'), DB::table('users')->where('username', 'tech2')->value('id')]]);
        $this->tech2->post("/api/manual-tests/$id/cancel", ['reason' => 'x'])->assertStatus(403);
        $this->tech->post("/api/manual-tests/$id/cancel", ['reason' => 'The mailbox was deleted'])->assertOk()->assertJsonPath('data.test_status', 'cancelled');
        $this->assertStringContainsString('The mailbox was deleted', DB::table('backup_test_executions')->find($id)->error_message);
        $this->assertNull(DB::table('servers')->find($this->tenantId)->last_test_status, 'a cancelled test is not a result');
    }

    public function test_the_whole_story_is_in_the_audit_log(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tickAll($id);
        $this->giveRequiredEvidence($id);
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertOk();
        $actions = collect($this->admin->get('/api/audit?limit=100')->json('data'))->pluck('action');
        foreach (['manual_test_created', 'manual_test_started', 'manual_test_evidence_added', 'manual_test_completed'] as $a) {
            $this->assertTrue($actions->contains($a), $a);
        }
    }

    // ---------------------------------------------------------------- scheduling manual tests

    public function test_a_schedule_can_queue_tests_for_a_person_including_ones_bms_cannot_run_and_platform_backups(): void
    {
        $body = ['name' => 'Monthly mailbox check', 'test_type' => 'm365_mailbox', 'target_type' => 'all', 'frequency' => 'monthly', 'run_day' => 1, 'run_time' => '08:00', 'timezone' => 'UTC'];
        $this->admin->post('/api/test-schedules', $body)->assertStatus(400);
        $id = $this->admin->post('/api/test-schedules', $body + ['manual' => true])->assertStatus(201)->assertJsonPath('data.manual', true)->json('data.id');
        DB::table('backup_jobs')->where('id', $this->jobId)->update(['status' => 'success']);
        DB::table('test_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(1, TestScheduling::runDue($this->companyId));
        $t = DB::table('backup_test_executions')->where('manual', 1)->first();
        $this->assertSame('m365_mailbox', $t->test_type);
        $this->assertSame('pending', $t->test_status);
        $this->assertNull($t->executed_by, 'nobody has done it yet');
        $this->assertContains($t->target, ['alice@contoso.com', 'bob@contoso.com', 'carol@contoso.com']);
        $this->assertSame(0, Engine::runDueTests($this->companyId));
        $this->assertSame(0, TestScheduling::runDue($this->companyId));
        DB::table('test_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(0, TestScheduling::runDue($this->companyId), 'one is already waiting, so another is not stacked up');
    }

    public function test_run_now_on_a_manual_schedule_does_not_record_the_admin_as_the_tester(): void
    {
        $id = $this->admin->post('/api/test-schedules', ['name' => 'M', 'test_type' => 'recovery_point', 'manual' => true, 'target_type' => 'job', 'backup_job_id' => $this->jobId,
            'frequency' => 'daily', 'run_time' => '08:00', 'timezone' => 'UTC'])->assertStatus(201)->json('data.id');
        $this->admin->post("/api/test-schedules/$id/run")->assertStatus(202);
        $this->assertNull(DB::table('backup_test_executions')->where('manual', 1)->value('executed_by'));
        $this->assertSame(1, TestSchedule::withoutGlobalScopes()->where('manual', 1)->count());
        $this->assertSame(1, CompanyContext::run($this->companyId, fn () => BackupTestExecution::where('manual', true)->count()));
    }

    // ---------------------------------------------------------------- reference items: no need for the mailbox owner or the original

    private function withReferences(?array $items = null): void
    {
        $this->admin->patch("/api/servers/{$this->tenantId}", ['reference_items' => $items ?? [
            ['label' => 'restore-check.pdf', 'hash' => strtoupper(self::SHA_A), 'where' => 'canary@contoso.com / Inbox'],
            ['label' => 'restore-check.docx', 'hash' => self::SHA_B, 'where' => 'canary@contoso.com / Documents'],
        ]])->assertOk();
    }

    public function test_reference_items_are_tidied_and_their_algorithm_is_read_from_the_fingerprint(): void
    {
        $this->withReferences([['label' => ' report.pdf ', 'hash' => strtoupper(self::SHA_A).' ', 'where' => ' Inbox '], ['label' => 'old.txt', 'hash' => str_repeat('ab', 20)]]);
        $items = $this->admin->get("/api/servers/{$this->tenantId}")->json('data.reference_items');
        $this->assertSame(['label' => 'report.pdf', 'algorithm' => 'sha256', 'hash' => self::SHA_A, 'where' => 'Inbox'], $items[0]);
        $this->assertSame(['sha256', 'sha1'], array_column($items, 'algorithm'));
    }

    public function test_a_reference_item_must_have_a_real_fingerprint_and_a_unique_name_and_only_a_tenant_has_them(): void
    {
        foreach ([[['label' => 'x', 'hash' => 'not-hex']], [['label' => 'x', 'hash' => 'abc123']], [['label' => 'x', 'hash' => self::SHA_A], ['label' => 'X', 'hash' => self::SHA_B]]] as $bad) {
            $this->admin->patch("/api/servers/{$this->tenantId}", ['reference_items' => $bad])->assertStatus(400);
        }
        $machine = $this->admin->post('/api/servers', ['hostname' => 'plain-box', 'os_type' => 'linux'])->json('data.id');
        $this->admin->patch("/api/servers/$machine", ['reference_items' => [['label' => 'x', 'hash' => self::SHA_A]]])->assertStatus(400);
    }

    public function test_a_test_is_given_a_reference_item_and_the_checklist_asks_only_for_the_restored_fingerprint(): void
    {
        $this->withReferences();
        $t = $this->show($this->create());
        $this->assertContains($t['reference']['label'], ['restore-check.pdf', 'restore-check.docx']);
        $this->assertSame('sha256', $t['reference']['algorithm']);
        $step = collect($t['steps'])->firstWhere('id', 'fingerprint');
        $this->assertTrue($step['reference']);
        $this->assertStringContainsString('never need the mailbox owner or the original', $step['text']);
        $this->assertNull(collect($t['steps'])->firstWhere('id', 'compare'), 'the old compare-with-the-original step is gone');
    }

    public function test_without_a_reference_item_the_checklist_needs_no_original_and_says_so(): void
    {
        $t = $this->show($this->create());
        $this->assertNull($t['reference']);
        $ids = array_column($t['steps'], 'id');
        $this->assertContains('restore', $ids);
        $this->assertContains('open', $ids);
        $this->assertNotContains('compare', $ids, 'there is no original to compare with');
        $this->assertNotContains('fingerprint', $ids);
        $this->assertNotNull($t['selection'], 'it says which item to open');
        $this->assertNotEmpty($t['fields'], 'and the tester records the item in fields BMS checks');
    }

    public function test_the_tester_enters_only_the_restored_fingerprint_and_cannot_change_the_original(): void
    {
        $this->withReferences([['label' => 'restore-check.pdf', 'hash' => self::SHA_A, 'where' => 'canary']]);
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tech->post("/api/manual-tests/$id/evidence", ['kind' => 'hash', 'step_id' => 'fingerprint', 'label' => 'x', 'algorithm' => 'sha256'])->assertStatus(400);
        $this->tech->post("/api/manual-tests/$id/evidence", ['kind' => 'hash', 'step_id' => 'fingerprint', 'label' => 'x', 'restored' => 'not-hex'])->assertStatus(400);
        $r = $this->tech->post("/api/manual-tests/$id/evidence", ['kind' => 'hash', 'step_id' => 'fingerprint', 'label' => 'whatever', 'algorithm' => 'md5',
            'original' => self::SHA_B, 'restored' => strtoupper(self::SHA_A)])->assertStatus(201);
        $e = collect($r->json('data.steps'))->firstWhere('id', 'fingerprint')['evidence_items'][0];
        $this->assertSame(['restore-check.pdf', 'sha256', self::SHA_A, self::SHA_A], [$e['label'], $e['algorithm'], $e['value_a'], $e['value_b']], 'the expected fingerprint is the one BMS holds, whatever the tester typed');
    }

    public function test_a_matching_restored_fingerprint_passes_and_a_different_one_never_does(): void
    {
        $this->withReferences([['label' => 'restore-check.pdf', 'hash' => self::SHA_A, 'where' => 'canary']]);
        $ok = $this->create();
        $this->tech->post("/api/manual-tests/$ok/start");
        $this->tickAll($ok);
        $this->proof($ok);
        $this->findings($ok)->assertOk();
        $r = $this->tech->post("/api/manual-tests/$ok/complete", ['result' => 'passed'])->assertStatus(400);
        $this->assertStringContainsString('fingerprint of the restored copy', $r->json('error'));
        $this->tech->post("/api/manual-tests/$ok/evidence", ['kind' => 'hash', 'step_id' => 'fingerprint', 'label' => 'x', 'restored' => self::SHA_A])->assertStatus(201);
        $this->tech->post("/api/manual-tests/$ok/complete", ['result' => 'passed'])->assertOk()->assertJsonPath('data.test_status', 'success');
        $t = DB::table('backup_test_executions')->find($ok);
        $this->assertSame([self::SHA_A, self::SHA_A, 1], [$t->hash_original, $t->hash_restored, (int) $t->hash_match]);

        $bad = $this->create();
        $this->tech->post("/api/manual-tests/$bad/start");
        $this->tickAll($bad);
        $this->proof($bad);
        $this->findings($bad)->assertOk();
        $this->tech->post("/api/manual-tests/$bad/evidence", ['kind' => 'hash', 'step_id' => 'fingerprint', 'label' => 'x', 'restored' => self::SHA_B])->assertStatus(201);
        $this->assertStringContainsString('do not match', $this->tech->post("/api/manual-tests/$bad/complete", ['result' => 'passed'])->assertStatus(400)->json('error'));
        $this->tech->post("/api/manual-tests/$bad/complete", ['result' => 'failed', 'notes' => 'The restored item is not the one we put there.'])->assertOk();
        $f = DB::table('backup_test_failures')->first();
        $this->assertSame(['hash_mismatch', self::SHA_A, self::SHA_B], [$f->failure_type, $f->expected_value, $f->actual_value]);
    }

    public function test_the_item_is_copied_into_the_test_so_editing_the_list_later_changes_nothing(): void
    {
        $this->withReferences([['label' => 'restore-check.pdf', 'hash' => self::SHA_A, 'where' => 'canary']]);
        $id = $this->create();
        $this->withReferences([['label' => 'replacement.pdf', 'hash' => self::SHA_B, 'where' => 'other']]);
        $this->assertSame(['restore-check.pdf', self::SHA_A], [$this->show($id)['reference']['label'], $this->show($id)['reference']['hash']]);
    }

    public function test_consecutive_tests_use_a_different_reference_item_when_there_is_a_choice(): void
    {
        $this->withReferences();
        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $id = $this->create();
            $seen[] = $this->show($id)['reference']['label'];
            $this->admin->post("/api/manual-tests/$id/cancel", ['reason' => 'next'])->assertOk();
        }
        foreach (range(1, 3) as $i) {
            $this->assertNotSame($seen[$i - 1], $seen[$i], 'never the same item twice in a row');
        }
    }

    public function test_only_the_microsoft_365_mailbox_test_uses_a_reference_item_and_scheduled_tests_get_one_too(): void
    {
        $this->withReferences();
        $this->assertNull($this->show($this->create('recovery_point'))['reference']);
        $id = $this->admin->post('/api/test-schedules', ['name' => 'Mailbox', 'test_type' => 'm365_mailbox', 'manual' => true, 'target_type' => 'all', 'frequency' => 'monthly', 'run_day' => 1, 'run_time' => '08:00', 'timezone' => 'UTC'])->json('data.id');
        DB::table('backup_jobs')->where('id', $this->jobId)->update(['status' => 'success']);
        DB::table('test_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(1, TestScheduling::runDue($this->companyId));
        $ref = json_decode(DB::table('backup_test_executions')->where('test_type', 'm365_mailbox')->value('reference'), true);
        $this->assertContains($ref['label'], ['restore-check.pdf', 'restore-check.docx']);
    }
}
