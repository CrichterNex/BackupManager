<?php

namespace Tests\Feature;

use App\Support\AgentRelease;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

class AgentsPageTest extends TestCase
{
    private ApiClient $admin;

    private ApiClient $tech;

    private ApiClient $rival;

    private int $serverA;

    private int $serverB;

    private int $serverC;

    protected function setUp(): void
    {
        parent::setUp();
        $company = $this->makeCompany('agentsco');
        $other = $this->makeCompany('rivalagents');
        $this->makeUser($company, 'admin', 'company_admin');
        $techId = $this->makeUser($company, 'tech', 'company_technician');
        $this->makeUser($other, 'rival', 'company_admin');
        $this->admin = $this->login('admin');
        $this->tech = $this->login('tech');
        $this->rival = $this->login('rival');
        foreach (['alpha-01' => 'serverA', 'beta-01' => 'serverB', 'gamma-01' => 'serverC'] as $host => $prop) {
            $this->$prop = $this->admin->post('/api/servers', ['hostname' => $host, 'os_type' => 'linux', 'backup_methods' => ['rsync']])->json('data.id');
        }
        $this->admin->put("/api/servers/{$this->serverA}/assignments", ['user_ids' => [$techId]]);
    }

    private function enroll(int $serverId): string
    {
        return $this->admin->post("/api/servers/$serverId/agent")->json('agent_config.token');
    }

    private function checkIn(string $secret, string $version): void
    {
        Auth::forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => "Bearer $secret"])->postJson('/api/agent/poll', ['version' => $version, 'os' => 'Linux 6.1', 'actions' => ['backup' => ['type' => 'command']], 'locations' => ['nas-main']]);
    }

    public function test_the_page_lists_every_agent_with_its_state_and_the_servers_still_missing_one(): void
    {
        $a = $this->enroll($this->serverA);
        $b = $this->enroll($this->serverB);
        $this->checkIn($a, AgentRelease::version());
        $this->checkIn($b, '0.9.0');
        DB::table('agents')->where('server_id', $this->serverB)->update(['last_seen_at' => now()->subMinutes(30)]);

        $d = $this->admin->get('/api/agents')->json('data');
        $byHost = array_column($d['agents'], null, 'hostname');
        $this->assertTrue($byHost['alpha-01']['online']);
        $this->assertFalse($byHost['alpha-01']['outdated']);
        $this->assertFalse($byHost['beta-01']['online'], 'not seen for 30 minutes');
        $this->assertTrue($byHost['beta-01']['outdated'], '0.9.0 is older than what this BMS serves');
        $this->assertSame(['nas-main'], $byHost['alpha-01']['locations']);
        $this->assertSame(['gamma-01'], array_column($d['servers_without_agent'], 'hostname'));
        $this->assertSame(['total' => 2, 'online' => 1, 'offline' => 1, 'outdated' => 1], $d['counts']);
    }

    public function test_a_waiting_command_shows_against_the_agent(): void
    {
        $a = $this->enroll($this->serverA);
        $this->checkIn($a, AgentRelease::version());
        $job = $this->admin->post('/api/backup-jobs', ['server_id' => $this->serverA, 'backup_method' => 'rsync'])->json('data.id');
        $this->admin->post("/api/backup-jobs/$job/run");
        $this->assertSame(1, $this->admin->get('/api/agents')->json('data.agents.0.pending_commands'));
    }

    public function test_it_never_exposes_secrets_and_is_private_to_the_company(): void
    {
        $secret = $this->enroll($this->serverA);
        $body = $this->admin->get('/api/agents')->getContent();
        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString('token_hash', $body);
        $this->assertSame([], $this->rival->get('/api/agents')->json('data.agents'));
        $this->assertSame([], $this->rival->get('/api/agents')->json('data.servers_without_agent'));
        $this->assertSame(401, $this->anonymous()->getJson('/api/agents')->status());
    }

    public function test_a_technician_sees_only_agents_and_servers_they_are_assigned_to(): void
    {
        $this->enroll($this->serverA);
        $this->enroll($this->serverB);
        $d = $this->tech->get('/api/agents')->json('data');
        $this->assertSame(['alpha-01'], array_column($d['agents'], 'hostname'));
        $this->assertSame([], $d['servers_without_agent']);
    }

    public function test_the_agent_download_is_public_and_matches_the_checksum_the_page_shows(): void
    {
        $res = $this->anonymous()->get(AgentRelease::URL_PATH);
        $res->assertOk();
        $file = file_get_contents(AgentRelease::path());
        $this->assertSame($file, file_get_contents($res->baseResponse->getFile()->getPathname()));
        $this->assertSame(hash('sha256', $file), $res->headers->get('X-Content-SHA256'));
        $this->assertSame(hash('sha256', $file), $this->admin->get('/api/agents')->json('data.release.sha256'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('def do_install', $file, 'the served agent can install itself');
    }

    public function test_the_release_version_is_read_from_the_agent_file(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', AgentRelease::version());
        $this->assertSame(AgentRelease::version(), $this->admin->get('/api/agents')->json('data.release.version'));
        $this->assertFalse(AgentRelease::isOutdated(AgentRelease::version()));
        $this->assertTrue(AgentRelease::isOutdated('0.0.1'));
        $this->assertFalse(AgentRelease::isOutdated(null), 'an agent that has not reported a version is not called outdated');
    }

    public function test_enrolling_returns_install_commands_that_carry_the_secret_once(): void
    {
        $this->admin->post('/api/backup-locations', ['name' => 'Main NAS', 'loc_key' => 'nas-main']);
        $res = $this->admin->post("/api/servers/{$this->serverA}/agent");
        $secret = $res->json('agent_config.token');
        $linux = $res->json('install.linux');
        $windows = $res->json('install.windows');
        $this->assertStringContainsString("BMS_AGENT_TOKEN='$secret'", $linux, 'the secret goes in the environment, not on the command line');
        $this->assertStringNotContainsString("--token $secret", $linux);
        $this->assertStringContainsString('--install', $linux);
        $this->assertStringContainsString('/agent/bms_agent.py', $linux);
        $this->assertStringContainsString('--location nas-main=', $linux, 'prefilled with this company\'s location keys');
        $this->assertStringContainsString('$env:BMS_AGENT_TOKEN=', $windows);
        $this->assertStringContainsString('Invoke-WebRequest', $windows);
        $this->assertStringContainsString('--allow-insecure-http', $linux, 'the test server is plain http');
        $this->assertSame(AgentRelease::version(), $res->json('agent_release.version'));

        $later = $this->admin->get("/api/servers/{$this->serverA}/agent")->getContent();
        $this->assertStringNotContainsString($secret, $later);
        $this->assertStringNotContainsString('BMS_AGENT_TOKEN', $later);
    }

    public function test_commands_for_an_https_bms_do_not_weaken_tls(): void
    {
        $cmds = AgentRelease::installCommands('https://bms.example.com', 'bms_x', []);
        $this->assertStringNotContainsString('--allow-insecure-http', $cmds['linux'].$cmds['windows']);
        $this->assertStringContainsString('--location nas-main=', $cmds['linux'], 'a placeholder when the company has no locations yet');
    }
}
