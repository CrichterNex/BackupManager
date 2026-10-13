<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Backup commands an admin edits in BMS instead of in the agent's config file. */
class ManagedActionsTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $manager;

    private ApiClient $tech;

    private ApiClient $rival;

    private int $server;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $company = $this->makeCompany('managedco');
        $other = $this->makeCompany('rivalmanaged');
        $this->makeUser($company, 'admin', 'company_admin');
        $this->makeUser($company, 'mgr', 'company_manager');
        $techId = $this->makeUser($company, 'tech', 'company_technician');
        $this->makeUser($other, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->manager = $this->login('mgr');
        $this->tech = $this->login('tech');
        $this->rival = $this->login('rival');
        $this->server = $this->admin->post('/api/servers', ['hostname' => 'web-01', 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        $this->admin->put("/api/servers/{$this->server}/assignments", ['user_ids' => [$techId]]);
        $this->secret = $this->admin->post("/api/servers/{$this->server}/agent")->json('agent_config.token');
    }

    private function poll(array $body = []): array
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer {$this->secret}"])->postJson('/api/agent/poll', $body + ['version' => '1.3.0', 'actions' => [], 'remote_actions' => true])->json();
    }

    private function save(ApiClient $who, array $actions)
    {
        return $who->put("/api/servers/{$this->server}/agent/actions", ['actions' => $actions]);
    }

    public function test_an_agent_with_remote_actions_gets_an_empty_backup_action_to_fill_in_by_default(): void
    {
        $r = $this->poll();
        $this->assertSame(['type' => 'command', 'command' => null], $r['managed_actions']['backup']);
    }

    public function test_an_agent_that_does_not_allow_remote_actions_is_never_sent_any(): void
    {
        $this->assertArrayNotHasKey('managed_actions', $this->poll(['remote_actions' => false]));
        $this->assertArrayNotHasKey('managed_actions', $this->poll(['remote_actions' => null] + ['version' => '1.2.0']), 'an older agent that says nothing');
    }

    public function test_an_admin_sets_the_command_and_the_agent_gets_it_on_its_next_check_in(): void
    {
        $this->poll();
        $this->save($this->admin, ['backup' => ['command' => ['/usr/local/bin/backup.sh', '--full'], 'cwd' => '/srv', 'timeout_seconds' => 3600]])->assertOk();
        $backup = $this->poll()['managed_actions']['backup'];
        $this->assertSame(['/usr/local/bin/backup.sh', '--full'], $backup['command']);
        $this->assertSame(3600, $backup['timeout_seconds']);
        $this->assertSame('/srv', $backup['cwd']);
    }

    public function test_the_change_is_in_the_audit_log_with_before_and_after(): void
    {
        $this->poll();
        $this->save($this->admin, ['backup' => ['command' => ['/b.sh']]])->assertOk();
        $this->save($this->admin, ['backup' => ['command' => ['/c.sh']]])->assertOk();
        $e = collect($this->admin->get('/api/audit?limit=50')->json('data'))->where('action', 'agent_actions_updated')->first();
        $this->assertNotNull($e);
        $this->assertSame('/b.sh', $e['details']['before']['backup']['command'][0]);
        $this->assertSame('/c.sh', $e['details']['after']['backup']['command'][0]);
    }

    public function test_only_company_admins_can_change_actions_and_only_they_can_see_the_commands(): void
    {
        $this->poll();
        $this->save($this->admin, ['backup' => ['command' => ['/secret-path.sh']]])->assertOk();
        foreach ([$this->manager, $this->tech] as $who) {
            $this->save($who, ['backup' => ['command' => ['/x']]])->assertStatus(403);
            $page = $who->get('/api/agents')->json('data.agents');
            $this->assertArrayNotHasKey('managed_actions', $page[0] ?? []);
        }
        $this->assertSame('/secret-path.sh', $this->admin->get('/api/agents')->json('data.agents.0.managed_actions.backup.command.0'));
    }

    public function test_another_company_cannot_touch_or_see_this_agent(): void
    {
        $this->poll();
        $this->save($this->rival, ['backup' => ['command' => ['/evil.sh']]])->assertStatus(404);
        $this->assertSame([], $this->rival->get('/api/agents')->json('data.agents'));
        $this->assertNull(DB::table('agents')->value('managed_actions'));
    }

    public function test_bad_input_is_refused(): void
    {
        $this->poll();
        $bad = [
            'name with space' => ['command' => ['/b']],
            'x' => ['command' => ['']],
            'y' => ['command' => 'rm -rf /'],
            'z' => ['command' => ['/b'], 'timeout_seconds' => 5],
            'w' => ['command' => [['nested']]],
        ];
        foreach ($bad as $name => $a) {
            $this->save($this->admin, [$name => $a])->assertStatus(400);
        }
        $this->assertNull(DB::table('agents')->value('managed_actions'));
    }

    public function test_it_is_refused_for_an_agent_installed_with_remote_actions_off(): void
    {
        $this->poll(['remote_actions' => false]);
        $this->save($this->admin, ['backup' => ['command' => ['/b.sh']]])->assertStatus(409)->assertJsonPath('error_code', 'AGENT_006');
    }

    public function test_an_action_the_agent_runs_as_a_restore_test_cannot_be_replaced_with_a_command(): void
    {
        $this->poll(['actions' => ['restore-test' => ['type' => 'restore_test']]]);
        $this->save($this->admin, ['restore-test' => ['command' => ['/evil.sh']]])->assertStatus(400);
    }

    public function test_removing_every_action_brings_the_empty_default_back(): void
    {
        $this->poll();
        $this->save($this->admin, ['backup' => ['command' => ['/b.sh']]])->assertOk();
        $this->save($this->admin, [])->assertOk();
        $this->assertSame(['type' => 'command', 'command' => null], $this->poll()['managed_actions']['backup']);
    }

    public function test_a_backup_can_be_queued_against_the_default_action_once_the_agent_advertises_it(): void
    {
        $this->poll(['actions' => ['backup' => ['type' => 'command']]]);
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $this->server, 'backup_method' => 'rsync', 'frequency' => 'daily'])->json('data.id');
        $this->assertNotNull($job);
        $this->admin->post("/api/backup-jobs/$job/run")->assertStatus(202)->assertJsonPath('data.action', 'backup');
    }

    public function test_an_admin_maps_a_location_to_a_folder_and_the_agent_gets_it(): void
    {
        $this->admin->post('/api/backup-locations', ['name' => 'Store', 'loc_key' => 'cntstore2'])->assertStatus(201);
        $this->poll();
        $this->assertEquals((object) [], (object) $this->poll()['managed_locations']);
        $this->saveLocations($this->admin, ['cntstore2' => 'D:\Backups\cnt'])->assertOk();
        $this->assertSame(['cntstore2' => 'D:\Backups\cnt'], $this->poll()['managed_locations']);
        $e = collect($this->admin->get('/api/audit?limit=50')->json('data'))->firstWhere('action', 'agent_locations_updated');
        $this->assertSame('D:\Backups\cnt', $e['details']['after']['cntstore2']);
    }

    public function test_location_folders_are_only_for_real_locations_and_must_be_full_paths(): void
    {
        $this->admin->post('/api/backup-locations', ['name' => 'Store', 'loc_key' => 'cntstore2']);
        $this->poll();
        foreach ([['nope' => '/mnt/x'], ['cntstore2' => 'relative/dir'], ['cntstore2' => '/mnt/../etc'], ['cntstore2' => '']] as $bad) {
            $this->saveLocations($this->admin, $bad)->assertStatus(400);
        }
        $this->assertNull(DB::table('agents')->value('managed_locations'));
    }

    public function test_only_admins_set_location_folders_and_other_companies_cannot(): void
    {
        $this->admin->post('/api/backup-locations', ['name' => 'Store', 'loc_key' => 'cntstore2']);
        $this->poll();
        $this->saveLocations($this->manager, ['cntstore2' => '/mnt/x'])->assertStatus(403);
        $this->saveLocations($this->tech, ['cntstore2' => '/mnt/x'])->assertStatus(403);
        $this->saveLocations($this->rival, ['cntstore2' => '/mnt/x'])->assertStatus(400);
    }

    public function test_location_folders_are_refused_for_an_agent_with_remote_actions_off(): void
    {
        $this->admin->post('/api/backup-locations', ['name' => 'Store', 'loc_key' => 'cntstore2']);
        $this->poll(['remote_actions' => false]);
        $this->saveLocations($this->admin, ['cntstore2' => '/mnt/x'])->assertStatus(409);
        $this->assertArrayNotHasKey('managed_locations', $this->poll(['remote_actions' => false]));
    }

    private function saveLocations(ApiClient $who, array $locations)
    {
        return $who->put("/api/servers/{$this->server}/agent/locations", ['locations' => $locations]);
    }

    private function saveRoots(ApiClient $who, array $roots)
    {
        return $who->put("/api/servers/{$this->server}/agent/source-roots", ['source_roots' => $roots]);
    }

    public function test_an_admin_allows_source_folders_and_the_agent_gets_them(): void
    {
        $this->poll();
        $this->assertSame([], $this->poll()['managed_source_roots']);
        $this->saveRoots($this->admin, ['D:\Data', 'D:\Data', '/srv/www'])->assertOk();
        $this->assertSame(['D:\Data', '/srv/www'], $this->poll()['managed_source_roots'], 'duplicates are dropped');
        $e = collect($this->admin->get('/api/audit?limit=50')->json('data'))->firstWhere('action', 'agent_source_roots_updated');
        $this->assertSame(['D:\Data', '/srv/www'], $e['details']['after']);
        $this->assertSame(['D:\Data', '/srv/www'], $this->admin->get('/api/agents')->json('data.agents.0.managed_source_roots'));
    }

    public function test_source_folders_must_be_full_paths_and_only_admins_can_set_them(): void
    {
        $this->poll();
        foreach ([['relative/dir'], ['/a/../etc'], [''], ['D:\x', 'also relative']] as $bad) {
            $this->saveRoots($this->admin, $bad)->assertStatus(400);
        }
        $this->saveRoots($this->manager, ['/srv'])->assertStatus(403);
        $this->saveRoots($this->tech, ['/srv'])->assertStatus(403);
        $this->saveRoots($this->rival, ['/srv'])->assertStatus(404);
        $this->assertNull(DB::table('agents')->value('managed_source_roots'));
    }

    public function test_source_folders_are_refused_for_an_agent_with_remote_actions_off_and_never_sent_to_it(): void
    {
        $this->poll(['remote_actions' => false]);
        $this->saveRoots($this->admin, ['/srv'])->assertStatus(409);
        $this->assertArrayNotHasKey('managed_source_roots', $this->poll(['remote_actions' => false]));
    }

    public function test_reads_and_changes_have_separate_rate_limits(): void
    {
        config(['bms.read_rate_limit_per_hour' => 3, 'bms.rate_limit_per_hour' => 100]);
        $this->poll();
        for ($i = 0; $i < 3; $i++) {
            $this->admin->get('/api/agents')->assertOk();
        }
        $this->admin->get('/api/agents')->assertStatus(429);
        $this->saveRoots($this->admin, ['/srv'])->assertOk();
    }

    private function saveCredentials(ApiClient $who, array $body)
    {
        return $who->put("/api/servers/{$this->server}/agent/credentials", $body);
    }

    private function withLocation(): void
    {
        $this->admin->post('/api/backup-locations', ['name' => 'Vault', 'loc_key' => 'vault'])->assertStatus(201);
    }

    public function test_credentials_are_stored_encrypted_and_never_come_back_out_of_any_call(): void
    {
        $this->withLocation();
        $this->poll();
        $this->saveCredentials($this->admin, ['location' => 'vault', 'username' => 'CORP\svc-backup', 'password' => 'S3cret-Pass-Word!'])->assertOk()->assertJsonPath('data.credential_users.vault', 'CORP\svc-backup');

        $raw = (string) DB::table('agents')->value('managed_credentials');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('S3cret', $raw, 'must be encrypted at rest');
        $this->assertStringNotContainsString('svc-backup', $raw);

        foreach (['/api/agents', "/api/servers/{$this->server}/agent", "/api/servers/{$this->server}"] as $url) {
            $this->assertStringNotContainsString('S3cret', $this->admin->get($url)->getContent(), $url);
        }
        $this->assertSame('CORP\svc-backup', $this->admin->get('/api/agents')->json('data.agents.0.credential_users.vault'));
    }

    public function test_the_password_is_not_in_the_audit_log_only_who_and_which_location(): void
    {
        $this->withLocation();
        $this->poll();
        $this->saveCredentials($this->admin, ['location' => 'vault', 'username' => 'svc', 'password' => 'Hunter2-Hunter2'])->assertOk();
        $this->saveCredentials($this->admin, ['location' => 'vault', 'remove' => true])->assertOk();
        $log = $this->admin->get('/api/audit?limit=50')->getContent();
        $this->assertStringNotContainsString('Hunter2', $log);
        $this->assertStringContainsString('agent_credentials_set', $log);
        $this->assertStringContainsString('agent_credentials_removed', $log);
    }

    public function test_the_agent_is_sent_the_passwords_only_when_its_copy_is_out_of_date(): void
    {
        $this->withLocation();
        $this->poll();
        $this->saveCredentials($this->admin, ['location' => 'vault', 'username' => 'svc', 'password' => 'pw-pw-pw-pw'])->assertOk();
        $first = $this->poll(['credentials_version' => 0]);
        $this->assertSame(['username' => 'svc', 'password' => 'pw-pw-pw-pw'], $first['managed_credentials']['vault']);
        $v = $first['credentials_version'];
        $this->assertGreaterThan(0, $v);
        $again = $this->poll(['credentials_version' => $v]);
        $this->assertArrayNotHasKey('managed_credentials', $again, 'an agent that is up to date is not sent them again');

        $this->saveCredentials($this->admin, ['location' => 'vault', 'remove' => true])->assertOk();
        $cleared = $this->poll(['credentials_version' => $v]);
        $this->assertEquals((object) [], (object) $cleared['managed_credentials'], 'removal reaches the agent too');
    }

    public function test_an_agent_with_remote_actions_off_is_never_sent_credentials_and_cannot_be_given_any(): void
    {
        $this->withLocation();
        $this->poll(['remote_actions' => false]);
        $this->saveCredentials($this->admin, ['location' => 'vault', 'username' => 'svc', 'password' => 'pw-pw-pw-pw'])->assertStatus(409);
        $this->assertArrayNotHasKey('managed_credentials', $this->poll(['remote_actions' => false, 'credentials_version' => 0]));
    }

    public function test_only_admins_set_credentials_and_only_for_real_locations_in_their_own_company(): void
    {
        $this->withLocation();
        $this->poll();
        $body = ['location' => 'vault', 'username' => 'svc', 'password' => 'pw-pw-pw-pw'];
        $this->saveCredentials($this->manager, $body)->assertStatus(403);
        $this->saveCredentials($this->tech, $body)->assertStatus(403);
        $this->saveCredentials($this->rival, $body)->assertStatus(400);
        $this->saveCredentials($this->admin, ['location' => 'nope'] + $body)->assertStatus(400);
        $this->saveCredentials($this->admin, ['location' => 'vault', 'username' => 'svc'])->assertStatus(400);
        $this->assertNull(DB::table('agents')->value('managed_credentials'));
        foreach ([$this->manager, $this->tech] as $who) {
            $this->assertArrayNotHasKey('credential_users', $who->get('/api/agents')->json('data.agents.0') ?? []);
        }
    }
}
