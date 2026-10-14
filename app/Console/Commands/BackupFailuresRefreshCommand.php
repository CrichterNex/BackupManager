<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Integration;
use App\Models\ServerLink;
use App\Services\BackupFailures;
use App\Services\Integrations\IntegrationSync;
use App\Support\CompanyContext;
use Illuminate\Console\Command;

/** Raise the backup problems that are already visible in what the last Acronis sync recorded, without calling Acronis. */
class BackupFailuresRefreshCommand extends Command
{
    protected $signature = 'bms:backup-failures-refresh';

    protected $description = 'Open review items for backup problems already recorded by the Acronis sync';

    public function handle(): int
    {
        $raised = 0;
        foreach (Company::where('is_active', true)->pluck('id') as $companyId) {
            CompanyContext::run($companyId, function () use (&$raised) {
                foreach (Integration::where('active', true)->get() as $integration) {
                    foreach (ServerLink::with('server')->where('integration_id', $integration->id)->get() as $link) {
                        $server = $link->server;
                        if (! $server || ! $server->active) {
                            continue;
                        }
                        if (str_starts_with($link->external_id, 'm365:')) {
                            continue; // a Microsoft 365 workload is judged from its summary at each sync
                        }
                        $run = $link->last_run_at ? $link->last_run_at->toImmutable() : null;
                        $ok = $link->last_success_at ? $link->last_success_at->toImmutable() : null;
                        $state = IntegrationSync::stateFor($run, $ok, $integration->stale_after_hours);
                        if ($state === 'unknown') {
                            continue;
                        }
                        $kind = BackupFailures::acronisKind($state, $link->last_status);
                        $d = ['last_run_at' => $run, 'last_success_at' => $ok, 'status' => $link->last_status];
                        $f = BackupFailures::report($server, 'acronis', 'acronis', $kind, $kind ? BackupFailures::acronisMessage($kind, $d, $integration) : null, null, BackupFailures::acronisGap($server, $d));
                        $raised += $f && $f->wasRecentlyCreated ? 1 : 0;
                    }
                }
            });
        }
        $this->info("Opened $raised new item(s) to review.");

        return self::SUCCESS;
    }
}
