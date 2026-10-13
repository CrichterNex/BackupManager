<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\BackupJob;
use App\Models\BackupTestExecution;
use App\Models\BackupTestFailure;
use App\Models\Server;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single write path for test outcomes. The test engine calls it, and there is deliberately no public
 * API to post a result, because a hand-submitted "pass" would undermine the compliance evidence the
 * product exists to produce.
 */
final class TestResults
{
    /** Notify every active user with one of the given roles in the current company. */
    public static function notifyRoles(array $roles, string $eventType, string $subject, string $message, ?string $resourceType = null, ?int $resourceId = null): void
    {
        foreach (User::whereIn('role', $roles)->where('active', true)->where('company_id', CompanyContext::id())->get(['id']) as $u) {
            AppNotification::create([
                'user_id' => $u->id, 'event_type' => $eventType, 'resource_type' => $resourceType, 'resource_id' => $resourceId,
                'subject' => $subject, 'message' => $message, 'created_at' => now(),
            ]);
        }
    }

    public static function notifyAdmins(string $eventType, string $subject, string $message, ?string $resourceType = null, ?int $resourceId = null): void
    {
        self::notifyRoles(['company_admin'], $eventType, $subject, $message, $resourceType, $resourceId);
    }

    /**
     * Record the outcome of a test execution inside the current company context.
     *
     * @param  array{success: bool, hash_algorithm?: string, hash_original?: string, hash_restored?: string, failure_type?: string, severity?: string, failed_item?: string, error_message?: string}  $r
     * @param  array{delay_minutes: int, max_attempts: int}|null  $retry  when set, early failures are retried quietly
     * @return array{retry_scheduled: bool}
     */
    public static function record(int $testId, array $r, ?array $retry = null): array
    {
        return DB::transaction(function () use ($testId, $r, $retry) {
            $exec = BackupTestExecution::whereIn('test_status', ['pending', 'running'])->lockForUpdate()->find($testId);
            if (! $exec) {
                throw new RuntimeException("Test $testId is not pending or running");
            }
            $ok = $r['success'];
            $orig = $r['hash_original'] ?? null;
            $rest = $r['hash_restored'] ?? null;
            $started = $exec->started_time ?? $exec->scheduled_time;
            $exec->forceFill([
                'test_status' => $ok ? 'success' : 'failed',
                'completed_time' => now(),
                'started_time' => $exec->started_time ?? now(),
                'duration_seconds' => max(0, (int) $started->diffInSeconds(now(), false)),
                'hash_algorithm' => $r['hash_algorithm'] ?? null,
                'hash_original' => $orig,
                'hash_restored' => $rest,
                'hash_match' => ($orig === null || $rest === null) ? null : $orig === $rest,
                'error_message' => $r['error_message'] ?? null,
                'summary' => isset($r['summary']) ? mb_substr((string) $r['summary'], 0, 500) : null,
                'details' => $r['details'] ?? null,
                'source_status' => $r['source_status'] ?? null,
                'source_note' => isset($r['source_note']) ? mb_substr($r['source_note'], 0, 500) : null,
            ])->save();

            // Level 1 of the blueprint's failure handling: an early failure is retried quietly, with no failure record or alert.
            if (! $ok && $retry && $exec->retry_count + 1 < $retry['max_attempts']) {
                BackupTestExecution::create([
                    'backup_job_id' => $exec->backup_job_id, 'test_type' => $exec->test_type, 'executed_by' => $exec->executed_by,
                    'retry_of' => $exec->id, 'retry_count' => $exec->retry_count + 1,
                    'scheduled_time' => now()->addMinutes($retry['delay_minutes']),
                ]);

                return ['retry_scheduled' => true];
            }

            $outcome = $ok ? 'passed' : 'failed';
            $job = BackupJob::withTrashed()->find($exec->backup_job_id);
            if ($job) {
                $job->forceFill(['test_status' => $outcome])->save();
                Server::whereKey($job->server_id)->update(['last_test_time' => now(), 'last_test_status' => $outcome, 'updated_at' => now()]);
            }

            if (! $ok) {
                $attempts = $exec->retry_count + 1;
                $base = $r['error_message'] ?? null;
                $failure = BackupTestFailure::create([
                    'test_execution_id' => $exec->id,
                    'failure_type' => $r['failure_type'] ?? 'restore_failed',
                    'severity' => $r['severity'] ?? 'high',
                    'failed_item' => $r['failed_item'] ?? null,
                    'expected_value' => $orig,
                    'actual_value' => $rest,
                    'error_message' => $attempts > 1 ? ($base ?? 'Test failed')." (failed $attempts times)" : $base,
                ]);
                self::notifyAdmins(
                    'test_failed', "Backup test failed ({$exec->test_type})",
                    ($base ?? "A {$exec->test_type} test failed and needs review.").($attempts > 1 ? " It failed $attempts times." : ''),
                    'test_failure', $failure->id,
                );
            }

            return ['retry_scheduled' => false];
        });
    }

    /**
     * Turn a recorded pass into a failure because a second person reviewing the evidence rejected it. This is the only way a recorded result
     * changes. The failure is raised and the admins told exactly as for any other failure, and server health follows.
     */
    public static function overturn(int $testId, string $reason, int $reviewerId): void
    {
        DB::transaction(function () use ($testId, $reason) {
            $exec = BackupTestExecution::lockForUpdate()->find($testId);
            if (! $exec || $exec->test_status !== 'success') {
                throw new RuntimeException("Test $testId is not a recorded pass");
            }
            $message = "Evidence rejected on review: $reason";
            $exec->forceFill(['test_status' => 'failed', 'error_message' => $message])->save();
            $job = BackupJob::withTrashed()->find($exec->backup_job_id);
            if ($job) {
                $job->forceFill(['test_status' => 'failed'])->save();
                Server::whereKey($job->server_id)->update(['last_test_time' => now(), 'last_test_status' => 'failed', 'updated_at' => now()]);
            }
            $failure = BackupTestFailure::create([
                'test_execution_id' => $exec->id, 'failure_type' => 'restore_failed', 'severity' => 'high',
                'failed_item' => $exec->target ?? $job?->id, 'error_message' => $message,
            ]);
            self::notifyAdmins('test_failed', "Backup test failed on review ({$exec->test_type})", $message, 'test_failure', $failure->id);
        });
    }

    /** Mark a test as not run, with the reason. A skipped test is never counted as a pass. */
    public static function skip(int $testId, string $reason): void
    {
        BackupTestExecution::whereKey($testId)->whereIn('test_status', ['pending', 'running'])->update([
            'test_status' => 'skipped', 'completed_time' => now(), 'error_message' => $reason, 'updated_at' => now(),
        ]);
        BackupTestExecution::whereKey($testId)->whereNull('started_time')->update(['started_time' => now()]);
    }
}
