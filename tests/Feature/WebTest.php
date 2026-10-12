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

    public function test_unknown_api_routes_never_serve_the_shell(): void
    {
        $res = $this->getJson('/api/does-not-exist');
        $this->assertSame(401, $res->status());
        $this->assertStringContainsString('json', $res->headers->get('Content-Type'));
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
