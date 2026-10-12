<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebTest extends TestCase
{
    public function test_the_shell_is_served_at_the_root_and_for_deep_links(): void
    {
        foreach (['/', '/companies/acme/servers', '/anything/else'] as $path) {
            $res = $this->get($path);
            $res->assertOk();
            $this->assertStringContainsString('<div id="app">', file_get_contents($res->baseResponse->getFile()->getPathname()), $path);
        }
    }

    public function test_static_assets_exist(): void
    {
        foreach (['app.js', 'lib.js', 'styles.css', 'index.html'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }

    public function test_the_web_scripts_are_valid_javascript(): void
    {
        // One syntax error in app.js or lib.js leaves the whole app blank, so check them as the browser reads them (modules).
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node is not installed, so the scripts cannot be syntax-checked here.');
        }
        foreach (['app.js', 'lib.js'] as $file) {
            $out = [];
            exec('node --input-type=module --check < '.escapeshellarg(public_path($file)).' 2>&1', $out, $code);
            $this->assertSame(0, $code, "$file has a syntax error:\n".implode("\n", $out));
        }
    }

    public function test_unknown_api_routes_never_serve_the_shell(): void
    {
        $res = $this->getJson('/api/does-not-exist');
        $this->assertSame(401, $res->status());
        $this->assertStringContainsString('json', $res->headers->get('Content-Type'));
    }

    public function test_the_api_answers_401_as_json_even_when_the_caller_does_not_ask_for_json(): void
    {
        // A browser fetch or curl sends Accept: */*. This used to be a 500 (redirect to a login route that does not
        // exist), which left the web app blank instead of showing its login screen.
        foreach (['/api/me', '/api/servers', '/api/dashboard'] as $path) {
            $res = $this->get($path);
            $this->assertSame(401, $res->status(), $path);
            $this->assertStringContainsString('json', $res->headers->get('Content-Type'), $path);
            $this->assertSame('AUTH_002', $res->json('error_code'), $path);
        }
        $this->assertSame(401, $this->post('/api/auth/login', ['username' => 'x', 'password' => 'y'])->status());
    }

    public function test_security_headers_and_no_store_are_sent(): void
    {
        $res = $this->getJson('/api/me');
        $this->assertStringContainsString("script-src 'self'", $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertNull($res->headers->get('Access-Control-Allow-Origin'));
    }
}
