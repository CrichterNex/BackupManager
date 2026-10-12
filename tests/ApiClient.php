<?php

namespace Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/** A logged-in caller. Each request starts from a clean auth state so one user's identity cannot leak into the next call. */
final class ApiClient
{
    public function __construct(private TestCase $test, public string $token) {}

    public function call(string $method, string $url, array $data = []): TestResponse
    {
        Auth::forgetGuards();
        $this->test->flushHeaders();

        return $this->test->withHeaders(['Authorization' => 'Bearer '.$this->token])->json($method, $url, $data);
    }

    public function get(string $url): TestResponse
    {
        return $this->call('GET', $url);
    }

    public function post(string $url, array $data = []): TestResponse
    {
        return $this->call('POST', $url, $data);
    }

    public function patch(string $url, array $data): TestResponse
    {
        return $this->call('PATCH', $url, $data);
    }

    public function put(string $url, array $data): TestResponse
    {
        return $this->call('PUT', $url, $data);
    }

    public function del(string $url): TestResponse
    {
        return $this->call('DELETE', $url);
    }
}
