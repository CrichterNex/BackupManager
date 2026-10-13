<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for the Acronis Cyber Protect Cloud API.
 *
 * Auth: POST {datacenter}/api/2/idp/token with HTTP Basic (client id and secret) and grant_type=client_credentials.
 * Status: GET {datacenter}/api/resource_management/v4/resource_statuses?type=resource.machine. Each item has
 * context (id, name, ...), aggregate (names, status) and policies[] (type, last_run, last_success_run).
 * Written from Acronis's published documentation. Verify against your own console with `php artisan bms:acronis-probe`.
 */
final class AcronisClient
{
    private const TIMEOUT_SECONDS = 20;

    private const MAX_PAGES = 100;

    public function __construct(private Integration $integration) {}

    /**
     * The datacenter URL must be https and on an allowed Acronis domain, because BMS will call it from the server.
     * Without this check an admin could point BMS at an internal address and use it to probe the network.
     */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        // Any port, credentials, query or fragment makes the URL something other than a plain datacenter address.
        $extras = array_intersect_key($parts ?: [], array_flip(['port', 'user', 'pass', 'query', 'fragment']));
        $ok = ($parts['scheme'] ?? '') === 'https' && $host !== '' && $extras === [] && ($path === '' || $path === '/');
        $allowed = false;
        foreach (config('bms.integration_allowed_host_suffixes') as $suffix) {
            $allowed = $allowed || $host === $suffix || str_ends_with($host, '.'.$suffix);
        }
        if (! $ok || ! $allowed) {
            throw new IntegrationException('The datacenter URL must look like https://eu2-cloud.acronis.com (https, an acronis.com host, nothing after it).');
        }

        return 'https://'.$host;
    }

    private function http()
    {
        // No redirects: a redirect could send the request, with its credentials, somewhere that was never validated.
        return Http::timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->acceptJson()->withUserAgent('bms/1.0');
    }

    private function fail(Response $r, string $what): never
    {
        throw new IntegrationException(match (true) {
            $r->status() === 401 => 'Acronis rejected the API client credentials.',
            $r->status() === 403 => "The API client is not allowed to $what. Give it a read-only role on the tenant.",
            $r->status() === 429 => 'Acronis is rate limiting requests. BMS will try again on the next sync.',
            $r->serverError() => 'Acronis had a server problem. BMS will try again on the next sync.',
            default => "Acronis answered {$r->status()} when asked to $what.",
        });
    }

    private function token(bool $fresh = false): string
    {
        $key = 'acronis-token-'.$this->integration->id;
        if (! $fresh && ($cached = Cache::get($key))) {
            return $cached;
        }
        $c = $this->integration->credentials;
        try {
            $r = $this->http()->withBasicAuth($c['client_id'], $c['client_secret'])->asForm()
                ->post($this->integration->base_url.'/api/2/idp/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new IntegrationException('Could not reach Acronis. Check the datacenter URL and your network.');
        }
        if (! $r->successful() || ! is_string($r->json('access_token'))) {
            $this->fail($r, 'sign in');
        }
        $token = $r->json('access_token');
        $expires = (int) $r->json('expires_on'); // a Unix timestamp
        $ttl = $expires > time() ? $expires - time() - 60 : 300;
        Cache::put($key, $token, max(30, $ttl));

        return $token;
    }

    private function get(string $path, array $query = []): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $r = $this->http()->withToken($this->token($attempt > 0))->get($this->integration->base_url.'/api'.$path, $query);
            } catch (ConnectionException) {
                throw new IntegrationException('Could not reach Acronis. Check the datacenter URL and your network.');
            }
            if ($r->status() === 401 && $attempt === 0) {
                continue; // the cached token expired or was revoked: sign in again once
            }
            if (! $r->successful()) {
                $this->fail($r, 'read device status');
            }

            return $r->json() ?? [];
        }
        throw new IntegrationException('Acronis rejected the API client credentials.');
    }

    /** Turn a time the platform sent (ISO text or a Unix number) into a UTC date, or null if it is missing or unreadable. */
    public static function parseTime(mixed $v): ?CarbonImmutable
    {
        if ($v === null || $v === '' || $v === 0) {
            return null;
        }
        try {
            return is_numeric($v) ? CarbonImmutable::createFromTimestampUTC((int) $v) : CarbonImmutable::parse((string) $v)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every machine the API client can see, in BMS's own shape. Only policies whose type mentions "backup" count
     * towards backup times, so an antimalware or patch run is never mistaken for a backup.
     *
     * @return list<array{id: string, name: string, external_id: ?string, status: ?string, plans: list<string>, last_run_at: ?CarbonImmutable, last_success_at: ?CarbonImmutable}>
     */
    public function listMachines(): array
    {
        $out = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->get('/resource_management/v4/resource_statuses', array_filter(['type' => 'resource.machine', 'after' => $after]));
            foreach ($body['items'] ?? [] as $item) {
                $ctx = $item['context'] ?? [];
                if (! isset($ctx['id'])) {
                    continue;
                }
                $runs = $successes = [];
                foreach ($item['policies'] ?? [] as $p) {
                    if (stripos((string) ($p['type'] ?? ''), 'backup') === false) {
                        continue;
                    }
                    $runs[] = self::parseTime($p['last_run'] ?? null);
                    $successes[] = self::parseTime($p['last_success_run'] ?? null);
                }
                $latest = fn (array $times) => collect($times)->filter()->sortDesc()->first();
                $plans = $item['aggregate']['names'] ?? [];
                $out[] = [
                    'id' => (string) $ctx['id'],
                    'name' => (string) ($ctx['user_defined_name'] ?? $ctx['name'] ?? $ctx['id']),
                    'external_id' => isset($ctx['external_id']) ? (string) $ctx['external_id'] : null,
                    'status' => isset($item['aggregate']['status']) ? (string) $item['aggregate']['status'] : null,
                    'plans' => is_array($plans) ? array_values(array_map('strval', $plans)) : [(string) $plans],
                    'last_run_at' => $latest($runs),
                    'last_success_at' => $latest($successes),
                ];
            }
            $after = $body['paging']['cursors']['after'] ?? null;
            if (! $after) {
                break;
            }
        }

        return $out;
    }

    /** Sign in and count devices. Used when a connection is added and by the Test button. */
    public function check(): int
    {
        return count($this->listMachines());
    }

    /** The only read endpoints the probe may look at. Anything else is refused, so the probe cannot be used to explore the API freely. */
    private const PROBE_PATHS = [
        '/resource_management/v4/resource_statuses', '/task_manager/v2/activities', '/alert_manager/v1/alerts', '/policy_management/v4/policies',
    ];

    /**
     * A read-only look at one of a few Acronis endpoints, for the probe command only. Never stored.
     *
     * @param  array<string, scalar>  $query
     */
    public function probeGet(string $path, array $query = []): array
    {
        if (! in_array($path, self::PROBE_PATHS, true)) {
            throw new IntegrationException('The probe may only read: '.implode(', ', self::PROBE_PATHS));
        }

        return $this->get($path, $query);
    }

    /** Raw first page, for the probe command only. Never stored. */
    public function rawFirstPage(): array
    {
        return $this->get('/resource_management/v4/resource_statuses', ['type' => 'resource.machine']);
    }
}
