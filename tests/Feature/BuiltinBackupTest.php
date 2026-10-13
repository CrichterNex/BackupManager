<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** A robocopy or rsync job is run by the agent's built-in action from the job's own folders, so nobody repeats them in a script. */
class BuiltinBackupTest extends TestCase
{
    private ApiClient $admin;

    private int $serverId;

    private int $locationId;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $company = $this->makeCompany('builtinco');
        $this->makeUser($company, 'admin', 'company_admin');
        $this->admin = $this->login('admin');
        $this->serverId = $this->admin->post('/api/servers', ['hostname' => 'win-01', 'os_type' => 'windows', 'backup_methods' => ['robocopy', 'rsync']])->json('data.id');
        $this->locationId = $this->admin->post('/api/backup-locations', ['name' => 'NAS', 'loc_key' => 'nas-main'])->json('data.id');
        $this->secret = $this->admin->post("/api/servers/{$this->serverId}/agent")->json('agent_config.token');
    }

    private function poll(array $actions, array $locations = ['nas-main']): array
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => "Bearer {$this->secret}"])->postJson('/api/agent/poll', ['version' => '1.4.0', 'actions' => $actions, 'locations' => $locations, 'remote_actions' => true])->json();
    }

    private function job(string $method, array $over = []): int
    {
        return $this->admin->post('/api/backup-jobs', array_merge(['server_id' => $this->serverId, 'backup_method' => $method, 'location_id' => $this->locationId,
            'scope_type' => 'folders', 'source_paths' => ['D:\\Data\\Web', 'D:\\Data\\Mail'], 'excluded_paths' => ['D:\\Data\\Web\\Cache'], 'layout' => 'by_folder_name', 'destination_path' => 'win-01/daily'], $over))->json('data.id');
    }

    public function test_a_robocopy_job_hands_the_agent_its_folders_exclusions_layout_and_location_but_no_command_line(): void
    {
        $this->poll(['robocopy' => ['type' => 'robocopy']]);
        $job = $this->job('robocopy');
        $run = $this->admin->post("/api/backup-jobs/$job/run");
        $run->assertStatus(202)->assertJsonPath('data.action', 'robocopy');

        $cmd = $this->poll(['robocopy' => ['type' => 'robocopy']])['command'];
        $this->assertSame('robocopy', $cmd['action']);
        $this->assertSame('nas-main', $cmd['location']);
        $this->assertSame('win-01/daily', $cmd['subpath']);
        $this->assertSame(['layout' => 'by_folder_name', 'folders' => ['D:\\Data\\Web', 'D:\\Data\\Mail'], 'excluded' => ['D:\\Data\\Web\\Cache']], $cmd['source']);
        $this->assertArrayNotHasKey('command', $cmd);
    }

    public function test_an_rsync_job_uses_the_built_in_rsync_the_same_way(): void
    {
        $this->poll(['rsync' => ['type' => 'rsync']]);
        $job = $this->job('rsync', ['source_paths' => ['/srv/www'], 'excluded_paths' => ['/srv/www/cache'], 'layout' => 'contents']);
        $this->admin->post("/api/backup-jobs/$job/run")->assertStatus(202)->assertJsonPath('data.action', 'rsync');
        $this->assertSame(['layout' => 'contents', 'folders' => ['/srv/www'], 'excluded' => ['/srv/www/cache']], $this->poll(['rsync' => ['type' => 'rsync']])['command']['source']);
    }

    public function test_without_the_built_in_the_agents_own_backup_command_still_runs_the_job(): void
    {
        $this->poll(['backup' => ['type' => 'command']]);
        $job = $this->job('robocopy');
        $this->admin->post("/api/backup-jobs/$job/run")->assertStatus(202)->assertJsonPath('data.action', 'backup');
        $this->assertNull($this->poll(['backup' => ['type' => 'command']])['command']['source']);
    }

    public function test_naming_an_action_picks_that_action_instead_of_the_built_in(): void
    {
        $this->poll(['robocopy' => ['type' => 'robocopy'], 'backup' => ['type' => 'command']]);
        $job = $this->job('robocopy');
        $this->admin->post("/api/backup-jobs/$job/run", ['action' => 'backup'])->assertStatus(202)->assertJsonPath('data.action', 'backup');
    }

    public function test_a_job_that_is_not_specific_enough_is_refused_with_what_to_fix(): void
    {
        $this->poll(['robocopy' => ['type' => 'robocopy']]);
        $cases = [
            'whole server' => [['scope_type' => 'full_server', 'source_paths' => null, 'layout' => null], 'specific folders'],
            'no layout' => [['layout' => null], 'laid out'],
            'no location' => [['location_id' => null, 'destination_path' => null], 'backup location'],
        ];
        foreach ($cases as $name => [$over, $expect]) {
            $job = $this->job('robocopy', $over);
            $r = $this->admin->post("/api/backup-jobs/$job/run");
            $r->assertStatus(409)->assertJsonPath('error_code', 'AGENT_007');
            $this->assertStringContainsString($expect, $r->json('error'), $name);
        }
        $this->assertSame(0, DB::table('agent_commands')->count(), 'nothing is queued for a job the agent could not run');
    }

    public function test_a_location_the_agent_does_not_know_is_refused(): void
    {
        $this->poll(['robocopy' => ['type' => 'robocopy']], []);
        $job = $this->job('robocopy');
        $this->admin->post("/api/backup-jobs/$job/run")->assertStatus(409)->assertJsonPath('error_code', 'AGENT_007');
    }

    public function test_the_built_in_actions_are_accepted_in_a_check_in_and_shown_on_the_agents_page(): void
    {
        $this->poll(['robocopy' => ['type' => 'robocopy']]);
        $this->assertSame('robocopy', $this->admin->get('/api/agents')->json('data.agents.0.actions.robocopy.type'));
    }
}
