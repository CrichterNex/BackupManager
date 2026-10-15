<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\BackupJob;
use App\Models\BackupLocation;
use App\Models\BackupTestExecution;
use App\Models\Company;
use App\Models\Server;
use App\Services\Audit;
use App\Services\BackupFailures;
use App\Services\TestResults;
use App\Support\ApiException;
use App\Support\BackupPaths;
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

    /** Extra time on top of an action's own limit, for the agent to finish, deliver its result and retry if BMS is briefly unreachable. */
    private const RUN_MARGIN_MINUTES = 30;

    /** A command that started less than this long ago is never treated as lost, so a check-in that crosses its start cannot fail it. */
    private const ORPHAN_GRACE_SECONDS = 60;

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

    /**
     * A new secret for the server's agent, keeping everything set for it in BMS (its locations, source folders, share sign-ins and actions). The old secret
     * stops working at once and anything the agent was doing is cancelled. Use this to reinstall an agent; removing and enrolling again would start from nothing.
     *
     * @return array{0: Agent, 1: string}
     */
    public static function rotateSecret(Server $server): array
    {
        $agent = Agent::where('server_id', $server->id)->first() ?? throw ApiException::notFound('Agent', 'AGENT_005');
        $secret = 'bms_'.Str::random(40);
        AgentCommand::where('agent_id', $agent->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'cancelled', 'completed_at' => now(), 'output' => 'The agent\'s secret was replaced.']);
        $agent->forceFill(['token_hash' => hash('sha256', $secret), 'last_seen_at' => null])->save();

        return [$agent->refresh(), $secret];
    }

    /** Queue a backup run for a job. Only one run per job at a time. */
    public static function queueBackup(BackupJob $job, ?int $userId, ?string $action = null): AgentCommand
    {
        $agent = self::forServer($job->server_id) ?? throw ApiException::conflict('AGENT_001', 'This server has no agent, so BMS cannot start a backup on it.');
        if (! $agent->isOnline() && $userId !== null) {
            throw ApiException::conflict('AGENT_003', 'The agent has not checked in recently. Make sure it is running on the server.');
        }
        // A robocopy or rsync job is run by the agent's built-in action from the job's own scope, so folders are set once, on the job.
        $builtin = $action === null && in_array($job->backup_method, ['robocopy', 'rsync'], true) && ($agent->actions[$job->backup_method]['type'] ?? null) === $job->backup_method;
        $name = $builtin ? $job->backup_method : $agent->actionFor('backup', $action);
        if ($name === null) {
            throw ApiException::badRequest($action ? "The agent has no backup action named \"$action\"." : 'The agent has no backup action. Add one to its config file.');
        }
        if (AgentCommand::where('backup_job_id', $job->id)->where('type', 'backup')->whereIn('status', ['pending', 'running'])->exists()) {
            throw ApiException::conflict('AGENT_004', 'A backup is already queued or running for this job.');
        }
        $job_part = $builtin ? self::builtinBackup($job, $agent) : [];
        $cmd = AgentCommand::create([
            'agent_id' => $agent->id, 'type' => 'backup', 'action' => $name, 'backup_job_id' => $job->id,
            'requested_by' => $userId, 'deadline_at' => now()->addHours(self::BACKUP_DEADLINE_HOURS),
        ] + $job_part)->refresh();
        // Waiting, not running: it runs when the agent picks it up, which is when the status changes.
        $job->forceFill(['status' => 'scheduled', 'started_time' => null, 'completed_time' => null, 'status_message' => 'Waiting for the agent to start it.'])->save();

        return $cmd;
    }

    /**
     * What the agent's built-in robocopy or rsync needs, taken from the job: the folders, what to leave out, how they are laid out
     * in the destination, and the location (as a key, never a path) with the job's folder inside it. Refuses a job that is not
     * specific enough, rather than letting the agent guess.
     *
     * @return array<string, mixed>
     */
    private static function builtinBackup(BackupJob $job, Agent $agent): array
    {
        $why = fn (string $m) => ApiException::conflict('AGENT_007', "This job cannot be run by the agent's built-in {$job->backup_method} yet. $m");
        $folders = $job->source_paths ?? [];
        if ($job->scope_type !== 'folders' || ! $folders) {
            throw $why('Set what it backs up to specific folders (a whole-server scope has no folder list to copy).');
        }
        if (! $job->layout) {
            throw $why('Choose how the backup is laid out in the destination, so files land where restore tests expect them.');
        }
        BackupPaths::assertLayout($job->layout, $job->scope_type, $folders);
        $location = $job->location_id ? BackupLocation::find($job->location_id) : null;
        if (! $location) {
            throw $why('Choose the backup location it writes to.');
        }
        if (! in_array($location->loc_key, $agent->locations ?? [], true)) {
            throw $why("The agent does not know the location \"{$location->loc_key}\". Set its folder on the Agents page in BMS (Edit, then Locations), or give --location {$location->loc_key}=PATH when installing.");
        }

        return [
            'location_key' => $location->loc_key, 'location_subpath' => $job->destination_path,
            'payload' => ['layout' => $job->layout, 'folders' => array_values($folders), 'excluded' => array_values($job->excluded_paths ?? [])],
        ];
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
        // An agent only runs the tests it says it can. An older one would run a random restore and report it as the test that was asked for.
        $type = $test->test_type;
        if (! in_array($type, $agent->actions[$name]['tests'] ?? ['random_restore'], true)) {
            return [false, "The agent (version {$agent->agent_version}) cannot run the $type test. Upgrade it to the latest agent."];
        }
        // The job's location is sent as a key only. The agent turns it into a path from its own config, so BMS never sends a path.
        if ($locationKey !== null && ! in_array($locationKey, $agent->locations ?? [], true)) {
            return [false, "The agent does not know the location \"$locationKey\". Add it under \"locations\" in the agent's config file, then restart the agent."];
        }
        AgentCommand::create([
            'agent_id' => $agent->id, 'type' => 'restore_test', 'test_type' => $type, 'action' => $name, 'location_key' => $locationKey, 'location_subpath' => $locationKey ? $subpath : null, 'payload' => $source, 'test_execution_id' => $test->id,
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
                'actions' => $info['actions'] ?? [], 'remote_actions' => $info['remote_actions'] ?? null, 'locations' => array_values($info['locations'] ?? []), 'source_roots' => array_values($info['source_roots'] ?? []),
            ])->save();
            if (! empty($info['busy'])) {
                return null; // a heartbeat from an agent that is already running something
            }
            // An idle agent that BMS thinks is running something has lost it (the service or the machine restarted, and the copy died
            // with it). Say so now instead of waiting hours for the deadline.
            self::failLost($agent);
            $cmd = AgentCommand::where('agent_id', $agent->id)->where('status', 'pending')->where('deadline_at', '>', now())
                ->orderBy('id')->lockForUpdate()->first();
            $cmd?->forceFill(['status' => 'running', 'started_at' => now(), 'deadline_at' => now()->addSeconds(self::runLimitSeconds($agent, $cmd))])->save();
            if ($cmd && $cmd->type === 'backup' && $cmd->backup_job_id) {
                BackupJob::withoutGlobalScopes()->whereKey($cmd->backup_job_id)->update(['status' => 'running', 'started_time' => now(), 'status_message' => null]);
            }

            return $cmd;
        });
    }

    /**
     * How long a command may run once it has started: the limit its action advertises plus a margin, so a backup that is allowed 24 hours
     * is not written off after 8. An agent that does not say gets the default for that kind of command.
     */
    public static function runLimitSeconds(Agent $agent, AgentCommand $cmd): int
    {
        $default = ($cmd->type === 'backup' ? self::BACKUP_DEADLINE_HOURS : self::TEST_DEADLINE_HOURS) * 3600;
        $action = $agent->actions[$cmd->action] ?? [];
        // A test can have its own limit (reading a whole backup takes far longer than restoring one file).
        $advertised = (int) (($cmd->type === 'restore_test' ? ($action['test_timeouts'][$cmd->test_type ?? 'random_restore'] ?? null) : null) ?? $action['timeout_seconds'] ?? 0);

        return $advertised > 0 ? $advertised + self::RUN_MARGIN_MINUTES * 60 : $default;
    }

    /** Fail every command BMS thinks this agent is running, for an agent that just reported it is idle. */
    private static function failLost(Agent $agent): void
    {
        $lost = AgentCommand::where('agent_id', $agent->id)->where('status', 'running')->where('started_at', '<=', now()->subSeconds(self::ORPHAN_GRACE_SECONDS))->lockForUpdate()->get();
        foreach ($lost as $cmd) {
            self::finishUnreported($cmd, 'failed', 'The agent restarted or lost this before it finished, so the result is unknown. Check the server, then run it again.');
        }
    }

    /** End a command that never reported a result, and fail whatever it was for. */
    private static function finishUnreported(AgentCommand $cmd, string $status, string $why): void
    {
        $cmd->forceFill(['status' => $status, 'completed_at' => now(), 'output' => $why])->save();
        if ($cmd->type === 'backup' && ($job = BackupJob::find($cmd->backup_job_id))) {
            $job->forceFill(['status' => 'failed', 'completed_time' => now(), 'status_message' => $why])->save();
            if ($server = Server::find($job->server_id)) {
                BackupFailures::report($server, 'agent', $job->backup_method, 'failed', $why, $job->id, self::gapFor($job));
            }
        } elseif ($cmd->test_execution_id) {
            self::record($cmd->test_execution_id, ['success' => false, 'failure_type' => $status === 'expired' ? 'timeout' : 'restore_failed', 'severity' => 'high', 'error_message' => $why]);
        }
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

    /** The last good backup of this job's server and method before this one, and how often one is expected, for measuring a gap. */
    private static function gapFor(BackupJob $job): array
    {
        $previous = BackupJob::where('server_id', $job->server_id)->where('backup_method', $job->backup_method)->where('status', 'success')
            ->where('id', '<>', $job->id)->whereNotNull('completed_time')->max('completed_time');
        $server = Server::find($job->server_id);

        return ['last_good_at' => $previous ? CarbonImmutable::parse($previous) : null, 'back_at' => CarbonImmutable::now(),
            'interval' => $server ? BackupFailures::intervalSeconds($server, $job->backup_method, $job->schedule) : null];
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
        if ($server = Server::find($job->server_id)) {
            BackupFailures::report($server, 'agent', $job->backup_method, $ok ? null : 'failed', $message, $job->id, self::gapFor($job));
        }
    }

    private static function finishRestoreTest(AgentCommand $cmd, bool $ok, array $d): void
    {
        // The agent ran it but found nothing it could judge (for example no file whose source it may read). That is a skip, never a pass.
        if (! empty($d['skip_reason'])) {
            TestResults::skip($cmd->test_execution_id, (string) $d['skip_reason']);

            return;
        }
        $result = ['summary' => $d['summary'] ?? null, 'details' => array_intersect_key($d, array_flip(['files', 'bytes', 'free_bytes'])) ?: null, 'success' => $ok, 'hash_algorithm' => $d['hash_algorithm'] ?? null, 'hash_original' => $d['hash_original'] ?? null,
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

    /**
     * A backup that has been waiting for an agent that is not checking in. The command can sit for hours before it expires, and until then nothing
     * would say that the backup is not happening, so after a short grace a problem is raised to review. It is not raised while the agent is online
     * (it may simply be busy with something else), and it clears itself when the backup later runs and succeeds. Returns how many are open.
     */
    public static function flagUnreachable(int $companyId): int
    {
        return CompanyContext::run($companyId, function () {
            $grace = (int) config('bms.agent_pickup_grace_minutes');
            $n = 0;
            $waiting = AgentCommand::where('type', 'backup')->where('status', 'pending')->whereNotNull('backup_job_id')->where('created_at', '<=', now()->subMinutes($grace))->get();
            foreach ($waiting as $cmd) {
                $agent = Agent::find($cmd->agent_id);
                $job = BackupJob::find($cmd->backup_job_id);
                $server = $agent ? Server::find($agent->server_id) : null;
                if (! $agent || ! $job || ! $server || $agent->isOnline()) {
                    continue;
                }
                $seen = $agent->last_seen_at ? 'The agent last checked in at '.$agent->last_seen_at->utc()->format('Y-m-d H:i').' UTC' : 'The agent has never checked in';
                BackupFailures::report($server, 'agent', $job->backup_method, 'failed',
                    "$seen, so the backup queued at ".$cmd->created_at->utc()->format('Y-m-d H:i').' UTC has not started. Check that the agent is running on the server (on the Agents page it shows Offline).',
                    $job->id, self::gapFor($job));
                $n++;
            }

            return $n;
        });
    }

    /** Give up on commands nobody picked up (agent offline) or that never reported back, and say so. */
    public static function expireStale(int $companyId): int
    {
        return CompanyContext::run($companyId, function () {
            $n = 0;
            foreach (AgentCommand::whereIn('status', ['pending', 'running'])->where('deadline_at', '<=', now())->get() as $cmd) {
                $expired = DB::transaction(function () use ($cmd) {
                    // An agent works through one command at a time. A command waiting behind a long backup has not been ignored, it is queued,
                    // so it keeps waiting for as long as that backup is still within its own time. Otherwise a test would be recorded as a
                    // timeout failure for a backup that is perfectly fine.
                    if ($cmd->status === 'pending') {
                        $ahead = AgentCommand::where('agent_id', $cmd->agent_id)->where('status', 'running')->where('deadline_at', '>', now())->orderByDesc('deadline_at')->first();
                        if ($ahead) {
                            $cmd->forceFill(['deadline_at' => CarbonImmutable::instance($ahead->deadline_at)->addHours(self::TEST_DEADLINE_HOURS)])->save();

                            return false;
                        }
                    }
                    $why = $cmd->status === 'pending' ? 'The agent did not pick this up in time. It may be offline.' : 'The agent started this but never reported back within its time limit.';
                    self::finishUnreported($cmd, 'expired', $why);

                    return true;
                });
                $n += $expired ? 1 : 0;
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
