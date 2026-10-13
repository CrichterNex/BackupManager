<?php

namespace Tests\Feature;

use App\Services\TestResults;
use App\Support\CompanyContext;
use App\Support\ManualSelection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\ApiClient;
use Tests\TestCase;

/** The random item to open, the checks on the tester's record of it, required screenshots, and random checking by a second person. */
class ManualVerificationTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $tech2;

    private ApiClient $tech3;

    private ApiClient $manager;

    private int $companyId;

    private int $tenantId;

    private int $jobId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->companyId = $this->makeCompany('verifyco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $a = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $b = $this->makeUser($this->companyId, 'tech2', 'company_technician');
        $this->makeUser($this->companyId, 'tech3', 'company_technician');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->tech2 = $this->login('tech2');
        $this->tech3 = $this->login('tech3');
        $this->manager = $this->login('mgr');
        $this->tenantId = $this->admin->post('/api/servers', ['hostname' => 'verify-365', 'os_type' => 'microsoft_365', 'backup_methods' => ['acronis_m365'], 'test_targets' => ['canary@contoso.com']])->assertStatus(201)->json('data.id');
        $this->jobId = $this->admin->post('/api/backup-jobs', ['server_id' => $this->tenantId, 'backup_method' => 'acronis_m365'])->assertStatus(201)->json('data.id');
        $this->admin->put("/api/servers/{$this->tenantId}/assignments", ['user_ids' => [$a, $b]])->assertOk();
    }

    // ---------------------------------------------------------------- helpers

    private function create(string $type = 'm365_mailbox'): int
    {
        return $this->admin->post("/api/backup-jobs/{$this->jobId}/manual-test", ['test_type' => $type])->assertStatus(201)->json('data.id');
    }

    private function show(int $id, ?ApiClient $who = null): array
    {
        return ($who ?? $this->admin)->get("/api/manual-tests/$id")->assertOk()->json('data');
    }

    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bms');
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function upload(ApiClient $who, int $id, UploadedFile $f, array $extra = [])
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer {$who->token}", 'Accept' => 'application/json'])->post("/api/manual-tests/$id/evidence-file", ['file' => $f] + $extra);
    }

    private function shot(int $id, string $stepId, ?ApiClient $who = null): void
    {
        $this->upload($who ?? $this->tech, $id, $this->realFile('shot.png', base64_decode(self::PNG)), ['step_id' => $stepId])->assertStatus(201);
    }

    private function proof(int $id, ?ApiClient $who = null): void
    {
        foreach ($this->show($id)['steps'] as $s) {
            for ($i = $s['screenshots_attached']; $i < ($s['files'] ?? 0); $i++) {
                $this->shot($id, $s['id'], $who);
            }
        }
    }

    private function findings(int $id, array $over = [], ?ApiClient $who = null)
    {
        $sel = $this->show($id)['selection'];
        $point = now()->subHours(2);
        $f = ['recovery_point_date' => $point->format('Y-m-d'), 'recovery_point_time' => $point->format('H:i'), 'item_day' => $sel['day'], 'item_time' => '09:15',
            'position_used' => $sel['position'], 'item_subject' => 'Quarterly figures', 'item_size_kb' => 42, 'attachment' => 'opens'];

        return ($who ?? $this->tech)->put("/api/manual-tests/$id/findings", $over + $f);
    }

    private function tickAll(int $id, ?ApiClient $who = null): void
    {
        $steps = array_map(fn ($s) => ['id' => $s['id'], 'done' => true], $this->show($id)['steps']);
        ($who ?? $this->tech)->put("/api/manual-tests/$id", ['checklist' => $steps])->assertOk();
    }

    /** Started, ticked, screenshots attached and the record filled in: ready to complete. */
    private function ready(int $id, ?ApiClient $who = null): void
    {
        $who ??= $this->tech;
        $who->post("/api/manual-tests/$id/start")->assertOk();
        $this->tickAll($id, $who);
        $this->proof($id, $who);
        $this->findings($id, [], $who)->assertOk();
    }

    private function pass(?ApiClient $who = null): int
    {
        $id = $this->create();
        $this->ready($id, $who);
        ($who ?? $this->tech)->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertOk();

        return $id;
    }

    private function refuses(int $id, string $contains, array $findings = []): void
    {
        $r = $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertStatus(400);
        $this->assertStringContainsString($contains, $r->json('error'));
    }

    // ---------------------------------------------------------------- the item to open

    public function test_a_microsoft_365_test_asks_for_a_random_weekday_item_and_other_tests_do_not(): void
    {
        $sel = $this->show($this->create())['selection'];
        $this->assertSame('Inbox', $sel['folder']);
        $day = new \DateTimeImmutable($sel['day']);
        $this->assertLessThanOrEqual(5, (int) $day->format('N'), 'a weekday');
        $this->assertTrue($day < new \DateTimeImmutable('today') && $day >= (new \DateTimeImmutable('today'))->modify('-14 days'));
        $this->assertGreaterThanOrEqual(1, $sel['position']);
        $this->assertLessThanOrEqual(10, $sel['position']);
        $this->assertStringContainsString($day->format('l j F Y'), $sel['text']);
        $this->assertStringContainsString(ManualSelection::ordinal($sel['position']), $sel['text']);
        $this->assertNotEmpty($sel['fallback']);
        $this->assertNull($this->show($this->create('recovery_point'))['selection']);
    }

    public function test_the_choice_is_unpredictable_and_always_fair(): void
    {
        $seen = [];
        for ($i = 0; $i < 60; $i++) {
            $s = ManualSelection::generate('UTC');
            $this->assertLessThanOrEqual(5, (int) (new \DateTimeImmutable($s['day']))->format('N'));
            $this->assertNotSame(date('Y-m-d'), $s['day'], 'never today, which has no complete backup');
            $seen["{$s['day']}#{$s['position']}"] = true;
        }
        $this->assertGreaterThan(15, count($seen), 'it varies');
    }

    public function test_ordinals_read_naturally(): void
    {
        foreach ([1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 10 => '10th', 11 => '11th', 12 => '12th', 13 => '13th', 21 => '21st', 22 => '22nd', 101 => '101st', 111 => '111th'] as $n => $expect) {
            $this->assertSame($expect, ManualSelection::ordinal($n));
        }
    }

    public function test_the_day_is_chosen_in_the_servers_own_time_zone(): void
    {
        $this->admin->patch("/api/servers/{$this->tenantId}", ['timezone' => 'Pacific/Kiritimati'])->assertOk();
        $this->assertSame('Pacific/Kiritimati', $this->admin->get("/api/servers/{$this->tenantId}")->json('data.timezone'));
        $this->admin->patch("/api/servers/{$this->tenantId}", ['timezone' => 'Mars/Base'])->assertStatus(400);
        $s = ManualSelection::generate('Pacific/Kiritimati');
        $this->assertLessThan((new \DateTimeImmutable('today', new \DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d'), $s['day']);
    }

    // ---------------------------------------------------------------- the record of the item

    public function test_the_details_are_validated_as_they_are_saved_and_only_for_a_test_that_has_them(): void
    {
        $id = $this->create();
        $this->tech->put("/api/manual-tests/$id/findings", ['item_day' => '2026-01-01'])->assertStatus(409);
        $this->tech->post("/api/manual-tests/$id/start");
        foreach ([['item_day' => 'tomorrow'], ['item_time' => '25:00'], ['item_size_kb' => -5], ['item_size_kb' => 'big'], ['attachment' => 'maybe'], ['recovery_point_date' => '07/10/2026']] as $bad) {
            $this->tech->put("/api/manual-tests/$id/findings", $bad)->assertStatus(400);
        }
        $this->tech->put("/api/manual-tests/$id/findings", ['item_subject' => 'Hello', 'item_size_kb' => 12])->assertOk();
        $this->tech->put("/api/manual-tests/$id/findings", ['item_time' => '08:30'])->assertOk();
        $values = collect($this->show($id)['fields'])->pluck('value', 'id');
        $this->assertSame(['Hello', 12, '08:30'], [$values['item_subject'], $values['item_size_kb'], $values['item_time']], 'saving more later keeps what is already there');
        $this->tech->put("/api/manual-tests/$id/findings", ['item_subject' => ''])->assertOk();
        $this->assertNull(collect($this->show($id)['fields'])->pluck('value', 'id')['item_subject'], 'clearing a field works');

        $other = $this->create('recovery_point');
        $this->tech->post("/api/manual-tests/$other/start");
        $this->tech->put("/api/manual-tests/$other/findings", ['item_subject' => 'x'])->assertStatus(400);
    }

    public function test_a_test_with_missing_details_cannot_pass_and_the_message_lists_them(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tickAll($id);
        $this->proof($id);
        $this->refuses($id, 'Fill in the details');
        $this->tech->put("/api/manual-tests/$id/findings", ['item_subject' => 'x']);
        $r = $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertStatus(400);
        $this->assertStringNotContainsString('Item: subject', $r->json('error'), 'what is filled in is not listed again');
        $this->assertStringContainsString('Recovery point: date', $r->json('error'));
    }

    public function test_an_item_dated_after_its_recovery_point_cannot_have_come_from_it(): void
    {
        $id = $this->create();
        $this->ready($id);
        $point = now()->subHours(2);
        $this->findings($id, ['recovery_point_date' => $point->format('Y-m-d'), 'recovery_point_time' => $point->format('H:i'),
            'item_day' => $this->show($id)['selection']['day']])->assertOk();
        $this->tech->put("/api/manual-tests/$id/findings", ['recovery_point_date' => now()->subDays(30)->format('Y-m-d'), 'recovery_point_time' => '03:00'])->assertOk();
        $this->refuses($id, 'dated after the recovery point');
    }

    public function test_an_old_or_future_recovery_point_cannot_pass(): void
    {
        $id = $this->create();
        $this->ready($id);
        // An item from long ago (with the reason given), so the item is never later than the recovery point and the age check is what is tested.
        $earlier = (new \DateTimeImmutable($this->show($id)['selection']['day']))->modify('-20 days')->format('Y-m-d');
        $old = now()->subHours(100);
        $this->tech->put("/api/manual-tests/$id/findings", ['recovery_point_date' => $old->format('Y-m-d'), 'recovery_point_time' => $old->format('H:i'),
            'item_day' => $earlier, 'fallback_note' => 'Used an earlier weekday.'])->assertOk();
        $this->refuses($id, '100 hours old, more than the 36 hours allowed');
        $future = now()->addHours(5);
        $this->tech->put("/api/manual-tests/$id/findings", ['recovery_point_date' => $future->format('Y-m-d'), 'recovery_point_time' => $future->format('H:i')])->assertOk();
        $this->refuses($id, 'in the future');
    }

    public function test_the_recovery_point_allowance_follows_the_jobs_schedule(): void
    {
        DB::table('backup_jobs')->where('id', $this->jobId)->update(['schedule' => '0 2 * * 1']);
        $id = $this->create();
        $this->ready($id);
        $old = now()->subHours(100);
        $earlier = (new \DateTimeImmutable($this->show($id)['selection']['day']))->modify('-20 days')->format('Y-m-d');
        $this->tech->put("/api/manual-tests/$id/findings", ['recovery_point_date' => $old->format('Y-m-d'), 'recovery_point_time' => $old->format('H:i'),
            'item_day' => $earlier, 'fallback_note' => 'Used an earlier weekday so the item pre-dates the recovery point.'])->assertOk();
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertOk()->assertJsonPath('data.test_status', 'success');
    }

    public function test_an_attachment_that_does_not_open_cannot_be_a_pass(): void
    {
        $id = $this->create();
        $this->ready($id);
        $this->findings($id, ['attachment' => 'fails'])->assertOk();
        $this->refuses($id, 'failed restore');
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'failed', 'notes' => 'The attachment would not open.'])->assertOk();
        $this->assertSame('failed', DB::table('backup_test_executions')->find($id)->test_status);
    }

    public function test_the_item_has_to_be_the_one_asked_for_or_the_tester_says_why_not(): void
    {
        $id = $this->create();
        $this->ready($id);
        $sel = $this->show($id)['selection'];
        $earlier = (new \DateTimeImmutable($sel['day']))->modify('-3 days')->format('Y-m-d');
        $later = (new \DateTimeImmutable($sel['day']))->modify('+1 day')->format('Y-m-d');

        $this->findings($id, ['item_day' => $earlier])->assertOk();
        $this->refuses($id, 'say why');
        $this->findings($id, ['item_day' => $later])->assertOk();
        $this->refuses($id, 'later day is not allowed');
        $this->findings($id, ['position_used' => $sel['position'] + 1])->assertOk();
        $this->refuses($id, 'later one is not allowed');
        $this->findings($id, ['item_day' => $earlier, 'fallback_note' => 'There was no email on that day, so I used the nearest earlier weekday.'])->assertOk();
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertOk()->assertJsonPath('data.test_status', 'success');
    }

    public function test_fewer_emails_that_day_is_allowed_with_a_note(): void
    {
        $id = $this->create();
        $this->ready($id);
        $sel = $this->show($id)['selection'];
        $this->findings($id, ['position_used' => max(1, $sel['position'] - 1), 'fallback_note' => $sel['position'] > 1 ? 'Only that many emails that day, so I took the last one.' : 'x'])->assertOk();
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertOk();
    }

    public function test_times_are_read_in_the_servers_time_zone(): void
    {
        $this->admin->patch("/api/servers/{$this->tenantId}", ['timezone' => 'Pacific/Kiritimati'])->assertOk();
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tickAll($id);
        $this->proof($id);
        $sel = $this->show($id)['selection'];
        $point = now()->setTimezone('Pacific/Kiritimati')->subHours(2);
        $this->tech->put("/api/manual-tests/$id/findings", ['recovery_point_date' => $point->format('Y-m-d'), 'recovery_point_time' => $point->format('H:i'), 'item_day' => $sel['day'], 'item_time' => '09:15',
            'position_used' => $sel['position'], 'item_subject' => 'x', 'item_size_kb' => 1, 'attachment' => 'none'])->assertOk();
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertOk()->assertJsonPath('data.test_status', 'success');
    }

    // ---------------------------------------------------------------- screenshots

    public function test_the_steps_that_ask_for_a_screenshot_need_a_real_picture_to_pass(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tickAll($id);
        $this->findings($id)->assertOk();
        $this->refuses($id, 'needs a screenshot');
        $this->upload($this->tech, $id, $this->realFile('notes.txt', 'not a picture'), ['step_id' => 'restore'])->assertStatus(201);
        $this->refuses($id, 'Step 4 needs a screenshot');
        $this->shot($id, 'restore');
        $this->refuses($id, 'Step 5 needs a screenshot');
        $this->shot($id, 'open');
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertOk();
    }

    public function test_the_screenshot_count_is_shown_for_each_step(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->shot($id, 'restore');
        $steps = collect($this->show($id)['steps'])->keyBy('id');
        $this->assertSame([1, 1], [$steps['restore']['files'], $steps['restore']['screenshots_attached']]);
        $this->assertSame(0, $steps['open']['screenshots_attached']);
    }

    public function test_a_screenshot_can_be_viewed_in_the_page_but_only_if_it_really_is_a_picture(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->shot($id, 'restore');
        $this->upload($this->tech, $id, $this->realFile('notes.txt', 'plain'), ['step_id' => 'restore']);
        $items = collect(collect($this->show($id)['steps'])->firstWhere('id', 'restore')['evidence_items']);
        $png = $items->firstWhere('file_name', 'shot.png');
        $txt = $items->firstWhere('file_name', 'notes.txt');
        $inline = $this->tech->get("/api/manual-tests/$id/evidence/{$png['id']}/file?inline=1")->assertOk();
        $this->assertSame('image/png', $inline->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $inline->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));
        $text = $this->tech->get("/api/manual-tests/$id/evidence/{$txt['id']}/file?inline=1")->assertOk();
        $this->assertStringContainsString('attachment', $text->headers->get('Content-Disposition'), 'anything else is still only a download');
        $this->assertSame('application/octet-stream', $text->headers->get('Content-Type'));
    }

    // ---------------------------------------------------------------- checking by a second person

    public function test_a_share_of_passes_chosen_at_random_is_sent_for_checking_and_the_admins_are_told(): void
    {
        config(['bms.manual_verify_percent' => 100]);
        $id = $this->pass();
        $this->assertSame('pending', DB::table('backup_test_executions')->find($id)->verify_state);
        $this->assertSame(1, DB::table('notifications')->where('event_type', 'verification_needed')->count());
        $this->assertTrue(collect($this->admin->get('/api/audit?limit=100')->json('data'))->pluck('action')->contains('manual_test_verification_requested'));
        $this->assertFalse($this->show($id, $this->tech)['can_verify'], 'not by the person who did it');
        $this->assertTrue($this->show($id, $this->tech2)['can_verify']);
    }

    public function test_when_the_share_is_zero_nothing_is_sent_and_a_failed_test_is_never_sent(): void
    {
        config(['bms.manual_verify_percent' => 0]);
        $this->assertNull(DB::table('backup_test_executions')->find($this->pass())->verify_state);
        config(['bms.manual_verify_percent' => 100]);
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'failed', 'notes' => 'No recovery point found.'])->assertOk();
        $this->assertNull(DB::table('backup_test_executions')->find($id)->verify_state);
    }

    public function test_the_share_really_is_roughly_what_is_configured(): void
    {
        config(['bms.manual_verify_percent' => 50]);
        $hits = 0;
        for ($i = 0; $i < 40; $i++) {
            $id = $this->pass();
            $hits += DB::table('backup_test_executions')->find($id)->verify_state === 'pending' ? 1 : 0;
        }
        $this->assertGreaterThan(5, $hits);
        $this->assertLessThan(35, $hits);
    }

    public function test_another_technician_can_confirm_it(): void
    {
        config(['bms.manual_verify_percent' => 100]);
        $id = $this->pass();
        $r = $this->tech2->post("/api/manual-tests/$id/verify", ['decision' => 'confirm', 'note' => 'Screenshots match the details.'])->assertOk();
        $this->assertSame(['confirmed', 'tech2'], [$r->json('data.verification.state'), $r->json('data.verification.verified_by')]);
        $t = DB::table('backup_test_executions')->find($id);
        $this->assertSame(['success', 'confirmed'], [$t->test_status, $t->verify_state]);
        $this->assertNotNull($t->verified_at);
        $this->tech2->post("/api/manual-tests/$id/verify", ['decision' => 'confirm'])->assertStatus(409)->assertJsonPath('error_code', 'TEST_010');
        $this->assertSame('tech2', collect($this->admin->get('/api/tests')->json('data'))->firstWhere('id', $id)['verified_username']);
    }

    public function test_nobody_can_check_their_own_test_and_the_wrong_people_cannot_check_at_all(): void
    {
        config(['bms.manual_verify_percent' => 100]);
        $id = $this->pass();
        $this->tech->post("/api/manual-tests/$id/verify", ['decision' => 'confirm'])->assertStatus(403);
        $this->tech3->post("/api/manual-tests/$id/verify", ['decision' => 'confirm'])->assertStatus(404);
        $this->manager->post("/api/manual-tests/$id/verify", ['decision' => 'confirm'])->assertStatus(403);
        $this->assertSame('pending', DB::table('backup_test_executions')->find($id)->verify_state);
        $this->admin->post("/api/manual-tests/$id/verify", ['decision' => 'confirm'])->assertOk();
    }

    public function test_an_admin_cannot_check_their_own_test_either(): void
    {
        config(['bms.manual_verify_percent' => 100]);
        $id = $this->create();
        $this->ready($id, $this->admin);
        $this->admin->post("/api/manual-tests/$id/complete", ['result' => 'passed'])->assertOk();
        $this->admin->post("/api/manual-tests/$id/verify", ['decision' => 'confirm'])->assertStatus(403);
        $this->tech->post("/api/manual-tests/$id/verify", ['decision' => 'confirm'])->assertOk();
    }

    public function test_rejecting_needs_a_reason_and_turns_the_pass_into_a_failure_everywhere(): void
    {
        config(['bms.manual_verify_percent' => 100]);
        $id = $this->pass();
        $this->tech2->post("/api/manual-tests/$id/verify", ['decision' => 'reject'])->assertStatus(400);
        $this->assertSame('success', DB::table('backup_test_executions')->find($id)->test_status);

        $this->tech2->post("/api/manual-tests/$id/verify", ['decision' => 'reject', 'note' => 'The screenshot shows a different date from the details.'])->assertOk()->assertJsonPath('data.test_status', 'failed');
        $t = DB::table('backup_test_executions')->find($id);
        $this->assertSame(['failed', 'rejected'], [$t->test_status, $t->verify_state]);
        $this->assertStringContainsString('different date', $t->error_message);
        $f = DB::table('backup_test_failures')->where('test_execution_id', $id)->first();
        $this->assertSame(['restore_failed', 'high'], [$f->failure_type, $f->severity]);
        $this->assertSame('failed', DB::table('backup_jobs')->find($this->jobId)->test_status);
        $this->assertSame('failed', DB::table('servers')->find($this->tenantId)->last_test_status);
        $this->assertSame(1, DB::table('notifications')->where('event_type', 'test_failed')->count());
        $actions = collect($this->admin->get('/api/audit?limit=100')->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('manual_test_rejected'));
    }

    public function test_only_a_recorded_pass_can_be_overturned(): void
    {
        $id = $this->create();
        $this->tech->post("/api/manual-tests/$id/start");
        $this->tech->post("/api/manual-tests/$id/complete", ['result' => 'failed', 'notes' => 'x'])->assertOk();
        $this->expectException(\RuntimeException::class);
        CompanyContext::run($this->companyId, fn () => TestResults::overturn($id, 'because', 1));
    }

    public function test_an_admin_can_send_any_passed_test_for_checking(): void
    {
        config(['bms.manual_verify_percent' => 0]);
        $id = $this->pass();
        $this->tech->post("/api/manual-tests/$id/request-verification")->assertStatus(403);
        $this->admin->post("/api/manual-tests/$id/request-verification")->assertOk()->assertJsonPath('data.verification.state', 'pending');
        $this->admin->post("/api/manual-tests/$id/request-verification")->assertStatus(409);
        $failed = $this->create();
        $this->tech->post("/api/manual-tests/$failed/start");
        $this->tech->post("/api/manual-tests/$failed/complete", ['result' => 'failed', 'notes' => 'x'])->assertOk();
        $this->admin->post("/api/manual-tests/$failed/request-verification")->assertStatus(409);
    }

    public function test_the_queue_shows_what_waits_for_a_second_person_and_who_may_act(): void
    {
        config(['bms.manual_verify_percent' => 100]);
        $id = $this->pass();
        $mine = collect($this->tech->get('/api/manual-tests?status=verify')->json('data'))->firstWhere('id', $id);
        $theirs = collect($this->tech2->get('/api/manual-tests?status=verify')->json('data'))->firstWhere('id', $id);
        $this->assertFalse($mine['can_verify']);
        $this->assertTrue($theirs['can_verify']);
        $this->assertSame([], $this->tech3->get('/api/manual-tests?status=verify')->json('data'), 'not assigned to the server');
        $this->tech2->post("/api/manual-tests/$id/verify", ['decision' => 'confirm'])->assertOk();
        $this->assertSame([], $this->tech2->get('/api/manual-tests?status=verify')->json('data'));
    }

    public function test_the_reviewer_sees_everything_needed_to_judge_it(): void
    {
        config(['bms.manual_verify_percent' => 100]);
        $id = $this->pass();
        $t = $this->show($id, $this->tech2);
        $this->assertSame('Quarterly figures', collect($t['fields'])->pluck('value', 'id')['item_subject']);
        $this->assertNotNull($t['selection']);
        $this->assertCount(1, collect($t['steps'])->firstWhere('id', 'open')['evidence_items']);
        $this->assertTrue($t['can_verify']);
    }
}
