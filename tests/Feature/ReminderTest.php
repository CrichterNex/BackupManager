<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Engine\Engine;
use App\Services\Reminders;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Tests\ApiClient;
use Tests\TestCase;

/** The daily reminder mail: who gets it, when, what it says, and that it stays quiet when there is nothing to say. */
class ReminderTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $manager;

    private int $companyId;

    private int $techId;

    /** A Wednesday morning in UTC. */
    private function wed(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-14 08:05:00', 'UTC');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array']);
        $this->companyId = $this->makeCompany('remco');
        DB::table('companies')->where('id', $this->companyId)->update(['name' => 'Remco']);
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $this->techId = $this->makeUser($this->companyId, 'tech', 'company_technician');
        $this->admin = $this->login('admin');
        $this->manager = $this->login('mgr');
        $this->tech = $this->login('tech');
        // The daily mail goes out in the 8 o'clock hour, UTC.
        $this->admin->put('/api/reminders', ['send_hour' => 8, 'timezone' => 'UTC', 'enabled' => true])->assertOk();
    }

    private function server(string $host, ?string $lastTest = null): int
    {
        return DB::table('servers')->insertGetId(['company_id' => $this->companyId, 'hostname' => $host, 'os_type' => 'linux', 'criticality' => 'standard', 'backup_methods' => '["rsync"]', 'active' => 1,
            'last_test_time' => $lastTest, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function job(int $server): int
    {
        return DB::table('backup_jobs')->insertGetId(['company_id' => $this->companyId, 'server_id' => $server, 'backup_method' => 'rsync', 'status' => 'success', 'completed_time' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function backupProblem(int $server, string $severity = 'high', string $kind = 'failed'): void
    {
        DB::table('backup_failures')->insert(['company_id' => $this->companyId, 'server_id' => $server, 'source' => 'acronis', 'backup_method' => 'acronis', 'kind' => $kind, 'severity' => $severity, 'message' => 'm', 'active' => 1,
            'resolved' => 0, 'missed_runs' => 2, 'first_seen_at' => now()->subHours(30), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function manualTest(int $job, ?int $assignedTo = null): int
    {
        return DB::table('backup_test_executions')->insertGetId(['company_id' => $this->companyId, 'backup_job_id' => $job, 'test_type' => 'm365_mailbox', 'test_status' => 'pending', 'manual' => 1, 'assigned_to' => $assignedTo,
            'scheduled_time' => now(), 'created_at' => now()->subDays(3), 'updated_at' => now()]);
    }

    /** @return list<SentMessage> */
    private function mails(): array
    {
        return array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
    }

    private function to(object $m): string
    {
        return $m->getOriginalMessage()->getTo()[0]->getAddress();
    }

    private function digest(string $username): array
    {
        return CompanyContext::run($this->companyId, fn () => Reminders::digest(User::where('username', $username)->first()));
    }

    // ---------------------------------------------------------------- what it says

    public function test_an_admins_digest_lists_what_is_outstanding_in_each_part(): void
    {
        $web = $this->server('web01');
        $this->backupProblem($web);
        $this->manualTest($this->job($web));
        $this->server('old01', now()->subDays(45)->toDateTimeString());
        $this->server('fresh01', now()->subDays(2)->toDateTimeString());
        DB::table('integrations')->insert(['company_id' => $this->companyId, 'platform' => 'acronis', 'name' => 'Acronis EU', 'base_url' => 'https://x.test', 'credentials' => encrypt(['a' => 'b']), 'active' => 1,
            'stale_after_hours' => 36, 'last_sync_status' => 'error', 'last_sync_error' => 'Acronis rejected the API client credentials.', 'created_at' => now(), 'updated_at' => now()]);

        $d = $this->digest('admin');
        $this->assertSame(1, $d['backup_problems']['total']);
        $this->assertSame('web01', $d['backup_problems']['rows'][0]['server']);
        $this->assertStringContainsString('2 backups missed', $d['backup_problems']['rows'][0]['what']);
        $this->assertSame(1, $d['tests_waiting']['total']);
        $this->assertSame(['old01', 'web01'], collect($d['untested']['rows'])->pluck('server')->sort()->values()->all(), 'a server never or long ago tested, not the one tested two days ago');
        $this->assertSame(1, $d['connections']['total']);
        $this->assertGreaterThanOrEqual(30, $d['backup_problems']['rows'][0]['hours']);
    }

    public function test_a_technician_gets_only_the_tests_they_have_to_do(): void
    {
        $mine = $this->server('mine01');
        $other = $this->server('other01');
        DB::table('server_assignments')->insert(['company_id' => $this->companyId, 'server_id' => $mine, 'user_id' => $this->techId]);
        $this->manualTest($this->job($mine));                       // waiting on a server of theirs
        $this->manualTest($this->job($other));                      // somebody else's server
        $this->manualTest($this->job($other), $this->techId);       // assigned to them directly
        $this->backupProblem($mine);

        $d = $this->digest('tech');
        $this->assertSame(2, $d['tests_waiting']['total']);
        $this->assertSame([0, 0, 0, 0], [$d['backup_problems']['total'], $d['test_failures']['total'], $d['untested']['total'], $d['connections']['total']], 'failures and the rest are for admins');
    }

    public function test_one_company_never_sees_anothers_items_in_its_digest(): void
    {
        $other = $this->makeCompany('elsewhere');
        $sid = DB::table('servers')->insertGetId(['company_id' => $other, 'hostname' => 'rival-box', 'os_type' => 'linux', 'criticality' => 'standard', 'backup_methods' => '[]', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('backup_failures')->insert(['company_id' => $other, 'server_id' => $sid, 'source' => 'acronis', 'backup_method' => 'acronis', 'kind' => 'failed', 'severity' => 'high', 'message' => 'm', 'active' => 1, 'resolved' => 0,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $d = $this->digest('admin');
        $this->assertSame(0, $d['backup_problems']['total']);
        $this->assertStringNotContainsString('rival-box', json_encode($d));
    }

    // ---------------------------------------------------------------- when it goes

    private function outstanding(): void
    {
        $this->backupProblem($this->server('web01'));
    }

    public function test_it_is_sent_in_the_chosen_hour_to_the_admin_and_only_once_a_day(): void
    {
        $this->outstanding();
        $this->assertSame(1, Reminders::sendDue($this->companyId, $this->wed()), 'the admin; the technician has nothing waiting and the manager is not included');
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('admin@example.com', $this->to($mails[0]));
        $this->assertStringContainsString('1 backup problem', $mails[0]->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('web01', $mails[0]->getOriginalMessage()->getHtmlBody());
        $this->assertStringContainsString('web01', $mails[0]->getOriginalMessage()->getTextBody());

        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()->addMinutes(20)), 'the same hour a minute or so later does nothing more');
        $this->assertCount(1, $this->mails());
        $this->assertSame(1, Reminders::sendDue($this->companyId, $this->wed()->addDay()), 'the next morning it goes again, because it is still outstanding');
    }

    public function test_it_waits_for_the_hour_the_weekdays_and_the_switch(): void
    {
        $this->outstanding();
        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()->setTime(9, 0)), 'not the right hour');
        $this->assertSame(0, Reminders::sendDue($this->companyId, CarbonImmutable::parse('2026-10-17 08:05:00', 'UTC')), 'a Saturday');
        $this->admin->put('/api/reminders', ['weekdays_only' => false])->assertOk();
        $this->assertSame(1, Reminders::sendDue($this->companyId, CarbonImmutable::parse('2026-10-17 08:05:00', 'UTC')), 'weekends too once asked for');
        $this->admin->put('/api/reminders', ['enabled' => false])->assertOk();
        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()->addDays(2)), 'switched off');
    }

    public function test_the_hour_is_the_companys_own_local_hour(): void
    {
        $this->outstanding();
        $this->admin->put('/api/reminders', ['timezone' => 'Africa/Johannesburg', 'send_hour' => 8])->assertOk();
        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()->setTime(8, 5)), '08:05 UTC is 10:05 in Johannesburg');
        $this->assertSame(1, Reminders::sendDue($this->companyId, $this->wed()->setTime(6, 5)), '06:05 UTC is 08:05 in Johannesburg');
    }

    public function test_nothing_is_sent_when_there_is_nothing_to_say_but_the_day_is_marked_done(): void
    {
        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()));
        $this->assertCount(0, $this->mails());
        $this->assertSame(['nothing'], DB::table('reminder_log')->pluck('status')->unique()->values()->all());
        $this->outstanding();
        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()->addMinutes(10)), 'it was already looked at today');
    }

    public function test_managers_get_it_only_when_the_company_asks_and_anyone_can_opt_out(): void
    {
        $this->outstanding();
        $this->admin->put('/api/reminders', ['include_managers' => true])->assertOk();
        $this->assertSame(2, Reminders::sendDue($this->companyId, $this->wed()));
        $this->assertEqualsCanonicalizing(['admin@example.com', 'mgr@example.com'], array_map(fn ($m) => $this->to($m), $this->mails()));

        $this->admin->put('/api/reminders/mine', ['enabled' => false])->assertOk();
        $this->assertSame(1, Reminders::sendDue($this->companyId, $this->wed()->addDay()), 'only the manager now');
        $this->assertSame('mgr@example.com', $this->to($this->mails()[2]));
    }

    public function test_a_disabled_person_or_one_with_no_address_is_not_mailed(): void
    {
        $this->outstanding();
        DB::table('users')->where('username', 'admin')->update(['active' => 0]);
        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()));
        DB::table('users')->where('username', 'admin')->update(['active' => 1, 'email' => '']);
        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()->addDay()));
    }

    public function test_a_mail_server_that_is_down_is_recorded_and_not_hammered(): void
    {
        $this->outstanding();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Connection refused'));
        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()));
        $row = DB::table('reminder_log')->first();
        $this->assertSame(['failed', 'Connection refused'], [$row->status, $row->error]);
        $this->assertSame(0, Reminders::sendDue($this->companyId, $this->wed()->addMinutes(5)), 'not tried again the same day');
    }

    public function test_the_engine_sends_the_reminders_for_every_company(): void
    {
        $this->outstanding();
        DB::table('reminder_settings')->where('company_id', $this->companyId)->update(['send_hour' => (int) now('UTC')->format('G'), 'weekdays_only' => 0]);
        $this->assertSame(1, Engine::tick()['reminders']);
    }

    // ---------------------------------------------------------------- settings and sending one now

    public function test_only_an_admin_changes_the_settings_and_a_bad_value_is_refused(): void
    {
        $this->tech->put('/api/reminders', ['send_hour' => 9])->assertStatus(403);
        $this->manager->put('/api/reminders', ['send_hour' => 9])->assertStatus(403);
        $this->admin->put('/api/reminders', ['send_hour' => 24])->assertStatus(400);
        $this->admin->put('/api/reminders', ['timezone' => 'Mars/Olympus'])->assertStatus(400);
        $r = $this->admin->put('/api/reminders', ['send_hour' => 6, 'include_managers' => true])->assertOk()->json('data');
        $this->assertSame([6, true], [$r['settings']['send_hour'], $r['settings']['include_managers']]);
        $this->assertSame('array', $r['transport']['mailer']);
        $this->assertFalse($r['transport']['delivers'], 'says plainly when mail is only being written to a log');
        $this->assertGreaterThanOrEqual(1, DB::table('audit_trail')->where('action', 'reminder_settings_updated')->count());
    }

    public function test_a_person_can_send_themselves_the_digest_now_and_turn_their_own_off(): void
    {
        $this->outstanding();
        $r = $this->admin->post('/api/reminders/send-now')->assertOk()->json('data');
        $this->assertSame(2, $r['items'], 'the failed backup and a server that has never been tested');
        $this->assertCount(1, $this->mails());
        $this->assertSame(0, DB::table('reminder_log')->count(), 'trying it does not use up the day\'s mail');

        $this->tech->put('/api/reminders/mine', ['enabled' => false])->assertOk();
        $this->assertFalse((bool) User::find($this->techId)->mail_reminders);
        $this->assertSame(1, $this->tech->get('/api/reminders')->assertOk()->json('data.mine.has_email') ? 1 : 0);
    }

    public function test_the_mail_says_what_is_waiting_in_plain_words_and_links_to_the_right_page(): void
    {
        $this->backupProblem($this->server('web01'));
        $company = Company::find($this->companyId);
        $m = CompanyContext::run($this->companyId, fn () => Reminders::render(Reminders::digest(User::where('username', 'admin')->first()), User::where('username', 'admin')->first(), $company));
        $this->assertStringContainsString('Backup problems to review (1)', $m['text']);
        $this->assertStringContainsString('/companies/remco/backups', $m['html']);
        $this->assertStringContainsString('turn off mail reminders', $m['text']);
        $this->assertStringNotContainsString('password', strtolower($m['html']));
    }
}
