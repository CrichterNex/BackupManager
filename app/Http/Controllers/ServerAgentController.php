<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\BackupJob;
use App\Models\Server;
use App\Services\Agents\AgentService;
use App\Services\Audit;
use App\Support\ApiException;
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
        ], 201);
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
