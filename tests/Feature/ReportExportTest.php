<?php

namespace Tests\Feature;

use App\Services\Reports\Anonymiser;
use App\Services\Reports\ReportRenderer;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Reports: the register of servers, PDF export, and anonymising a report so it can go outside the company. */
class ReportExportTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $manager;

    private ApiClient $tech;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = $this->makeCompany('acmecorp');
        DB::table('companies')->where('id', $this->companyId)->update(['name' => 'Acme Holdings Pty']);
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'mgr', 'company_manager');
        $tid = $this->makeUser($this->companyId, 'janet', 'company_technician');
        DB::table('users')->where('id', $tid)->update(['first_name' => 'Janet', 'last_name' => 'Wolmarans']);
        $this->admin = $this->login('admin');
        $this->manager = $this->login('mgr');
        $this->tech = $this->login('janet');
    }

    private function server(string $host, ?string $function, array $extra = []): int
    {
        return $this->admin->post('/api/servers', ['hostname' => $host, 'os_type' => 'linux', 'ip_address' => '10.9.8.7', 'backup_methods' => ['rsync'], 'data_function' => $function] + $extra)->assertStatus(201)->json('data.id');
    }

    private function job(int $server, array $over = []): int
    {
        $id = $this->admin->post('/api/backup-jobs', ['server_id' => $server, 'backup_method' => 'rsync'])->assertStatus(201)->json('data.id');
        $over && DB::table('backup_jobs')->where('id', $id)->update($over);

        return $id;
    }

    private function test(int $job, string $type, string $status, bool $manual, ?int $by, string $at): void
    {
        DB::table('backup_test_executions')->insert(['company_id' => $this->companyId, 'backup_job_id' => $job, 'test_type' => $type, 'test_status' => $status, 'manual' => $manual,
            'executed_by' => $by, 'scheduled_time' => $at, 'started_time' => $at, 'completed_time' => $at, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function report(string $type = 'compliance'): int
    {
        $body = $type === 'dr_readiness' ? ['report_type' => $type] : ['report_type' => $type, 'period_start' => now()->subDays(30)->toIso8601ZuluString(), 'period_end' => now()->toIso8601ZuluString()];

        return $this->admin->post('/api/reports', $body)->assertStatus(201)->json('data.id');
    }

    private function html(int $id, bool $anon = false, ?ApiClient $who = null): string
    {
        return ($who ?? $this->admin)->get("/api/reports/$id/view".($anon ? '?anonymise=1' : ''))->assertOk()->getContent();
    }

    private function row(int $id, string $host): array
    {
        $snap = DB::table('reports')->find($id);
        $data = json_decode($snap->data, true);

        return collect($data['register'] ?? $data['servers'])->firstWhere('hostname', $host);
    }

    // ---------------------------------------------------------------- what a server stores

    public function test_a_server_keeps_what_it_stores_and_it_can_be_changed(): void
    {
        $id = $this->server('fin01', 'Finance database');
        $this->assertSame('Finance database', $this->admin->get("/api/servers/$id")->json('data.data_function'));
        $this->admin->patch("/api/servers/$id", ['data_function' => 'Payroll'])->assertOk();
        $this->assertSame('Payroll', $this->admin->get("/api/servers/$id")->json('data.data_function'));
        $this->admin->patch("/api/servers/$id", ['data_function' => str_repeat('x', 256)])->assertStatus(400);
    }

    // ---------------------------------------------------------------- the register

    public function test_the_register_shows_frequency_from_the_schedule_retention_and_who_tested(): void
    {
        $daily = $this->server('daily01', 'Finance database', ['retention_policy_days' => 30]);
        $this->job($daily, ['schedule' => '0 2 * * *', 'status' => 'success', 'completed_time' => now()->subDay()]);
        $weekly = $this->server('weekly01', 'File shares');
        $wj = $this->job($weekly, ['schedule' => '0 3 * * 0', 'retention_days' => 365]);
        $monthly = $this->server('monthly01', 'Archive');
        $this->job($monthly, ['schedule' => '0 1 1 * *']);

        $janet = DB::table('users')->where('username', 'janet')->value('id');
        $dj = DB::table('backup_jobs')->where('server_id', $daily)->value('id');
        $this->test($dj, 'random_restore', 'success', true, $janet, now()->subDays(3)->toDateTimeString());
        $this->test($wj, 'recovery_point', 'success', false, null, now()->subDays(2)->toDateTimeString());
        $id = $this->report();

        $d = $this->row($id, 'daily01');
        $this->assertSame(['Daily', '30 days'], [$d['frequency']['text'], $d['retention']]);
        $this->assertSame(['Janet Wolmarans', false], [$d['last_test']['by'], $d['last_test']['automatic']]);
        $w = $this->row($id, 'weekly01');
        $this->assertSame(['Weekly', '1 year'], [$w['frequency']['text'], $w['retention']]);
        $this->assertTrue($w['last_test']['automatic'], 'a test BMS ran on its own is the system\'s');
        $m = $this->row($id, 'monthly01');
        $this->assertSame(['Monthly', 'Not set', null], [$m['frequency']['text'], $m['retention'], $m['last_test']]);
    }

    public function test_without_a_schedule_the_frequency_is_worked_out_from_the_backups_and_says_so(): void
    {
        $s = $this->server('hist01', 'Mail');
        foreach ([1, 2, 3, 4] as $d) {
            $this->job($s, ['status' => 'success', 'completed_time' => now()->subDays($d)]);
        }
        $none = $this->server('none01', 'Scratch');
        $id = $this->report();
        $this->assertSame('Daily (observed)', $this->row($id, 'hist01')['frequency']['text']);
        $this->assertSame('Not known', $this->row($id, 'none01')['frequency']['text']);
        $this->assertNotNull($none);
    }

    public function test_a_failed_or_skipped_test_is_not_a_last_test_and_a_test_after_the_period_is_not_counted(): void
    {
        $s = $this->server('t01', 'Mail');
        $j = $this->job($s);
        $this->test($j, 'random_restore', 'skipped', false, null, now()->subDays(1)->toDateTimeString());
        $id = $this->report();
        $this->assertNull($this->row($id, 't01')['last_test']);
    }

    public function test_the_report_page_shows_the_new_columns(): void
    {
        $s = $this->server('col01', 'Finance database', ['retention_policy_days' => 90]);
        $this->job($s, ['schedule' => '0 2 * * *']);
        foreach (['compliance', 'sla', 'dr_readiness'] as $type) {
            $html = $this->html($this->report($type));
            foreach (['Backup frequency', 'Retention', 'Test performed by'] as $col) {
                $this->assertStringContainsString($col, $html, "$type has $col");
            }
            $this->assertStringContainsString('col01', $html);
        }
    }

    // ---------------------------------------------------------------- anonymising

    private function seedIdentifiable(): int
    {
        $a = $this->server('fin-db-prod', 'Finance database');
        $b = $this->server('hr-files-01', 'HR file shares');
        $c = $this->server('hr-files-02', 'HR file shares');
        $d = $this->server('mystery', null);
        $ja = $this->job($a, ['schedule' => '0 2 * * *', 'scope_type' => 'folders', 'source_paths' => json_encode(['D:\\Payroll2026']), 'status' => 'success', 'completed_time' => now()->subHour()]);
        $this->job($b);
        $this->job($c);
        $this->job($d);
        $this->test($ja, 'random_restore', 'success', true, DB::table('users')->where('username', 'janet')->value('id'), now()->subDays(2)->toDateTimeString());

        return $this->report('dr_readiness');
    }

    public function test_an_anonymised_report_names_no_company_server_address_person_or_folder(): void
    {
        $id = $this->seedIdentifiable();
        $plain = $this->html($id);
        foreach (['Acme Holdings', 'fin-db-prod', 'hr-files-01', 'mystery', 'Janet', 'Wolmarans', 'Payroll2026', 'Backs up (as stated)'] as $name) {
            $this->assertStringContainsString($name, $plain, "the normal report names $name");
        }

        $anon = $this->html($id, true);
        foreach (['Acme Holdings', 'acmecorp', 'fin-db-prod', 'hr-files-01', 'hr-files-02', 'mystery', 'Janet', 'Wolmarans', 'janet', 'Payroll2026', '10.9.8.7', 'Backs up (as stated)', 'D:\\'] as $name) {
            $this->assertStringNotContainsString($name, $anon, "the anonymised report must not name $name");
        }
        $this->assertStringContainsString('Finance database', $anon, 'a server is shown by what it stores');
        $this->assertStringContainsString('HR file shares (1)', $anon);
        $this->assertStringContainsString('HR file shares (2)', $anon, 'servers that do the same thing are numbered, not named');
        $this->assertStringContainsString('function not stated', $anon);
        $this->assertStringContainsString('Technician 1', $anon);
        $this->assertStringContainsString(Anonymiser::COMPANY, $anon);
        $this->assertStringContainsString('Anonymised', $anon);
    }

    public function test_the_anonymised_register_in_a_compliance_report_has_the_same_protection_and_no_alphabetical_leak(): void
    {
        $this->seedIdentifiable();
        $id = $this->report();
        $anon = $this->html($id, true);
        foreach (['fin-db-prod', 'hr-files-02', 'mystery', 'Janet', 'Acme', '10.9.8.7'] as $name) {
            $this->assertStringNotContainsString($name, $anon);
        }
        // The rows are in the order of what they store, not of the server names (fin, hr, hr, mystery would be the same here, so check the labels' own order).
        $this->assertLessThan(strpos($anon, 'HR file shares (1)'), strpos($anon, 'Finance database'));
        $this->assertLessThan(strpos($anon, 'Server 1 (function not stated)'), strpos($anon, 'HR file shares (2)'));
    }

    public function test_a_person_is_the_same_technician_throughout_and_the_system_stays_the_system(): void
    {
        $a = new Anonymiser([
            ['hostname' => 'x', 'function' => 'One', 'last_test' => ['by' => 'Zed']],
            ['hostname' => 'y', 'function' => 'Two', 'last_test' => ['by' => 'Amy']],
            ['hostname' => 'z', 'function' => null, 'last_test' => null],
        ]);
        $this->assertSame('Technician 1', $a->person('Amy'));
        $this->assertSame('Technician 2', $a->person('Zed'));
        $this->assertSame('Not recorded', $a->person(null));
        $this->assertSame(['One', 'Two', 'Server 1 (function not stated)'], [$a->server('x'), $a->server('y'), $a->server('z')]);
    }

    public function test_the_renderer_does_not_keep_the_anonymiser_between_reports(): void
    {
        $id = $this->seedIdentifiable();
        $this->html($id, true);
        $this->assertStringContainsString('fin-db-prod', $this->html($id), 'a normal view straight after an anonymised one still names the servers');
        $snap = json_decode(DB::table('reports')->find($id)->data, true);
        $this->assertStringContainsString('Acme Holdings', ReportRenderer::render($snap, $id));
    }

    public function test_a_report_made_before_these_columns_existed_still_opens_both_ways(): void
    {
        $s = $this->server('old01', 'Mail');
        $this->job($s);
        $id = $this->report('dr_readiness');
        $data = json_decode(DB::table('reports')->find($id)->data, true);
        foreach ($data['servers'] as $i => $sv) {
            unset($data['servers'][$i]['function'], $data['servers'][$i]['frequency'], $data['servers'][$i]['retention'], $data['servers'][$i]['last_test']);
        }
        DB::table('reports')->where('id', $id)->update(['data' => json_encode($data)]);
        $this->assertStringContainsString('Not recorded', $this->html($id));
        $this->assertStringNotContainsString('old01', $this->html($id, true));

        $cid = $this->report();
        $c = json_decode(DB::table('reports')->find($cid)->data, true);
        unset($c['register']);
        DB::table('reports')->where('id', $cid)->update(['data' => json_encode($c)]);
        $this->assertStringNotContainsString('Test performed by', $this->html($cid, true));
    }

    // ---------------------------------------------------------------- PDF

    public function test_a_report_downloads_as_a_pdf_with_a_file_name_that_names_nobody(): void
    {
        $id = $this->seedIdentifiable();
        $r = $this->admin->get("/api/reports/$id/pdf")->assertOk();
        $this->assertStringStartsWith('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertSame('attachment; filename="bms-dr_readiness-'.$id.'.pdf"', $r->headers->get('Content-Disposition'));
        $anon = $this->admin->get("/api/reports/$id/pdf?anonymise=1")->assertOk();
        $this->assertSame('attachment; filename="bms-dr_readiness-'.$id.'-anonymised.pdf"', $anon->headers->get('Content-Disposition'));
    }

    public function test_the_pdf_carries_no_author_producer_dates_or_title(): void
    {
        $id = $this->seedIdentifiable();
        $pdf = $this->admin->get("/api/reports/$id/pdf")->getContent();
        foreach (['Producer', 'Creator', 'CreationDate', 'ModDate', 'Author'] as $key) {
            preg_match_all('/\/'.$key.' \((.*?)\)/s', $pdf, $m);
            foreach ($m[1] as $v) {
                $this->assertSame('', trim($v), "$key is blank");
            }
        }
        $this->assertStringNotContainsString('Dompdf', $pdf);
        $this->assertStringNotContainsString('Acme', $pdf);
    }

    public function test_every_kind_of_report_makes_a_pdf_and_exports_are_logged(): void
    {
        $this->seedIdentifiable();
        foreach (['compliance', 'sla', 'security', 'dr_readiness'] as $type) {
            $id = $this->report($type);
            $this->assertStringStartsWith('%PDF', $this->admin->get("/api/reports/$id/pdf?anonymise=1")->getContent(), $type);
        }
        $rows = DB::table('audit_trail')->where('action', 'report_exported')->get();
        $this->assertGreaterThanOrEqual(4, $rows->count());
        $this->assertStringContainsString('"anonymised":true', $rows->last()->details);
    }

    public function test_who_can_export_and_who_cannot(): void
    {
        $id = $this->seedIdentifiable();
        $this->manager->get("/api/reports/$id/pdf")->assertOk();
        $this->manager->get("/api/reports/$id/view?anonymise=1")->assertOk();
        $this->tech->get("/api/reports/$id/pdf")->assertStatus(403);
        $this->tech->get("/api/reports/$id/view?anonymise=1")->assertStatus(403);

        $this->makeCompany('rival');
        $this->makeUser(DB::table('companies')->where('slug', 'rival')->value('id'), 'rivaladmin', 'company_admin');
        $rival = $this->login('rivaladmin');
        $rival->get("/api/reports/$id/pdf")->assertStatus(404);
        $rival->get("/api/reports/$id/view?anonymise=1")->assertStatus(404);
    }

    public function test_a_microsoft_365_tenant_never_gets_a_frequency_guessed_from_how_often_bms_looked(): void
    {
        $id = $this->admin->post('/api/servers', ['hostname' => 'tenant01', 'os_type' => 'microsoft_365', 'backup_methods' => ['acronis_m365'], 'data_function' => 'Staff mail'])->assertStatus(201)->json('data.id');
        foreach ([1, 2, 3, 4, 5] as $h) {
            $this->admin->post('/api/backup-jobs', ['server_id' => $id, 'backup_method' => 'acronis_m365']);
        }
        DB::table('backup_jobs')->where('server_id', $id)->update(['status' => 'success']);
        foreach (DB::table('backup_jobs')->where('server_id', $id)->pluck('id') as $n => $jid) {
            DB::table('backup_jobs')->where('id', $jid)->update(['completed_time' => now()->subMinutes(15 * ($n + 1))]);
        }
        $this->assertSame('Set in the backup platform', $this->row($this->report(), 'tenant01')['frequency']['text']);
    }
}
