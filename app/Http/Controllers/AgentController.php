<?php

namespace App\Http\Controllers;

use App\Services\Agents\AgentService;
use App\Support\Constants;
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
            'actions.*.type' => ['required', Rule::in(['command', 'restore_test', 'robocopy', 'rsync'])],
            // How long the agent lets the action run, so BMS waits that long (plus a margin) before giving up on a command.
            'actions.*.timeout_seconds' => ['sometimes', 'integer:strict', 'min:1', 'max:2764800'],
            // For a restore_test action: which tests this agent can run, and how long each may take.
            'actions.*.tests' => ['sometimes', 'array', 'max:20'],
            'actions.*.tests.*' => ['string', 'max:30'],
            'actions.*.test_timeouts' => ['sometimes', 'array', 'max:20'],
            'actions.*.test_timeouts.*' => ['integer:strict', 'min:1', 'max:2764800'],
            // True while the agent is running a command: it is only checking in, so no new command is handed out.
            'busy' => ['sometimes', 'boolean:strict'],
            // True when the agent's own config allows BMS to supply command actions.
            'remote_actions' => ['sometimes', 'boolean:strict'],
            // Which set of sign-ins the agent already holds. Passwords are only sent when this is out of date.
            'credentials_version' => ['sometimes', 'integer:strict', 'min:0'],
            // Location keys the agent can map to a local path.
            'locations' => ['sometimes', 'array', 'max:100'],
            'locations.*' => ['string', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'max:100'],
            // Folders the agent allows itself to read when comparing a backup with its source.
            'source_roots' => ['sometimes', 'array', 'max:50'],
            'source_roots.*' => ['string', 'max:512'],
        ]);
        foreach (array_keys($data['actions'] ?? []) as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name)) {
                abort(response()->json(['success' => false, 'error' => 'Invalid action name', 'error_code' => 'VALIDATION_001'], 400));
            }
        }
        $known = Constants::TEST_TYPES;
        $data['actions'] = array_map(fn ($a) => ['type' => $a['type']]
            + (isset($a['timeout_seconds']) ? ['timeout_seconds' => (int) $a['timeout_seconds']] : [])
            + (isset($a['tests']) ? ['tests' => array_values(array_intersect($a['tests'], $known))] : [])
            + (isset($a['test_timeouts']) ? ['test_timeouts' => array_intersect_key(array_map('intval', $a['test_timeouts']), array_flip($known))] : []), $data['actions'] ?? []);
        $cmd = AgentService::poll($agent, $data);

        $answer = ['success' => true];
        if ($agent->fresh()->remote_actions) {
            $answer['managed_actions'] = $agent->managedForAgent();
            $answer['managed_locations'] = (object) ($agent->managed_locations ?? []);
            $answer['managed_source_roots'] = array_values($agent->managed_source_roots ?? []);
            if ((int) ($data['credentials_version'] ?? 0) !== (int) $agent->credentials_version) {
                $answer['managed_credentials'] = (object) ($agent->managed_credentials ?? []);
                $answer['credentials_version'] = (int) $agent->credentials_version;
            }
        }

        return response()->json($answer + ['command' => $cmd ? [
            'id' => $cmd->id, 'type' => $cmd->type, 'test_type' => $cmd->test_type, 'action' => $cmd->action, 'location' => $cmd->location_key, 'subpath' => $cmd->location_subpath, 'source' => $cmd->payload, 'deadline_at' => $cmd->deadline_at,
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
            'details.source_status' => ['sometimes', 'nullable', 'in:verified,mismatch,not_comparable'],
            'details.source_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'details.summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'details.skip_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'details.files' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'details.bytes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'details.free_bytes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'details.severity' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        $cmd = AgentService::handleResult($agent, $id, $r);

        return response()->json(['success' => true, 'data' => ['id' => $cmd->id, 'status' => $cmd->status]]);
    }
}
