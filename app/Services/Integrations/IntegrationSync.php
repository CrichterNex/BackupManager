<?php

namespace App\Services\Integrations;

use App\Models\BackupJob;
use App\Models\Integration;
use App\Models\Server;
use App\Models\ServerLink;
use App\Services\Audit;
use App\Services\BackupFailures;
use App\Services\PlatformWorkloads;
use App\Services\TestResults;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pull each linked device's backup status from the platform and reflect it in BMS: the server's last backup, a
 * backup record per newly seen run (so dashboards and reports count it), and an alert when it turns bad.
 *
 * What BMS knows is only what the platform says. A sync sees the platform's latest run, so several runs between two
 * syncs show up as one. Reports label these runs as reported by the platform, never as verified by BMS.
 */
final class IntegrationSync
{
    /** @return array{status: string, linked: int, error: ?string} */
    public static function run(Integration $integration): array
    {
        try {
            $devices = collect((new AcronisClient($integration))->listMachines())->keyBy('id');
        } catch (IntegrationException $e) {
            $changed = $integration->last_sync_status !== 'error';
            $integration->forceFill(['last_sync_at' => now(), 'last_sync_status' => 'error', 'last_sync_error' => $e->getMessage()])->save();
            if ($changed) { // once per outage, not every sync
                TestResults::notifyAdmins('integration_error', "Cannot read backup status from {$integration->name}", $e->getMessage());
            }

            return ['status' => 'error', 'linked' => $integration->links()->count(), 'error' => $e->getMessage()];
        }

        if ($integration->auto_import) {
            self::autoImport($integration, $devices);
        }
        $linked = $integration->links()->count();

        // Read the activity first, so the workload summaries used just below include what Acronis finished since the last sync.
        self::syncChecks($integration);
        $tenants = [];
        foreach ($integration->links()->with('server')->get() as $link) {
            if (PlatformWorkloads::isWorkloadId($link->external_id)) {
                $tenants[$link->server_id]['server'] = $link->server;
                $tenants[$link->server_id]['summaries'][] = self::applyWorkload($integration, $link);
            } else {
                self::apply($integration, $link, $devices->get($link->external_id));
            }
        }
        // A Microsoft 365 tenant is several workloads, linked one by one. Its status is all of them together, so it is judged once, here.
        foreach ($tenants as $t) {
            self::judgeTenant($integration, $t['server'], $t['summaries']);
        }
        $integration->forceFill(['last_sync_at' => now(), 'last_sync_status' => 'ok', 'last_sync_error' => null])->save();

        return ['status' => 'ok', 'linked' => $linked, 'error' => null];
    }

    /**
     * Add protected devices that are not in BMS yet as servers. Never touches an existing server (a device whose name is
     * already used is left alone), takes at most a capped number per sync, and tells admins what it added.
     */
    private static function autoImport(Integration $integration, Collection $devices): void
    {
        $linkedIds = ServerLink::where('integration_id', $integration->id)->pluck('external_id')->all();
        $candidates = $devices->filter(fn ($d) => IntegrationImport::isProtected($d) && ! in_array($d['id'], $linkedIds, true) && IntegrationImport::hostnameFor($d['name']) !== null)
            ->keys()->take(config('bms.integration_auto_import_max_per_sync'))->all();
        if (! $candidates) {
            return;
        }
        $r = IntegrationImport::run($integration, $candidates, $integration->auto_import_os, false, $devices, false);
        $created = collect($r['results'])->where('outcome', 'created');
        if ($created->isEmpty()) {
            return; // everything skipped (name already used, server limit): stay quiet rather than repeat it every sync
        }
        $names = $created->pluck('hostname')->take(10)->implode(', ').($created->count() > 10 ? ', and more' : '');
        TestResults::notifyAdmins('servers_imported', "{$created->count()} new server(s) added from {$integration->name}", "BMS added these servers automatically because they have a protection plan: $names. Check they belong here, and set each server's operating system if it is not {$integration->auto_import_os}.", 'integration', $integration->id);
        Audit::log(null, 'integration_auto_import', 'integration', $integration->id, ['created' => $created->count(), 'server_ids' => $created->pluck('server_id')->all()], true, ['company_id' => $integration->company_id, 'user_id' => null]);
    }

    /** ok when the latest backup run succeeded and is recent, failed when it did not, warning when it is stale. */
    public static function stateFor(?CarbonImmutable $lastRun, ?CarbonImmutable $lastSuccess, int $staleHours): string
    {
        if ($lastRun !== null && ($lastSuccess === null || $lastRun->gt($lastSuccess))) {
            return 'failed';
        }
        if ($lastSuccess === null) {
            return 'unknown';
        }

        return $lastSuccess->lt(now()->subHours($staleHours)) ? 'warning' : 'success';
    }

    private static function apply(Integration $integration, ServerLink $link, ?array $d): void
    {
        $server = $link->server;
        if ($d === null) {
            $link->forceFill(['sync_error' => 'This device was not found on the platform. It may have been removed or renamed.', 'synced_at' => now()])->save();

            return;
        }
        $state = self::stateFor($d['last_run_at'], $d['last_success_at'], $integration->stale_after_hours);
        $link->forceFill([
            'external_name' => $d['name'], 'last_status' => $d['status'], 'last_run_at' => $d['last_run_at'],
            'last_success_at' => $d['last_success_at'], 'synced_at' => now(), 'sync_error' => null,
        ])->save();
        if ($state === 'unknown') {
            return; // nothing reported yet: do not invent a result
        }

        DB::transaction(function () use ($server, $d, $state, $integration) {
            $at = $state === 'failed' ? $d['last_run_at'] : $d['last_success_at'];
            // Only the successful and failed runs are real runs. A stale warning is a verdict on the last success.
            $runAt = $state === 'failed' ? $d['last_run_at'] : $d['last_success_at'];
            if (! BackupJob::where('server_id', $server->id)->where('backup_method', 'acronis')->where('completed_time', $runAt)->exists()) {
                BackupJob::create([
                    'is_record' => true,
                    'server_id' => $server->id, 'backup_method' => 'acronis', 'status' => $state === 'failed' ? 'failed' : 'success',
                    'status_message' => "Reported by {$integration->name}".($d['status'] ? " (status: {$d['status']})" : ''),
                    'started_time' => null, 'completed_time' => $runAt,
                ]);
            }
            Server::whereKey($server->id)->update(['last_backup_time' => $at, 'last_backup_status' => $state, 'updated_at' => now()]);
            // A problem somebody has to review: a failed run, a device Acronis calls critical, an overdue backup, or a warning from Acronis.
            $kind = BackupFailures::acronisKind($state, $d['status']);
            BackupFailures::report($server, 'acronis', 'acronis', $kind, $kind ? BackupFailures::acronisMessage($kind, $d, $integration) : null, null, BackupFailures::acronisGap($server, $d));
        });
    }

    /**
     * A linked Microsoft 365 workload: record its summary on the link and keep a backup record for it. Returns the summary, or null when nothing
     * has been read for it yet. The server's own status is judged from all its workloads together, in judgeTenant().
     *
     * @return array<string, mixed>|null
     */
    private static function applyWorkload(Integration $integration, ServerLink $link): ?array
    {
        $server = $link->server;
        $s = PlatformWorkloads::summary($integration, PlatformWorkloads::workloadOf($link->external_id));
        if ($s === null) {
            $link->forceFill(['sync_error' => 'No backups of this kind have been read from the platform yet. They appear after the next sync.', 'synced_at' => now()])->save();

            return null;
        }
        $link->forceFill(['external_name' => $s['label'], 'last_status' => $s['state'], 'last_run_at' => $s['last_run_at'], 'last_success_at' => $s['last_success_at'], 'synced_at' => now(), 'sync_error' => null])->save();
        if ($s['state'] !== 'unknown') {
            $failed = $s['state'] === 'failed';
            $runAt = $failed ? $s['last_run_at'] : $s['last_success_at'];
            if ($runAt) {
                // A workload is many separate backups (one for each mailbox, site and so on), so its last success moves every few minutes. One record
                // for each workload for each day, brought up to date as the day goes on, keeps the history readable and the backup counts honest.
                $day = CarbonImmutable::parse($runAt)->utc()->toDateString();
                $fields = ['status' => $failed ? 'failed' : 'success', 'completed_time' => $runAt,
                    'status_message' => "Reported by {$integration->name}: {$s['label']}, {$s['ok']} ok, {$s['warning']} with warnings, {$s['failed']} failed of {$s['items']} items"];
                $today = BackupJob::where('server_id', $server->id)->where('backup_method', 'acronis_m365')->where('platform_ref', $link->external_id)
                    ->whereDate('completed_time', $day)->orderByDesc('id')->first();
                $today ? $today->forceFill($fields)->save() : BackupJob::create($fields + ['is_record' => true, 'server_id' => $server->id, 'backup_method' => 'acronis_m365', 'platform_ref' => $link->external_id, 'started_time' => null]);
            }
        }

        return $s;
    }

    /**
     * The status of a tenant from every workload linked to it. Failed if any workload failed, a warning if any has gone quiet, otherwise fine. Its last
     * backup is when the oldest workload last succeeded (the whole tenant has been backed up since then), or, if one failed, when that last ran.
     * One problem to review covers the tenant, naming each workload that is not fine.
     *
     * @param  list<array<string, mixed>|null>  $summaries
     */
    private static function judgeTenant(Integration $integration, Server $server, array $summaries): void
    {
        $known = array_values(array_filter($summaries, fn ($s) => $s !== null && $s['state'] !== 'unknown'));
        if (! $known) {
            return; // nothing read yet: do not invent a result
        }
        $failed = array_values(array_filter($known, fn ($s) => $s['state'] === 'failed'));
        $quiet = array_values(array_filter($known, fn ($s) => $s['state'] === 'warning'));
        $state = $failed ? 'failed' : ($quiet ? 'warning' : 'success');
        $times = fn (string $k, array $list) => array_values(array_filter(array_map(fn ($s) => $s[$k], $list)));
        $earliest = fn (array $t) => $t ? collect($t)->sortBy(fn ($x) => $x->getTimestamp())->first() : null;
        $latest = fn (array $t) => $t ? collect($t)->sortByDesc(fn ($x) => $x->getTimestamp())->first() : null;
        $lastGood = $earliest($times('last_success_at', $known));
        $at = $failed ? $latest($times('last_run_at', $failed)) : $lastGood;
        $bad = $failed ?: $quiet;
        DB::transaction(function () use ($integration, $server, $state, $at, $lastGood, $bad) {
            Server::whereKey($server->id)->update(['last_backup_time' => $at, 'last_backup_status' => $state, 'updated_at' => now()]);
            $kind = match ($state) {
                'failed' => 'failed', 'warning' => 'overdue', default => null
            };
            BackupFailures::report($server, 'acronis', 'acronis_m365', $kind, $kind ? implode(' ', array_map(fn ($s) => PlatformWorkloads::message($s, $integration), $bad)) : null, null,
                ['last_good_at' => $lastGood, 'back_at' => $lastGood, 'interval' => BackupFailures::intervalSeconds($server, 'acronis_m365')]);
        });
    }

    /**
     * Keep what Acronis says about its own validation and replication runs. A problem reading it never stops the device statuses from syncing:
     * the devices were read fine, and the next sync carries on from where this one stopped.
     */
    private static function syncChecks(Integration $integration): void
    {
        try {
            // Carry on from where the last read stopped. A first read, or one that has fallen further behind than the look-back, starts from a date instead.
            $lookback = CarbonImmutable::now()->subDays((int) config('bms.activity_lookback_days'));
            $resume = $integration->activity_cursor && $integration->activity_through && CarbonImmutable::parse($integration->activity_through, 'UTC')->gt($lookback);
            $r = (new AcronisClient($integration))->checksFrom($resume ? $integration->activity_cursor : null, 40, $lookback);
            $servers = $integration->links()->pluck('server_id', 'external_id')->mapWithKeys(fn ($s, $e) => [strtolower((string) $e) => $s]);
            $time = fn ($v) => $v ? AcronisClient::parseTime($v)?->utc()->toDateTimeString() : null;
            $rows = [];
            $items = [];
            foreach ($r['items'] as $a) {
                if ($w = AcronisClient::workloadOf($a)) {
                    $items[] = PlatformWorkloads::rowFrom($integration, $w, $a);

                    continue;
                }
                $kind = AcronisClient::checkKind($a);
                $rid = strtolower((string) ($a['resource']['id'] ?? ''));
                $err = $a['result']['error'] ?? null;
                $rows[] = [
                    'company_id' => $integration->company_id, 'integration_id' => $integration->id, 'server_id' => $servers[$rid] ?? null,
                    'uuid' => (string) $a['uuid'], 'parent_uuid' => $kind === 'validation' ? ($a['context']['parentUUID'] ?? null) : null, 'kind' => $kind,
                    'state' => $a['state'] ?? null, 'result' => $a['result']['code'] ?? null, 'error_code' => $err['code'] ?? null,
                    'detail' => mb_substr((string) ($err['context']['resourceAddress'] ?? ''), 0, 500) ?: null,
                    'plan_name' => mb_substr((string) ($a['policy']['name'] ?? ''), 0, 300) ?: null,
                    'resource_id' => $rid ?: null, 'resource_name' => mb_substr((string) ($a['resource']['name'] ?? ''), 0, 255) ?: null,
                    'started_at' => $time($a['startedAt'] ?? $a['createdAt'] ?? null), 'completed_at' => $time($a['completedAt'] ?? null), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('platform_checks')->upsert($chunk, ['uuid'], ['server_id', 'state', 'result', 'error_code', 'detail', 'plan_name', 'completed_at', 'updated_at']);
            }
            PlatformWorkloads::store($items);
            PlatformWorkloads::prune($integration);
            $integration->forceFill(['activity_cursor' => $r['cursor'], 'activity_through' => $r['through']?->utc()->toDateTimeString() ?? $integration->activity_through])->save();
        } catch (\Throwable $e) {
            \Log::warning('Could not read Acronis validation activity: '.$e->getMessage());
        }
    }

    /** Sync every active integration of a company that has not been read for $everyMinutes. Returns how many ran. */
    public static function runDue(int $companyId, int $everyMinutes): int
    {
        return CompanyContext::run($companyId, function () use ($everyMinutes) {
            $n = 0;
            $due = Integration::where('active', true)->where(fn ($q) => $q->whereNull('last_sync_at')->orWhere('last_sync_at', '<=', now()->subMinutes($everyMinutes)))->get();
            foreach ($due as $integration) {
                self::run($integration);
                $n++;
            }

            return $n;
        });
    }
}
