<?php

namespace Tests\Feature;

use App\Support\AgentRelease;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The agent bundled with its own Python is served only once someone has made one and put it in place. */
class AgentBundleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bms-bundles-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->dir);
        config(['bms.agent_bundle_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function place(string $name, string $body = 'not really a tarball'): string
    {
        file_put_contents("$this->dir/$name", $body);

        return "$this->dir/$name";
    }

    public function test_there_is_nothing_to_download_until_a_bundle_is_put_in_place(): void
    {
        $this->get('/agent/bundle/x86_64')->assertStatus(404);
        $this->assertSame([], AgentRelease::bundles());
        $this->assertSame([], AgentRelease::summary()['bundles']);
    }

    public function test_a_bundle_downloads_with_its_checksum_and_a_name_that_says_what_it_is(): void
    {
        $this->place('bms-agent-1.9.0-linux-x86_64.tar.gz', 'bundle-bytes');
        $r = $this->get('/agent/bundle/x86_64')->assertOk();
        $this->assertSame(hash('sha256', 'bundle-bytes'), $r->headers->get('X-Content-SHA256'));
        $this->assertSame('application/gzip', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="bms-agent-1.9.0-linux-x86_64.tar.gz"', $r->headers->get('Content-Disposition'));
        $this->assertSame('bundle-bytes', file_get_contents($r->baseResponse->getFile()->getPathname()));
    }

    public function test_the_newest_version_is_served_and_versions_compare_as_numbers(): void
    {
        $this->place('bms-agent-1.9.0-linux-x86_64.tar.gz', 'old');
        $this->place('bms-agent-1.10.0-linux-x86_64.tar.gz', 'new');
        $this->assertStringEndsWith('bms-agent-1.10.0-linux-x86_64.tar.gz', AgentRelease::bundle('x86_64'), '1.10.0 is newer than 1.9.0');
        $this->assertSame('new', file_get_contents($this->get('/agent/bundle/x86_64')->baseResponse->getFile()->getPathname()));
    }

    public function test_each_cpu_type_has_its_own_and_anything_else_is_refused(): void
    {
        $this->place('bms-agent-1.9.0-linux-x86_64.tar.gz');
        $this->get('/agent/bundle/aarch64')->assertStatus(404);
        // A CPU type that is not offered is not a download at all: it falls through to the app's ordinary page, which is HTML and never the file.
        foreach (['armv7', '..%2F..%2F.env', 'x86_64.tar.gz'] as $odd) {
            $r = $this->get("/agent/bundle/$odd");
            $this->assertNotSame('application/gzip', $r->headers->get('Content-Type'), $odd);
            $this->assertNull($r->headers->get('X-Content-SHA256'), $odd);
        }
        $this->assertNull(AgentRelease::bundle('../../.env'));
        $this->place('notes.txt');
        $this->place('bms-agent-latest-linux-x86_64.tar.gz');
        $this->assertSame(1, count(AgentRelease::bundles()), 'other files in the folder are not mistaken for bundles');
    }

    public function test_the_agents_page_data_lists_what_is_available(): void
    {
        $this->place('bms-agent-1.9.0-linux-x86_64.tar.gz', 'abc');
        $b = AgentRelease::summary()['bundles'];
        $this->assertSame(['x86_64', '1.9.0', hash('sha256', 'abc'), 3, '/agent/bundle/x86_64'], [$b[0]['arch'], $b[0]['version'], $b[0]['sha256'], $b[0]['size'], $b[0]['path']]);
    }
}
