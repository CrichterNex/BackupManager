<?php

namespace App\Services;

use App\Models\PlatformCheck;
use App\Models\Server;
use Carbon\CarbonImmutable;

/**
 * Judges a device by what Acronis says about its own validation of the backups. A validation run is one pass over a device's backups, and each
 * backup validated in it is a result of its own. The newest run decides, so last night's errors do not count against a device whose later run is
 * clean. And a validation that errors is not always a damaged backup: when Acronis could not open the backup because a credential or archive
 * password is wrong, the backup has not been confirmed either way, and the test says so instead of calling it corruption.
 */
final class PlatformValidation
{
    private const ACCESS = '/(Credential|Password|Permission|AccessDenied|NotAuthori[sz]ed|Unauthori[sz]ed)/i';

    private const INTEGRITY = '/(Corrupt|Checksum|Damaged|Inconsisten|Integrity|BadBlock)/i';

    /** access: Acronis could not open the backup. integrity: it opened it and found it damaged. other: anything it did not explain. */
    public static function classify(?string $code): string
    {
        return match (true) {
            $code !== null && preg_match(self::INTEGRITY, $code) === 1 => 'integrity',
            $code !== null && preg_match(self::ACCESS, $code) === 1 => 'access',
            default => 'other',
        };
    }

    /**
     * Whether Acronis is validating this device, for showing on the server. Acronis's policy list does not name validation plans, so the proof is
     * its own activity record: a plan is confirmed once Acronis has run a validation for the device. The validation happens in Acronis, never in BMS.
     *
     * @return array{confirmed: bool, last_run_at: ?string, last_result: ?string, plan: ?string, runs_30d: int, message: string}
     */
    public static function plan(int $serverId): array
    {
        $runs = PlatformCheck::where('server_id', $serverId)->where('kind', 'validation_run');
        $last = (clone $runs)->orderByDesc('started_at')->first();
        if (! $last) {
            return ['confirmed' => false, 'last_run_at' => null, 'last_result' => null, 'plan' => null, 'runs_30d' => 0,
                'message' => 'No validation plan confirmed. Acronis has not reported a validation for this device. Validation is done by Acronis, not BMS: apply a validation plan to the device in Acronis.'];
        }
        $at = CarbonImmutable::parse($last->started_at)->utc();

        return ['confirmed' => true, 'last_run_at' => $at->toIso8601String(), 'last_result' => $last->result ?? $last->state, 'plan' => $last->plan_name, 'runs_30d' => (clone $runs)->where('started_at', '>=', now()->subDays(30))->count(),
            'message' => 'Validation plan confirmed in Acronis. Acronis runs the validation, and BMS only reads the result. Last run '.$at->format('Y-m-d H:i').' UTC.'];
    }

    /**
     * The result for one device, in the shape TestResults::record() takes, or ['skip' => reason] when there is nothing to judge.
     *
     * @return array<string, mixed>
     */
    public static function evaluate(int $serverId, ?int $maxAgeHours = null): array
    {
        $server = Server::find($serverId);
        $name = $server?->hostname ?? 'this device';
        $maxAgeHours ??= (int) config('bms.platform_validation_max_age_hours');

        $run = PlatformCheck::where('server_id', $serverId)->where('kind', 'validation_run')->orderByDesc('started_at')->first();
        if (! $run) {
            return ['skip' => 'Acronis has not reported any validation for this device. Validation is done by Acronis, not BMS: apply a validation plan to this device in Acronis, and this test will read the result each time it runs.'];
        }
        $started = CarbonImmutable::parse($run->started_at)->utc();
        $when = $started->format('Y-m-d H:i').' UTC';
        if ($started->lt(now()->subHours($maxAgeHours))) {
            return self::fail('file_missing', 'medium', $name, "No Acronis validation has started for this device since $when, more than $maxAgeHours hours ago. Check the validation plan still applies to it.",
                "Last validation $when, too long ago", ['run' => $run->uuid, 'started_at' => $when]);
        }

        $kids = PlatformCheck::where('parent_uuid', $run->uuid)->where('kind', 'validation')->get();
        $errors = $kids->where('result', 'error');
        $ok = $kids->whereIn('result', ['ok', 'warning'])->count();
        $codes = $errors->pluck('error_code')->all();
        // A run can fail as a whole before it validates anything, for example when it cannot reach the backup location.
        if (! $errors->count() && $run->result === 'error') {
            $codes = [$run->error_code];
            $errors = collect([$run]);
        }

        if ($errors->count()) {
            $classes = array_map(fn ($c) => self::classify($c), $codes);
            $worst = in_array('integrity', $classes, true) ? 'integrity' : (in_array('other', $classes, true) ? 'other' : 'access');
            $counts = array_count_values(array_map(fn ($c) => $c ?? 'unknown', $codes));
            arsort($counts);
            $list = implode(', ', array_map(fn ($c, $n) => "$c (x$n)", array_keys($counts), $counts));
            $where = $errors->pluck('detail')->filter()->first();
            $total = max($kids->count(), $errors->count());
            $explain = match ($worst) {
                'integrity' => ' Acronis opened the backup and found it damaged.',
                'access' => ' Acronis could not open the backup, so it is not confirmed either way. Check the credentials or archive password in the validation plan'.($where ? " (location $where)" : '').'.',
                default => ' Acronis did not say what was wrong.',
            };

            return self::fail(
                ['integrity' => 'corruption', 'access' => 'access_denied', 'other' => 'restore_failed'][$worst],
                ['integrity' => 'critical', 'access' => 'medium', 'other' => 'high'][$worst],
                $name,
                "Acronis reported {$errors->count()} validation error".($errors->count() === 1 ? '' : 's')." of $total in the run that started $when: $list.".$explain,
                "{$errors->count()} of $total validations errored: $list",
                ['run' => $run->uuid, 'started_at' => $when, 'ok' => $ok, 'errors' => $counts],
            );
        }

        if ($ok === 0) {
            return ['skip' => "A validation run started at $when but nothing has finished yet, so there is nothing to confirm."];
        }
        $running = $run->state === 'started';
        $summary = "Acronis validated $ok backup".($ok === 1 ? '' : 's').", no errors (run started $when".($running ? ', still running' : '').')';

        return ['success' => true, 'summary' => $summary, 'details' => ['run' => $run->uuid, 'started_at' => $when, 'ok' => $ok, 'running' => $running]];
    }

    /** @return array<string, mixed> */
    private static function fail(string $type, string $severity, string $item, string $message, string $summary, array $details): array
    {
        return ['success' => false, 'failure_type' => $type, 'severity' => $severity, 'failed_item' => $item, 'error_message' => $message, 'summary' => $summary, 'details' => $details];
    }
}
