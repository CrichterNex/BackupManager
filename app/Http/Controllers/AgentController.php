<?php

namespace App\Http\Controllers;

use App\Services\Agents\AgentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The agent's side of the conversation. Authenticated by the agent secret, not by a user. */
class AgentController extends Controller
{
    public function poll(Request $request)
    {
        $agent = $request->attributes->get('agent');
        $data = $request->validate([
            'version' => ['sometimes', 'nullable', 'string', 'max:30'],
            'os' => ['sometimes', 'nullable', 'string', 'max:255'],
            'actions' => ['sometimes', 'array', 'max:50'],
            'actions.*.type' => ['required', Rule::in(['command', 'restore_test'])],
            // True while the agent is running a command: it is only checking in, so no new command is handed out.
            'busy' => ['sometimes', 'boolean:strict'],
        ]);
        foreach (array_keys($data['actions'] ?? []) as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name)) {
                abort(response()->json(['success' => false, 'error' => 'Invalid action name', 'error_code' => 'VALIDATION_001'], 400));
            }
        }
        $data['actions'] = array_map(fn ($a) => ['type' => $a['type']], $data['actions'] ?? []);
        $cmd = AgentService::poll($agent, $data);

        return response()->json(['success' => true, 'command' => $cmd ? [
            'id' => $cmd->id, 'type' => $cmd->type, 'action' => $cmd->action, 'deadline_at' => $cmd->deadline_at,
        ] : null]);
    }

    public function result(Request $request, int $id)
    {
        $agent = $request->attributes->get('agent');
        $r = $request->validate([
            'status' => ['required', Rule::in(['success', 'failed'])],
            'exit_code' => ['sometimes', 'nullable', 'integer:strict'],
            'output' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'details' => ['sometimes', 'nullable', 'array'],
            'details.hash_algorithm' => ['sometimes', 'nullable', 'string', 'max:20'],
            'details.hash_original' => ['sometimes', 'nullable', 'string', 'max:128'],
            'details.hash_restored' => ['sometimes', 'nullable', 'string', 'max:128'],
            'details.failed_item' => ['sometimes', 'nullable', 'string', 'max:500'],
            'details.error_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'details.failure_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'details.severity' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        $cmd = AgentService::handleResult($agent, $id, $r);

        return response()->json(['success' => true, 'data' => ['id' => $cmd->id, 'status' => $cmd->status]]);
    }
}
