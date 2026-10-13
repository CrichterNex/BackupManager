<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\Server;
use App\Support\AgentRelease;
use App\Support\Permissions;
use App\Support\Scope;
use Illuminate\Http\Request;

/** The Agents page: every agent in the company at a glance, plus the servers that do not have one yet. */
class AgentsController extends Controller
{
    public function index(Request $request)
    {
        $me = $request->user();
        $query = Agent::query()->join('servers as s', 's.id', '=', 'agents.server_id')->where('s.active', true)
            ->select('agents.*', 's.hostname', 's.os_type');
        Scope::technician($query, $me, 's.id');
        $agents = $query->orderBy('s.hostname')->get();

        $busy = AgentCommand::whereIn('status', ['pending', 'running'])->whereIn('agent_id', $agents->pluck('id'))
            ->selectRaw('agent_id, SUM(status = \'pending\') AS pending, SUM(status = \'running\') AS running')->groupBy('agent_id')->get()->keyBy('agent_id');

        $canManage = Permissions::can($me->effectiveRole(), 'agents:manage');
        $rows = $agents->map(fn (Agent $a) => [
            'id' => $a->id, 'server_id' => $a->server_id, 'hostname' => $a->hostname, 'server_os' => $a->os_type,
            ...$a->summary(),
            // The commands may carry arguments not everyone should see, so only people who can change them get them.
            ...($canManage ? ['managed_actions' => $a->managedForAgent(), 'managed_locations' => (object) ($a->managed_locations ?? []), 'managed_source_roots' => array_values($a->managed_source_roots ?? []), 'credential_users' => (object) $a->credentialUsers()] : []),
            'outdated' => AgentRelease::isOutdated($a->agent_version),
            'pending_commands' => (int) ($busy[$a->id]->pending ?? 0),
            'running_commands' => (int) ($busy[$a->id]->running ?? 0),
        ])->values();

        // A Microsoft 365 tenant is not a machine, so there is nothing to install an agent on.
        $without = Server::where('active', true)->where('os_type', '<>', 'microsoft_365')->whereNotIn('id', Agent::query()->pluck('server_id'));
        Scope::technician($without, $me, 'servers.id');

        return response()->json(['success' => true, 'data' => [
            'agents' => $rows,
            'servers_without_agent' => $without->orderBy('hostname')->get(['id', 'hostname', 'os_type']),
            'release' => AgentRelease::summary(),
            'counts' => ['total' => $rows->count(), 'online' => $rows->where('online', true)->count(), 'offline' => $rows->where('online', false)->count(), 'outdated' => $rows->where('outdated', true)->count()],
        ]]);
    }
}
