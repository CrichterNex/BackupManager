<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();
        CompanyContext::set(null);
    }

    protected function makeCompany(string $slug, ?int $maxServers = null): int
    {
        return Company::create(['name' => strtoupper($slug), 'slug' => $slug, 'max_servers' => $maxServers])->id;
    }

    protected function makeUser(int $companyId, string $username, string $role, bool $active = true): int
    {
        return User::create([
            'company_id' => $companyId, 'username' => $username, 'email' => "$username@example.com", 'role' => $role,
            'auth_method' => 'local', 'password' => self::PASSWORD, 'active' => $active,
        ])->id;
    }

    /** Log in through the real endpoint and return a client bound to the bearer token. */
    protected function login(string $username, string $password = self::PASSWORD): ApiClient
    {
        $this->flushHeaders();
        Auth::forgetGuards();
        $res = $this->postJson('/api/auth/login', ['username' => $username, 'password' => $password]);
        if ($res->status() !== 200) {
            throw new \RuntimeException("login failed for $username: {$res->status()} ".$res->getContent());
        }

        return new ApiClient($this, $res->json('token'));
    }

    /** Drop any logged-in identity left over from an earlier request in the same test. */
    protected function anonymous(): static
    {
        $this->flushHeaders();
        Auth::forgetGuards();
        CompanyContext::set(null);

        return $this;
    }

    /** Run work as a company, the way the engine does. */
    protected function asCompany(int $companyId, callable $fn): mixed
    {
        return CompanyContext::run($companyId, $fn);
    }
}
