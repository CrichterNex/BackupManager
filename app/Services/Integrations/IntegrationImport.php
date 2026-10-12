<?php

namespace App\Services\Integrations;

use App\Models\Company;
use App\Models\Integration;
use App\Models\Server;
use App\Models\ServerLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Create BMS servers from the devices a platform connection lists, and link each one. Nothing is imported unless an
 * admin picks it, and a device is never imported twice.
 */
final class IntegrationImport
{
    /** A hostname BMS accepts: letters, digits, dot, dash and underscore, starting and ending with a letter or digit. */
    public static function hostnameFor(string $deviceName): ?string
    {
        $h = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($deviceName));
        $h = trim(substr((string) $h, 0, 255), '-._');

        return $h === '' ? null : $h;
    }

    /** True when the device has a protection plan applied. The API data BMS reads shows plans, not licences. */
    public static function isProtected(array $device): bool
    {
        return $device['plans'] !== [] && ($device['status'] ?? null) !== 'not_protected';
    }

    /**
     * @param  list<string>  $externalIds  device ids to import
     * @param  Collection|null  $devices  the platform's devices keyed by id, if the caller already has them (saves a second call)
     * @param  bool  $syncAfter  read the real status of what was just linked. The sync itself passes false because it does that next.
     * @return array{results: list<array<string, mixed>>, created: int, linked_existing: int, skipped: int}
     */
    public static function run(Integration $integration, array $externalIds, string $osType, bool $linkExistingByHostname, ?Collection $devices = null, bool $syncAfter = true): array
    {
        $devices ??= collect((new AcronisClient($integration))->listMachines())->keyBy('id');
        $linkedIds = ServerLink::where('integration_id', $integration->id)->pluck('external_id')->all();
        $results = [];
        $counts = ['created' => 0, 'linked_existing' => 0, 'skipped' => 0];

        foreach (array_values(array_unique($externalIds)) as $id) {
            $device = $devices->get($id);
            $row = ['external_id' => $id, 'name' => $device['name'] ?? null, 'server_id' => null, 'hostname' => null];
            if ($device === null) {
                $results[] = $row + ['outcome' => 'skipped', 'reason' => 'This device was not found on the platform.'];
                $counts['skipped']++;

                continue;
            }
            if (in_array($id, $linkedIds, true)) {
                $results[] = $row + ['outcome' => 'skipped', 'reason' => 'Already linked to a server.'];
                $counts['skipped']++;

                continue;
            }
            $hostname = self::hostnameFor($device['name']);
            if ($hostname === null) {
                $results[] = $row + ['outcome' => 'skipped', 'reason' => 'The device name cannot be turned into a hostname.'];
                $counts['skipped']++;

                continue;
            }
            $row['hostname'] = $hostname;

            $outcome = DB::transaction(function () use ($integration, $device, $hostname, $osType, $linkExistingByHostname) {
                $existing = Server::where('active', true)->where('hostname', $hostname)->first();
                if ($existing) {
                    if (! $linkExistingByHostname) {
                        return ['skipped', null, 'A server with this hostname already exists.'];
                    }
                    if (ServerLink::where('server_id', $existing->id)->where('integration_id', $integration->id)->exists()) {
                        return ['skipped', $existing->id, 'The server with this hostname is already linked to another device on this connection.'];
                    }
                    // Linking means the server's backups are made by this platform, so make sure the method is listed.
                    $methods = $existing->backup_methods ?? [];
                    if (! in_array($integration->platform, $methods, true)) {
                        $existing->forceFill(['backup_methods' => [...$methods, $integration->platform]])->save();
                    }
                    ServerLink::create(['server_id' => $existing->id, 'integration_id' => $integration->id, 'external_id' => $device['id'], 'external_name' => $device['name']]);

                    return ['linked_existing', $existing->id, null];
                }
                // Serialise creation per company so the subscription limit cannot be raced past.
                $company = Company::whereKey($integration->company_id)->lockForUpdate()->first();
                if ($company->max_servers !== null && Server::where('active', true)->count() >= $company->max_servers) {
                    return ['skipped', null, "Server limit reached for your subscription ({$company->max_servers})."];
                }
                $server = Server::create([
                    'hostname' => $hostname, 'os_type' => $osType, 'criticality' => 'standard', 'backup_methods' => [$integration->platform],
                    'notes' => "Imported from {$integration->name}.",
                ]);
                ServerLink::create(['server_id' => $server->id, 'integration_id' => $integration->id, 'external_id' => $device['id'], 'external_name' => $device['name']]);

                return ['created', $server->id, null];
            });

            [$kind, $serverId, $reason] = $outcome;
            $counts[$kind]++;
            $results[] = array_merge($row, ['outcome' => $kind, 'server_id' => $serverId], $reason ? ['reason' => $reason] : []);
        }

        // Read the real status for everything just linked, so the new servers show their backup state straight away.
        if ($syncAfter && $counts['created'] + $counts['linked_existing'] > 0) {
            IntegrationSync::run($integration);
        }

        return ['results' => $results] + $counts;
    }
}
