<?php

namespace App\Services\Integrations;

use App\Models\BackupJob;
use App\Models\Integration;
use App\Models\Server;
use App\Models\ServerLink;
use App\Services\TestResults;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
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
        $linked = $integration->links()->count();
        try {
            $devices = collect((new AcronisClient($integration))->listMachines())->keyBy('id');
        } catch (IntegrationException $e) {
            $changed = $integration->last_sync_status !== 'error';
            $integration->forceFill(['last_sync_at' => now(), 'last_sync_status' => 'error', 'last_sync_error' => $e->getMessage()])->save();
            if ($changed) { // once per outage, not every sync
                TestResults::notifyAdmins('integration_error', "Cannot read backup status from {$integration->name}", $e->getMessage());
            }

            return ['status' => 'error', 'linked' => $linked, 'error' => $e->getMessage()];
        }

        foreach ($integration->links()->with('server')->get() as $link) {
            self::apply($integration, $link, $devices->get($link->external_id));
        }
        $integration->forceFill(['last_sync_at' => now(), 'last_sync_status' => 'ok', 'last_sync_error' => null])->save();

        return ['status' => 'ok', 'linked' => $linked, 'error' => null];
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
            $previous = $server->last_backup_status;
            $at = $state === 'failed' ? $d['last_run_at'] : $d['last_success_at'];
            // Only the successful and failed runs are real runs. A stale warning is a verdict on the last success.
            $runAt = $state === 'failed' ? $d['last_run_at'] : $d['last_success_at'];
            if (! BackupJob::where('server_id', $server->id)->where('backup_method', 'acronis')->where('completed_time', $runAt)->exists()) {
                BackupJob::create([
                    'server_id' => $server->id, 'backup_method' => 'acronis', 'status' => $state === 'failed' ? 'failed' : 'success',
                    'status_message' => "Reported by {$integration->name}".($d['status'] ? " (status: {$d['status']})" : ''),
                    'started_time' => null, 'completed_time' => $runAt,
                ]);
            }
            Server::whereKey($server->id)->update(['last_backup_time' => $at, 'last_backup_status' => $state, 'updated_at' => now()]);
            if (in_array($state, ['failed', 'warning'], true) && $previous !== $state) {
                $msg = $state === 'failed'
                    ? "{$server->hostname}: the latest backup run reported by {$integration->name} did not succeed."
                    : "{$server->hostname}: {$integration->name} has reported no successful backup for over {$integration->stale_after_hours} hours.";
                TestResults::notifyAdmins('backup_failed', $state === 'failed' ? 'Backup failed' : 'Backup is overdue', $msg, 'server', $server->id);
            }
        });
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
