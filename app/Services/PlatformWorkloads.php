<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\PlatformWorkloadItem;
use App\Models\ServerLink;
use App\Services\Integrations\AcronisClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Microsoft 365 backups, one summary per kind of workload. Acronis reports a backup of each mailbox, OneDrive, Team and SharePoint site on its own, so
 * BMS keeps each item's latest result and judges the whole workload from them: failed when too many items failed in their latest backup (or none
 * succeeded), a warning when nothing has been backed up lately, otherwise fine. A few items failing is shown in the summary, not raised as a problem
 * for the whole workload, because in thousands of items some always fail for reasons like a deleted site. A backup that was cancelled is not a failure.
 */
final class PlatformWorkloads
{
    public const LABELS = ['mailbox' => 'Microsoft 365 mailboxes', 'onedrive' => 'OneDrive', 'teams' => 'Microsoft Teams', 'sharepoint' => 'SharePoint sites'];

    /** A link to a workload carries this prefix, so it can never be mistaken for a device id. */
    public const PREFIX = 'm365:';

    public static function isWorkloadId(string $id): bool
    {
        return str_starts_with($id, self::PREFIX) && isset(self::LABELS[substr($id, strlen(self::PREFIX))]);
    }

    public static function workloadOf(string $id): string
    {
        return substr($id, strlen(self::PREFIX));
    }

    /** How the platform's result words are kept: a timed-out backup is an error. */
    public static function resultFor(?string $code): string
    {
        return match ($code) {
            'ok' => 'ok', 'warning' => 'warning', 'cancelled' => 'cancelled', default => 'error',
        };
    }

    /** @return array<string, mixed> one row for platform_workload_items from an Acronis backup activity */
    public static function rowFrom(Integration $integration, string $workload, array $a): array
    {
        $result = self::resultFor($a['result']['code'] ?? null);
        $r = $a['result'] ?? [];
        $first = $r['warnings'][0] ?? null;
        $code = $r['error']['code'] ?? $first['code'] ?? null;
        $message = $r['error']['context']['reason'] ?? $first['context']['message'] ?? $first['debug']['msg'] ?? null;
        $time = AcronisClient::parseTime($a['completedAt'] ?? $a['updatedAt'] ?? null);

        return [
            'company_id' => $integration->company_id, 'integration_id' => $integration->id, 'workload' => $workload, 'resource_id' => mb_substr(strtolower((string) ($a['resource']['id'] ?? '')), 0, 64),
            'plan_name' => mb_substr((string) ($a['policy']['name'] ?? ''), 0, 300) ?: null, 'result' => $result,
            'error_code' => $result === 'ok' ? null : (mb_substr((string) $code, 0, 100) ?: null), 'message' => $result === 'ok' ? null : (mb_substr((string) $message, 0, 300) ?: null),
            'name' => $result === 'ok' ? null : (mb_substr((string) ($a['resource']['name'] ?? ''), 0, 255) ?: null),
            'activity_uuid' => mb_substr((string) ($a['uuid'] ?? ''), 0, 40) ?: null, 'completed_at' => $time?->utc()->toDateTimeString(),
        ];
    }

    /**
     * Store the latest result of each item. Rows arrive oldest first, but a result is only replaced by one that finished later, so a re-read of old
     * activity never puts an old result back.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function store(array $rows): void
    {
        $latest = [];
        foreach ($rows as $r) {
            if ($r['resource_id'] === '' || $r['completed_at'] === null) {
                continue;
            }
            $k = $r['workload'].'|'.$r['resource_id'];
            if (! isset($latest[$k]) || $r['completed_at'] >= $latest[$k]['completed_at']) {
                $latest[$k] = $r;
            }
        }
        $cols = ['company_id', 'integration_id', 'workload', 'resource_id', 'plan_name', 'result', 'error_code', 'message', 'name', 'activity_uuid', 'completed_at', 'created_at', 'updated_at'];
        $keep = ['plan_name', 'result', 'error_code', 'message', 'name', 'activity_uuid'];
        // completed_at is assigned last: later assignments in the same statement see the new value of earlier ones.
        $set = implode(', ', [...array_map(fn ($c) => "$c = IF(VALUES(completed_at) >= completed_at, VALUES($c), $c)", $keep), 'updated_at = VALUES(updated_at)', 'completed_at = GREATEST(completed_at, VALUES(completed_at))']);
        foreach (array_chunk(array_values($latest), 300) as $chunk) {
            $now = now()->toDateTimeString();
            $marks = implode(', ', array_fill(0, count($chunk), '('.implode(', ', array_fill(0, count($cols), '?')).')'));
            $values = [];
            foreach ($chunk as $r) {
                foreach ($cols as $c) {
                    $values[] = $r[$c] ?? ($c === 'created_at' || $c === 'updated_at' ? $now : null);
                }
            }
            DB::statement('INSERT INTO platform_workload_items ('.implode(', ', $cols).") VALUES $marks ON DUPLICATE KEY UPDATE $set", $values);
        }
    }

    /** Forget items that have not been backed up for a month: gone from the tenant, and no longer something to count. */
    public static function prune(Integration $integration): void
    {
        PlatformWorkloadItem::where('integration_id', $integration->id)->where('completed_at', '<', now()->subDays(30))->delete();
    }

    /**
     * What the latest backups of one workload add up to, or null when nothing has been read for it.
     *
     * @return array<string, mixed>|null
     */
    public static function summary(Integration $integration, string $workload): ?array
    {
        $since = now()->subDays((int) config('bms.workload_window_days'));
        $q = fn () => PlatformWorkloadItem::where('integration_id', $integration->id)->where('workload', $workload)->where('completed_at', '>=', $since);
        $by = $q()->selectRaw('result, COUNT(*) AS n')->groupBy('result')->pluck('n', 'result');
        $total = (int) $by->sum();
        if ($total === 0) {
            return null;
        }
        [$ok, $warn, $failed, $cancelled] = [(int) ($by['ok'] ?? 0), (int) ($by['warning'] ?? 0), (int) ($by['error'] ?? 0), (int) ($by['cancelled'] ?? 0)];
        $time = fn ($v) => $v ? CarbonImmutable::parse($v, 'UTC') : null;
        $lastRun = $time($q()->max('completed_at'));
        $lastSuccess = $time($q()->whereIn('result', ['ok', 'warning'])->max('completed_at'));
        $counted = $ok + $warn + $failed;
        $pct = $counted > 0 ? round($failed / $counted * 100, 1) : 0.0;
        $state = match (true) {
            $counted === 0 => 'unknown',
            $ok + $warn === 0 || $pct >= (float) config('bms.workload_failed_percent') => 'failed',
            // While BMS is still reading older activity, an old last backup says nothing about now, so it is not judged.
            self::catchingUp($integration) => 'unknown',
            $lastSuccess === null || $lastSuccess->lt(now()->subHours($integration->stale_after_hours)) => 'warning',
            default => 'success',
        };
        $codes = fn (string $r) => $q()->where('result', $r)->whereNotNull('error_code')->selectRaw('error_code, COUNT(*) AS n')->groupBy('error_code')->orderByDesc('n')->limit(5)->pluck('n', 'error_code')->all();

        return [
            'workload' => $workload, 'label' => self::LABELS[$workload], 'state' => $state, 'items' => $total, 'ok' => $ok, 'warning' => $warn, 'failed' => $failed, 'cancelled' => $cancelled,
            'failed_percent' => $pct, 'last_run_at' => $lastRun, 'last_success_at' => $lastSuccess, 'errors' => $codes('error'), 'warnings' => $codes('warning'),
            'plans' => $q()->whereNotNull('plan_name')->distinct()->pluck('plan_name')->sort()->values()->all(),
            'read_through' => $integration->activity_through ? CarbonImmutable::parse($integration->activity_through, 'UTC') : null,
        ];
    }

    /** True while the read of Acronis's activity is behind: the first read goes back days and takes several syncs. */
    public static function catchingUp(Integration $integration): bool
    {
        return ! $integration->activity_through || CarbonImmutable::parse($integration->activity_through, 'UTC')->lt(now()->subMinutes(3 * (int) config('bms.integration_sync_minutes')));
    }

    /** Every workload read for a connection, for the Integrations page. @return list<array<string, mixed>> */
    public static function forIntegration(Integration $integration): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $w) {
            if ($s = self::summary($integration, $w)) {
                $out[] = self::present($s) + ['problems' => self::problems($integration, $w, 5)];
            }
        }

        return $out;
    }

    /** The items whose latest backup failed, worst first, for a person to act on. @return list<array<string, mixed>> */
    public static function problems(Integration $integration, string $workload, int $limit = 10): array
    {
        $since = now()->subDays((int) config('bms.workload_window_days'));

        return PlatformWorkloadItem::where('integration_id', $integration->id)->where('workload', $workload)->where('result', 'error')->where('completed_at', '>=', $since)
            ->orderByDesc('completed_at')->limit($limit)->get(['name', 'error_code', 'message', 'completed_at'])
            ->map(fn ($i) => ['name' => $i->name ?: 'Unnamed item', 'error_code' => $i->error_code, 'message' => $i->message, 'at' => $i->completed_at?->toIso8601ZuluString()])->all();
    }

    /** A workload in the shape of a device, so a server can be linked to it and synced like one. @return array<string, mixed>|null */
    public static function asDevice(Integration $integration, string $workload): ?array
    {
        $s = self::summary($integration, $workload);

        return $s === null ? null : ['id' => self::PREFIX.$workload, 'name' => $s['label'], 'external_id' => null, 'status' => $s['state'], 'plans' => $s['plans'],
            'last_run_at' => $s['last_run_at'], 'last_success_at' => $s['last_success_at'], 'summary' => $s];
    }

    /** The sentence a failed workload is raised with. */
    public static function message(array $s, Integration $integration): string
    {
        $codes = collect($s['errors'])->map(fn ($n, $c) => "$c (x$n)")->implode(', ');
        $last = 'Last successful backup: '.($s['last_success_at'] ? $s['last_success_at']->utc()->format('Y-m-d H:i').' UTC' : 'never').'.';

        return match ($s['state']) {
            'failed' => "{$s['label']}: {$s['failed']} of ".($s['ok'] + $s['warning'] + $s['failed'])." items failed in their latest backup ({$s['failed_percent']}%) as reported by {$integration->name}.".($codes ? " Errors: $codes." : '')." $last",
            default => "{$integration->name} has reported no successful {$s['label']} backup for over {$integration->stale_after_hours} hours. $last",
        };
    }

    /** What each linked workload of a server adds up to, for the server's page. @return list<array<string, mixed>> */
    public static function forServer(int $serverId): array
    {
        $out = [];
        foreach (ServerLink::with('integration')->where('server_id', $serverId)->where('external_id', 'like', self::PREFIX.'%')->get() as $link) {
            if (! self::isWorkloadId($link->external_id) || ! $link->integration) {
                continue;
            }
            $w = self::workloadOf($link->external_id);
            $s = self::summary($link->integration, $w);
            $out[] = ['workload' => $w, 'label' => self::LABELS[$w], 'connection' => $link->integration->name, 'summary' => $s ? self::present($s) : null, 'problems' => $s ? self::problems($link->integration, $w) : []];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function present(array $s): array
    {
        $iso = fn ($t) => $t?->toIso8601ZuluString();

        return [...$s, 'last_run_at' => $iso($s['last_run_at']), 'last_success_at' => $iso($s['last_success_at']), 'read_through' => $iso($s['read_through']),
            'catching_up' => $s['read_through'] === null || $s['read_through']->lt(now()->subMinutes(3 * (int) config('bms.integration_sync_minutes')))];
    }
}
