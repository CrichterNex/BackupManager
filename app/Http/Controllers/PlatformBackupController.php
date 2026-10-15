<?php

namespace App\Http\Controllers;

use App\Models\BackupJob;
use App\Models\Integration;
use App\Models\ServerLink;
use App\Services\Integrations\IntegrationSync;
use App\Services\PlatformWorkloads;
use App\Support\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * What a backup platform (Acronis) says about each server linked to it, for the Backups page: one row for a device, or one for each Microsoft 365
 * workload. These are read from the platform by the sync, so they are what the platform reported at `synced_at`, not something BMS checked.
 */
class PlatformBackupController extends Controller
{
    public function index(Request $request)
    {
        $q = ServerLink::query()->join('servers as s', 's.id', '=', 'server_links.server_id')->join('integrations as i', 'i.id', '=', 'server_links.integration_id')
            ->where('s.active', true)->orderBy('s.hostname')->orderBy('server_links.external_name')
            ->select('server_links.*', 's.hostname', 's.last_test_status', 's.last_test_time', 'i.name as connection', 'i.platform as connection_platform', 'i.stale_after_hours', 'i.id as integration_pk');
        Scope::technician($q, $request->user(), 's.id');

        $rows = $q->get()->map(function ($l) {
            $workload = PlatformWorkloads::isWorkloadId($l->external_id);
            $run = $l->last_run_at ? CarbonImmutable::instance($l->last_run_at) : null;
            $ok = $l->last_success_at ? CarbonImmutable::instance($l->last_success_at) : null;
            $detail = null;
            if ($workload && ($s = PlatformWorkloads::summary(Integration::find($l->integration_pk), PlatformWorkloads::workloadOf($l->external_id)))) {
                $detail = "{$s['ok']} ok, {$s['warning']} with warnings, {$s['failed']} failed of {$s['items']} items".($s['errors'] ? ' ('.implode(', ', array_keys($s['errors'])).')' : '');
            }

            // The newest record BMS keeps of this backup, so a test can be run or done by hand against it from the same row.
            $method = $workload ? 'acronis_m365' : $l->connection_platform;
            $jobId = BackupJob::where('server_id', $l->server_id)->where('is_record', true)->where('backup_method', $method)->when($workload, fn ($x) => $x->where('platform_ref', $l->external_id))
                ->orderByDesc('completed_time')->orderByDesc('id')->value('id');

            return [
                'method' => $method, 'job_id' => $jobId, 'test_status' => $l->last_test_status, 'last_test_time' => $l->last_test_time ? CarbonImmutable::parse($l->last_test_time, 'UTC')->toIso8601ZuluString() : null,
                'server_id' => $l->server_id, 'hostname' => $l->hostname, 'connection' => $l->connection, 'kind' => $workload ? 'workload' : 'device',
                'backup' => $l->external_name ?: $l->external_id,
                // The platform's own word for it (idle, ok, warning, critical...) and BMS's verdict from the times it reported.
                'platform_status' => $workload ? null : $l->last_status,
                'state' => $workload ? ($l->last_status ?: 'unknown') : IntegrationSync::stateFor($run, $ok, (int) $l->stale_after_hours),
                'last_run_at' => $run?->toIso8601ZuluString(), 'last_success_at' => $ok?->toIso8601ZuluString(),
                'synced_at' => $l->synced_at ? CarbonImmutable::instance($l->synced_at)->toIso8601ZuluString() : null, 'sync_error' => $l->sync_error, 'detail' => $detail,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }
}
