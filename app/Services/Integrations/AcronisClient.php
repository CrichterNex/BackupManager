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
     * Machines with an agent, and virtual machines the hypervisor integration protects without one (kind "vm"). A hypervisor host, a group and anything
     * else Acronis lists are not backup targets and are left out. Machines come first, so when a VM has the same name as a machine (an agent installed inside
     * it) the machine is the one that is imported.
     *
     * @return list<array{id: string, name: string, kind: string, external_id: ?string, status: ?string, plans: list<string>, last_run_at: ?CarbonImmutable, last_success_at: ?CarbonImmutable}>
     */
    public function listMachines(): array
    {
        $out = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->get('/resource_management/v4/resource_statuses', array_filter(['limit' => 200, 'after' => $after]));
            foreach ($body['items'] ?? [] as $item) {
                $ctx = $item['context'] ?? [];
                $type = (string) ($ctx['type'] ?? '');
                $isVm = str_starts_with($type, 'resource.virtual_machine.');
                // An item that names no type is read as a machine, as the platform's own machine list always did.
                if (! isset($ctx['id']) || ($type !== '' && $type !== 'resource.machine' && ! $isVm)) {
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
                    'id' => (string) $ctx['id'], 'kind' => $isVm ? 'vm' : 'machine',
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
        usort($out, fn ($a, $b) => $a['kind'] <=> $b['kind']);   // "machine" before "vm"; the sort keeps the rest of the order

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

    /** Acronis's own ids for the commands behind validation and replication. The title is used too, in case an id differs on another datacenter. */
    public const VALIDATION_RUN = '58F58E51-A7F4-408E-8619-90D9C67AE8C9';

    public const VALIDATION = '489B4D82-0676-4F49-BC9F-C6F504A10721';

    private const REPLICATION = ['4B40E257-1B18-47F2-AA94-72A4B1D6E4DF', '44EEE32F-FC8A-4E37-B5BA-A8B16CDC06D6'];

    /** The command Acronis logs for one Microsoft 365 item's backup, and the kind of item each resource type is. */
    private const C2C_BACKUP = '0016750A-EE56-49E6-BF59-7C768C9869AC';

    private const WORKLOAD_RESOURCES = ['MAILBOX' => 'mailbox', 'DRIVE' => 'onedrive', 'TEAM' => 'teams', 'SITE' => 'sharepoint'];

    /** mailbox, onedrive, teams or sharepoint for a finished Microsoft 365 backup of one item, or null for anything else (including Acronis's own cleanup of old backups). */
    public static function workloadOf(array $a): ?string
    {
        if (strtoupper((string) ($a['type'] ?? '')) !== self::C2C_BACKUP || ($a['state'] ?? null) !== 'completed') {
            return null;
        }

        return self::WORKLOAD_RESOURCES[strtoupper((string) ($a['resource']['type'] ?? ''))] ?? null;
    }

    /** validation_run (a pass over a device's backups), validation (one backup's check within a run), replication, or null for any other activity. */
    public static function checkKind(array $a): ?string
    {
        $type = strtoupper((string) ($a['type'] ?? ''));
        $title = (string) ($a['context']['title'] ?? '');

        return match (true) {
            $type === self::VALIDATION_RUN || stripos($title, 'Validating backups') === 0 => 'validation_run',
            $type === self::VALIDATION || $title === 'Validating' => 'validation',
            in_array($type, self::REPLICATION, true) || stripos($title, 'Replicat') === 0 => 'replication',
            default => null,
        };
    }

    /**
     * Validation and replication activities from a cursor on, oldest first. Returns them with the cursor the last page was fetched from, so
     * the next read starts there: it sees the same last page again, which is harmless because activities are stored by their id.
     *
     * With no cursor it starts at $since, not at the beginning of Acronis's history, because a busy tenant logs thousands of activities a day. Besides
     * validation and replication it keeps the finished Microsoft 365 item backups. `through` is how far the read got: now once it reached the end of the log, otherwise when the newest activity read was last updated.
     *
     * @return array{items: list<array<string, mixed>>, cursor: ?string, through: ?CarbonImmutable}
     */
    public function checksFrom(?string $cursor, int $maxPages = 40, ?CarbonImmutable $since = null): array
    {
        $items = [];
        $last = $cursor;
        $through = null;
        $caughtUp = false;
        for ($i = 0; $i < $maxPages; $i++) {
            // A cursor already carries the filter it was made with, so it is sent alone.
            $query = $cursor ? ['limit' => 1000, 'after' => $cursor] : array_filter(['limit' => 1000, 'updatedAt' => $since ? 'gt('.$since->utc()->format('Y-m-d\TH:i:s\Z').')' : null]);
            try {
                $r = $this->get('/task_manager/v2/activities', $query);
            } catch (IntegrationException $e) {
                if ($i === 0) {
                    throw $e;
                }
                break; // keep what earlier pages gave: the next sync carries on from there
            }
            $page = $r['items'] ?? [];
            foreach ($page as $a) {
                if (self::checkKind($a) !== null || self::workloadOf($a) !== null) {
                    $items[] = $a;
                }
                $u = self::parseTime($a['updatedAt'] ?? null);
                $through = $u && (! $through || $u->gt($through)) ? $u : $through;
            }
            $last = $cursor;
            $next = $r['paging']['cursors']['after'] ?? null;
            if (! $next || count($page) < 1000) {
                $caughtUp = true;

                break;
            }
            $cursor = $next;
            $last = $cursor;
        }

        return ['items' => $items, 'cursor' => $last, 'through' => $caughtUp ? CarbonImmutable::now() : $through];
    }

    /** Raw first page, for the probe command only. Never stored. */
    public function rawFirstPage(): array
    {
        return $this->get('/resource_management/v4/resource_statuses', ['type' => 'resource.machine']);
    }
}
