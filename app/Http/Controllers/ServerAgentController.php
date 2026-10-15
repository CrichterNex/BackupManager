<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\BackupJob;
use App\Models\BackupLocation;
use App\Models\Server;
use App\Services\Agents\AgentService;
use App\Services\Audit;
use App\Support\AgentRelease;
use App\Support\ApiException;
use App\Support\BackupPaths;
use App\Support\Page;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Admin side: enroll or remove a server's agent, start a backup, and see what the agents have been doing. */
class ServerAgentController extends Controller
{
    private function server(Request $request, int $id): Server
    {
        $query = Server::where('active', true)->whereKey($id);
        Scope::technician($query, $request->user(), 'servers.id');

        return $query->first() ?? throw ApiException::notFound('Server', 'SERVER_001');
    }

    public function show(Request $request, int $id)
    {
        $server = $this->server($request, $id);
        $agent = Agent::where('server_id', $server->id)->first();

        return response()->json(['success' => true, 'data' => $agent ? $agent->summary() : ['enrolled' => false]]);
    }

    public function enroll(Request $request, int $id)
    {
        $server = $this->server($request, $id);
        if ($server->os_type === 'microsoft_365') {
            throw ApiException::conflict('SERVER_005', 'A Microsoft 365 tenant is not a machine, so there is nothing to install an agent on. Its tests are done by a person from the Tests page.');
        }
        [$agent, $secret] = DB::transaction(function () use ($server, $request) {
            $result = AgentService::enroll($server, $request->user()->id);
            Audit::log($request, 'agent_enrolled', 'server', $server->id, ['hostname' => $server->hostname]);

            return $result;
        });

        return response()->json([
            'success' => true,
            'data' => $agent->summary(),
            // Shown once. Only a hash is stored, so it cannot be shown again, only replaced by removing and re-enrolling.
            'agent_config' => ['bms_url' => $request->getSchemeAndHttpHost(), 'token' => $secret],
            // Copy-and-run commands that download the agent and install it as a service. They carry the secret, so they are shown once too.
            'install' => AgentRelease::installCommands($request->getSchemeAndHttpHost(), $secret, BackupLocation::orderBy('name')->pluck('loc_key')->all()),
            'agent_release' => AgentRelease::summary(),
        ], 201);
    }

    /**
     * Replace the backup commands BMS manages for this agent. Admin only, because whatever is saved here runs on the server
     * with the agent's rights (root / SYSTEM), and every change is written to the audit trail.
     */
    public function updateActions(Request $request, int $id)
    {
        $body = $request->validate([
            'actions' => ['present', 'array', 'max:20'],
            'actions.*.command' => ['nullable', 'array', 'max:50'],
            'actions.*.command.*' => ['string', 'max:1000'],
            'actions.*.cwd' => ['sometimes', 'nullable', 'string', 'max:500'],
            'actions.*.timeout_seconds' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:604800'],
        ]);
        $clean = [];
        foreach ($body['actions'] as $name => $a) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name)) {
                throw ApiException::badRequest('Action names may only use letters, numbers, dot, dash and underscore.');
            }
            $cmd = array_values($a['command'] ?? []);
            if ($cmd && trim($cmd[0]) === '') {
                throw ApiException::badRequest("Action '$name': the first part of the command is the program to run and cannot be empty.");
            }
            $clean[$name] = ['type' => 'command', 'command' => $cmd ?: null]
                + (! empty($a['cwd']) ? ['cwd' => $a['cwd']] : [])
                + (! empty($a['timeout_seconds']) ? ['timeout_seconds' => (int) $a['timeout_seconds']] : []);
        }
        $server = $this->server($request, $id);
        $agent = DB::transaction(function () use ($server, $clean, $request) {
            $agent = Agent::where('server_id', $server->id)->lockForUpdate()->first() ?? throw ApiException::notFound('Agent', 'AGENT_005');
            if ($agent->remote_actions === false) {
                throw ApiException::conflict('AGENT_006', 'This agent was installed with remote actions turned off, so only its own config file decides what it runs.');
            }
            foreach (array_keys($clean) as $name) {
                if (($agent->actions[$name]['type'] ?? null) === 'restore_test') {
                    throw ApiException::badRequest("'$name' is a restore test action on this agent and cannot be replaced with a command.");
                }
            }
            $before = $agent->managed_actions ?? [];
            $agent->forceFill(['managed_actions' => $clean ?: null])->save();
            Audit::log($request, 'agent_actions_updated', 'server', $server->id, ['hostname' => $server->hostname, 'before' => $before, 'after' => $clean]);

            return $agent;
        });

        return response()->json(['success' => true, 'data' => $agent->summary() + ['managed_actions' => $agent->managedForAgent()]]);
    }

    /**
     * Say which folder on this server each backup location key means. Admin only and audited. The agent still only ever uses
     * these as a place to write backups to and read them from; a location that is not a BMS location cannot be set.
     */
    public function updateLocations(Request $request, int $id)
    {
        $body = $request->validate(['locations' => ['present', 'array', 'max:50'], 'locations.*' => ['required', 'string', 'max:512']]);
        $known = BackupLocation::pluck('loc_key')->all();
        $clean = [];
        foreach ($body['locations'] as $key => $path) {
            if (! is_string($key) || ! in_array($key, $known, true)) {
                throw ApiException::badRequest('"'.$key.'" is not one of your backup locations. Add it on the Locations page first.');
            }
            $norm = str_replace('\\', '/', trim($path));
            $absolute = str_starts_with($norm, '/') || preg_match('#^[A-Za-z]:(/|$)#', $norm);
            if (! $absolute || str_contains($path, "\0") || in_array('..', explode('/', $norm), true)) {
                throw ApiException::badRequest("The folder for \"$key\" must be a full path such as /mnt/nas/backups, D:\\Backups or \\\\nas\\share, with no \"..\".");
            }
            $clean[$key] = trim($path);
        }
        $server = $this->server($request, $id);
        $agent = DB::transaction(function () use ($server, $clean, $request) {
            $agent = Agent::where('server_id', $server->id)->lockForUpdate()->first() ?? throw ApiException::notFound('Agent', 'AGENT_005');
            if ($agent->remote_actions === false) {
                throw ApiException::conflict('AGENT_006', 'This agent was installed with remote actions turned off, so only its own config file decides what it runs.');
            }
            $before = $agent->managed_locations ?? [];
            $agent->forceFill(['managed_locations' => $clean ?: null])->save();
            Audit::log($request, 'agent_locations_updated', 'server', $server->id, ['hostname' => $server->hostname, 'before' => $before, 'after' => $clean]);

            return $agent;
        });

        return response()->json(['success' => true, 'data' => $agent->summary() + ['managed_locations' => (object) ($agent->managed_locations ?? [])]]);
    }

    /**
     * The folders this agent may read when it backs up a job's folders or compares a backup with its source. Admin only and audited.
     * A folder here is a place the agent will read as root / SYSTEM, so only company admins can add one.
     *
     * @see BackupPaths::source() for what a valid folder is
     */
    public function updateSourceRoots(Request $request, int $id)
    {
        $body = $request->validate(['source_roots' => ['present', 'array', 'max:50'], 'source_roots.*' => ['required', 'string', 'max:512']]);
        $clean = BackupPaths::sourceList($body['source_roots']);
        $server = $this->server($request, $id);
        $agent = DB::transaction(function () use ($server, $clean, $request) {
            $agent = Agent::where('server_id', $server->id)->lockForUpdate()->first() ?? throw ApiException::notFound('Agent', 'AGENT_005');
            if ($agent->remote_actions === false) {
                throw ApiException::conflict('AGENT_006', 'This agent was installed with remote actions turned off, so only its own config file decides what it runs.');
            }
            $before = $agent->managed_source_roots ?? [];
            $agent->forceFill(['managed_source_roots' => $clean ?: null])->save();
            Audit::log($request, 'agent_source_roots_updated', 'server', $server->id, ['hostname' => $server->hostname, 'before' => $before, 'after' => $clean]);

            return $agent;
        });

        return response()->json(['success' => true, 'data' => $agent->summary() + ['managed_source_roots' => array_values($agent->managed_source_roots ?? [])]]);
    }

    /**
     * Set or remove the account an agent signs in with for one location's network share. Admin only and audited, but the audit
     * entry holds the user name only. The password is stored encrypted, is never returned by any call, and is sent to the
     * agent over HTTPS only when it changes.
     */
    public function updateCredentials(Request $request, int $id)
    {
        $body = $request->validate([
            'location' => ['required', 'string', 'max:100'],
            'remove' => ['sometimes', 'boolean'],
            'username' => ['required_unless:remove,true', 'nullable', 'string', 'max:200'],
            'password' => ['required_unless:remove,true', 'nullable', 'string', 'max:500'],
        ]);
        if (! BackupLocation::where('loc_key', $body['location'])->exists()) {
            throw ApiException::badRequest('"'.$body['location'].'" is not one of your backup locations.');
        }
        $remove = (bool) ($body['remove'] ?? false);
        $server = $this->server($request, $id);
        $agent = DB::transaction(function () use ($server, $body, $remove, $request) {
            $agent = Agent::where('server_id', $server->id)->lockForUpdate()->first() ?? throw ApiException::notFound('Agent', 'AGENT_005');
            if ($agent->remote_actions === false) {
                throw ApiException::conflict('AGENT_006', 'This agent was installed with remote actions turned off, so only its own config file decides what it runs.');
            }
            $all = $agent->managed_credentials ?? [];
            if ($remove) {
                unset($all[$body['location']]);
            } else {
                $all[$body['location']] = ['username' => trim($body['username']), 'password' => $body['password']];
            }
            $agent->forceFill(['managed_credentials' => $all ?: null, 'credentials_version' => $agent->credentials_version + 1])->save();
            Audit::log($request, $remove ? 'agent_credentials_removed' : 'agent_credentials_set', 'server', $server->id,
                ['hostname' => $server->hostname, 'location' => $body['location']] + ($remove ? [] : ['username' => trim($body['username'])]));

            return $agent;
        });

        return response()->json(['success' => true, 'data' => $agent->summary() + ['credential_users' => (object) $agent->credentialUsers()]]);
    }

    /** A new secret and install command for the existing agent, keeping the settings made for it in BMS. */
    public function rotate(Request $request, int $id)
    {
        $server = $this->server($request, $id);
        [$agent, $secret] = DB::transaction(function () use ($server, $request) {
            $result = AgentService::rotateSecret($server);
            Audit::log($request, 'agent_secret_replaced', 'server', $server->id, ['hostname' => $server->hostname]);

            return $result;
        });

        return response()->json([
            'success' => true, 'data' => $agent->summary(),
            'agent_config' => ['bms_url' => $request->getSchemeAndHttpHost(), 'token' => $secret],
            'install' => AgentRelease::installCommands($request->getSchemeAndHttpHost(), $secret, BackupLocation::orderBy('name')->pluck('loc_key')->all()),
            'agent_release' => AgentRelease::summary(),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $server = $this->server($request, $id);
        DB::transaction(function () use ($server, $request) {
            $agent = Agent::where('server_id', $server->id)->first() ?? throw ApiException::notFound('Agent', 'AGENT_005');
            AgentCommand::where('agent_id', $agent->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'cancelled', 'completed_at' => now(), 'output' => 'The agent was removed.']);
            $agent->delete();
            Audit::log($request, 'agent_removed', 'server', $server->id, ['hostname' => $server->hostname]);
        });

        return response()->noContent();
    }

    // Start a backup now through the server's agent.
    public function runBackup(Request $request, int $id)
    {
        $b = $request->validate(['action' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $cmd = DB::transaction(function () use ($id, $b, $request) {
            $query = BackupJob::query()->whereKey($id);
            Scope::technician($query, $request->user(), 'backup_jobs.server_id');
            $job = $query->lockForUpdate()->first() ?? throw ApiException::notFound('Backup job', 'BACKUP_001');
            $cmd = AgentService::queueBackup($job, $request->user()->id, $b['action'] ?? null);
            Audit::log($request, 'backup_run_requested', 'backup_job', $job->id, ['action' => $cmd->action]);

            return $cmd;
        });

        return response()->json(['success' => true, 'data' => $cmd->only(['id', 'type', 'action', 'status', 'deadline_at', 'created_at'])], 202);
    }

    public function commands(Request $request)
    {
        $q = $request->validate(['backup_job_id' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', 'string', 'max:20']]);
        $query = AgentCommand::query()->join('agents as a', 'a.id', '=', 'agent_commands.agent_id')->join('servers as s', 's.id', '=', 'a.server_id')
            ->select('agent_commands.*', 's.hostname');
        isset($q['backup_job_id']) && $query->where('agent_commands.backup_job_id', $q['backup_job_id']);
        isset($q['status']) && $query->where('agent_commands.status', $q['status']);
        Scope::technician($query, $request->user(), 's.id');
        $query->orderByDesc('agent_commands.id');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json(['success' => true, 'data' => $rows, 'pagination' => $pagination]);
    }
}
