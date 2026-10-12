<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\BackupJob;
use App\Models\BackupTestExecution;
use App\Models\Company;
use App\Models\Server;
use App\Services\Audit;
use App\Services\TestResults;
use App\Support\ApiException;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Remote agents run backups and restore tests on the machines that hold the data. BMS only ever queues a
 * named action; the agent runs what its own local config maps that name to. Results come back through
 * handleResult(), which feeds the same TestResults::record() the local engine uses.
 */
final class AgentService
{
    /** A backup can run this long before BMS gives up on it. Restore tests get a shorter window. */
    private const BACKUP_DEADLINE_HOURS = 8;

    private const TEST_DEADLINE_HOURS = 2;

    /** Longest output kept per command, so a chatty script cannot fill the database. */
    private const OUTPUT_LIMIT = 4000;

    /** Create the server's agent and return it with the secret, which is shown once and stored only as a hash. */
    public static function enroll(Server $server, int $userId): array
    {
        if (Agent::where('server_id', $server->id)->exists()) {
            throw ApiException::conflict('AGENT_002', 'This server already has an agent. Remove it first to enroll a new one.');
        }
        $secret = 'bms_'.Str::random(40);
        $agent = Agent::create(['server_id' => $server->id, 'token_hash' => hash('sha256', $secret), 'created_by' => $userId]);

        return [$agent, $secret];
    }

    public static function forServer(int $serverId): ?Agent
    {
        return Agent::where('server_id', $serverId)->where('active', true)->first();
    }

    // --- queueing ---

    /** Queue a backup run for a job. Only one run per job at a time. */
    public static function queueBackup(BackupJob $job, ?int $userId, ?string $action = null): AgentCommand
    {
        $agent = self::forServer($job->server_id) ?? throw ApiException::conflict('AGENT_001', 'This server has no agent, so BMS cannot start a backup on it.');
        if (! $agent->isOnline() && $userId !== null) {
            throw ApiException::conflict('AGENT_003', 'The agent has not checked in recently. Make sure it is running on the server.');
        }
        $name = $agent->actionFor('backup', $action);
        if ($name === null) {
            throw ApiException::badRequest($action ? "The agent has no backup action named \"$action\"." : 'The agent has no backup action. Add one to its config file.');
        }
        if (AgentCommand::where('backup_job_id', $job->id)->where('type', 'backup')->whereIn('status', ['pending', 'running'])->exists()) {
            throw ApiException::conflict('AGENT_004', 'A backup is already queued or running for this job.');
        }
        $cmd = AgentCommand::create([
            'agent_id' => $agent->id, 'type' => 'backup', 'action' => $name, 'backup_job_id' => $job->id,
            'requested_by' => $userId, 'deadline_at' => now()->addHours(self::BACKUP_DEADLINE_HOURS),
        ])->refresh();
        $job->forceFill(['status' => 'running', 'started_time' => now(), 'completed_time' => null, 'status_message' => null])->save();

        return $cmd;
    }

    /**
     * Hand a restore test to the agent instead of running it on the BMS host. Returns false (and says why)
     * when the agent cannot take it, so the caller can skip the test with an honest reason.
     *
     * @return array{0: bool, 1: ?string}
     */
    public static function queueRestoreTest(BackupTestExecution $test, Agent $agent, ?string $locationKey = null, ?string $subpath = null, ?array $source = null): array
    {
        $name = $agent->actionFor('restore_test');
        if ($name === null) {
            return [false, 'The agent has no restore_test action. Add one to its config file.'];
        }
        // The job's location is sent as a key only. The agent turns it into a path from its own config, so BMS never sends a path.
        if ($locationKey !== null && ! in_array($locationKey, $agent->locations ?? [], true)) {
            return [false, "The agent does not know the location \"$locationKey\". Add it under \"locations\" in the agent's config file, then restart the agent."];
        }
        AgentCommand::create([
            'agent_id' => $agent->id, 'type' => 'restore_test', 'action' => $name, 'location_key' => $locationKey, 'location_subpath' => $locationKey ? $subpath : null, 'payload' => $source, 'test_execution_id' => $test->id,
            'backup_job_id' => $test->backup_job_id, 'requested_by' => $test->executed_by, 'deadline_at' => now()->addHours(self::TEST_DEADLINE_HOURS),
        ]);

        return [true, null];
    }

    // --- the agent's side of the conversation ---

    /** Record a heartbeat and hand over the oldest pending command, marking it running. */
    public static function poll(Agent $agent, array $info): ?AgentCommand
    {
        return DB::transaction(function () use ($agent, $info) {
            $agent->forceFill([
                'last_seen_at' => now(), 'agent_version' => $info['version'] ?? null, 'os_info' => $info['os'] ?? null,
                'actions' => $info['actions'] ?? [], 'locations' => array_values($info['locations'] ?? []), 'source_roots' => array_values($info['source_roots'] ?? []),
            ])->save();
            if (! empty($info['busy'])) {
                return null; // a heartbeat from an agent that is already running something
            }
            $cmd = AgentCommand::where('agent_id', $agent->id)->where('status', 'pending')->where('deadline_at', '>', now())
                ->orderBy('id')->lockForUpdate()->first();
            $cmd?->forceFill(['status' => 'running', 'started_at' => now()])->save();

            return $cmd;
        });
    }

    /**
     * Take the agent's report on a command. A command can be reported once, and only by the agent it was
     * given to. For restore tests the outcome goes through TestResults::record(), the single write path.
     */
    public static function handleResult(Agent $agent, int $commandId, array $r): AgentCommand
    {
        return DB::transaction(function () use ($agent, $commandId, $r) {
            $cmd = AgentCommand::where('agent_id', $agent->id)->lockForUpdate()->find($commandId) ?? throw ApiException::notFound('Command', 'AGENT_005');
            if ($cmd->status !== 'running') {
                throw ApiException::conflict('AGENT_006', "This command is already {$cmd->status}.");
            }
            $ok = $r['status'] === 'success';
            $cmd->forceFill([
                'status' => $ok ? 'success' : 'failed', 'completed_at' => now(), 'exit_code' => $r['exit_code'] ?? null,
                'output' => isset($r['output']) ? Str::limit((string) $r['output'], self::OUTPUT_LIMIT, '') : null,
                'details' => $r['details'] ?? null,
            ])->save();
            Audit::log(null, 'agent_command_finished', 'agent_command', $cmd->id, ['type' => $cmd->type, 'action' => $cmd->action, 'status' => $cmd->status], $ok, ['company_id' => $agent->company_id, 'user_id' => $cmd->requested_by]);

            if ($cmd->type === 'backup') {
                self::finishBackup($cmd, $ok, $r);
            } elseif ($cmd->test_execution_id) {
                self::finishRestoreTest($cmd, $ok, $r['details'] ?? []);
            }

            return $cmd;
        });
    }

    private static function finishBackup(AgentCommand $cmd, bool $ok, array $r): void
    {
        $job = BackupJob::find($cmd->backup_job_id);
        if (! $job) {
            return;
        }
        $message = $ok ? null : (trim((string) ($r['output'] ?? '')) !== '' ? Str::limit(trim($r['output']), 500, '') : 'The backup action exited with an error.');
        $job->forceFill(['status' => $ok ? 'success' : 'failed', 'completed_time' => now(), 'status_message' => $message])->save();
        Server::whereKey($job->server_id)->update(['last_backup_time' => now(), 'last_backup_status' => $ok ? 'success' : 'failed', 'updated_at' => now()]);
        if (! $ok) {
            TestResults::notifyAdmins('backup_failed', 'Backup failed', $message, 'backup_job', $job->id);
        }
    }

    private static function finishRestoreTest(AgentCommand $cmd, bool $ok, array $d): void
    {
        $result = ['success' => $ok, 'hash_algorithm' => $d['hash_algorithm'] ?? null, 'hash_original' => $d['hash_original'] ?? null,
            'hash_restored' => $d['hash_restored'] ?? null, 'failed_item' => $d['failed_item'] ?? null, 'error_message' => $d['error_message'] ?? null,
            'source_status' => $d['source_status'] ?? null, 'source_note' => $d['source_note'] ?? null];
        if (! $ok) {
            $type = $d['failure_type'] ?? 'restore_failed';
            $result['failure_type'] = in_array($type, ['file_missing', 'hash_mismatch', 'corruption', 'timeout', 'access_denied', 'restore_failed'], true) ? $type : 'restore_failed';
            $result['severity'] = in_array($d['severity'] ?? null, ['critical', 'high', 'medium', 'low'], true) ? $d['severity'] : 'high';
        }
        self::record($cmd->test_execution_id, $result);
    }

    private static function record(int $testId, array $result): void
    {
        TestResults::record($testId, $result, ['delay_minutes' => config('bms.test_retry_delay_minutes'), 'max_attempts' => config('bms.test_max_attempts')]);
    }

    // --- engine housekeeping ---

    /** Give up on commands nobody picked up (agent offline) or that never reported back, and say so. */
    public static function expireStale(int $companyId): int
    {
        return CompanyContext::run($companyId, function () {
            $n = 0;
            foreach (AgentCommand::whereIn('status', ['pending', 'running'])->where('deadline_at', '<=', now())->get() as $cmd) {
                DB::transaction(function () use ($cmd) {
                    $why = $cmd->status === 'pending' ? 'The agent did not pick this up in time. It may be offline.' : 'The agent started this but never reported back.';
                    $cmd->forceFill(['status' => 'expired', 'completed_at' => now(), 'output' => $why])->save();
                    if ($cmd->type === 'backup' && ($job = BackupJob::find($cmd->backup_job_id))) {
                        $job->forceFill(['status' => 'failed', 'completed_time' => now(), 'status_message' => $why])->save();
                        TestResults::notifyAdmins('backup_failed', 'Backup did not run', $why, 'backup_job', $job->id);
                    } elseif ($cmd->test_execution_id) {
                        self::record($cmd->test_execution_id, ['success' => false, 'failure_type' => 'timeout', 'severity' => 'high', 'error_message' => $why]);
                    }
                });
                $n++;
            }

            return $n;
        });
    }

    /**
     * Queue backups whose cron schedule came due, for servers that have an online agent. A job runs at most once
     * per scheduled time, and only if that time was within the last day (so a long outage does not cause a pile-up).
     */
    public static function scheduleDueBackups(int $companyId): int
    {
        return CompanyContext::run($companyId, function () {
            $n = 0;
            foreach (BackupJob::whereNotNull('schedule')->get() as $job) {
                $agent = self::forServer($job->server_id);
                if (! $agent || ! $agent->isOnline() || ! $agent->actionFor('backup')) {
                    continue;
                }
                try {
                    $due = CronExpression::factory($job->schedule)->getPreviousRunDate(now())->setTimezone(new \DateTimeZone('UTC'));
                } catch (\Throwable) {
                    continue; // a bad expression is reported when the job is saved, never block the engine on it
                }
                $dueAt = CarbonImmutable::instance($due);
                if ($dueAt->lt(now()->subDay()) || $dueAt->lt($job->created_at)) {
                    continue;
                }
                $already = AgentCommand::where('backup_job_id', $job->id)->where('type', 'backup')->whereNull('requested_by')->where('created_at', '>=', $dueAt)->exists();
                $busy = AgentCommand::where('backup_job_id', $job->id)->where('type', 'backup')->whereIn('status', ['pending', 'running'])->exists();
                if ($already || $busy) {
                    continue;
                }
                self::queueBackup($job, null);
                $n++;
            }

            return $n;
        });
    }

    public static function companyIds()
    {
        return Company::where('is_active', true)->pluck('id');
    }
}
