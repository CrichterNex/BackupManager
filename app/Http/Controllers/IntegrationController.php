<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\Server;
use App\Models\ServerLink;
use App\Services\Audit;
use App\Services\Integrations\AcronisClient;
use App\Services\Integrations\IntegrationException;
use App\Services\Integrations\IntegrationSync;
use App\Support\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Connections to external backup platforms (admins), and links between servers and devices on them. */
class IntegrationController extends Controller
{
    private function find(int $id): Integration
    {
        return Integration::find($id) ?? throw ApiException::notFound('Integration', 'INTEGRATION_001');
    }

    private function present(Integration $i): array
    {
        return [...$i->toArray(), 'links' => $i->links()->count()];
    }

    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (IntegrationException $e) {
            throw ApiException::badRequest($e->getMessage());
        }
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => Integration::orderBy('name')->get()->map(fn ($i) => $this->present($i))->values()]);
    }

    public function store(Request $request)
    {
        $b = $request->validate([
            'platform' => ['required', 'in:acronis'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'base_url' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:512'],
            'stale_after_hours' => ['sometimes', 'integer:strict', 'min:1', 'max:720'],
        ]);
        $url = $this->guard(fn () => AcronisClient::normalizeUrl($b['base_url']));
        $integration = new Integration([
            'platform' => $b['platform'], 'name' => $b['name'], 'base_url' => $url, 'stale_after_hours' => $b['stale_after_hours'] ?? 36,
            'credentials' => ['client_id' => $b['client_id'], 'client_secret' => $b['client_secret']], 'created_by' => $request->user()->id,
        ]);
        // Prove the credentials work before saving them, so a typo is caught here and not at 3 a.m.
        $devices = $this->guard(fn () => (new AcronisClient($integration))->check());
        DB::transaction(function () use ($integration, $request, $devices) {
            $integration->save();
            Audit::log($request, 'integration_created', 'integration', $integration->id, ['platform' => $integration->platform, 'name' => $integration->name, 'devices_visible' => $devices]);
        });

        return response()->json(['success' => true, 'data' => $this->present($integration->refresh()), 'devices_visible' => $devices], 201);
    }

    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($id, $request) {
            $i = $this->find($id);
            Audit::log($request, 'integration_removed', 'integration', $i->id, ['name' => $i->name]);
            $i->delete(); // its server links go with it
        });

        return response()->noContent();
    }

    public function test(int $id)
    {
        $i = $this->find($id);
        $n = $this->guard(fn () => (new AcronisClient($i))->check());

        return response()->json(['success' => true, 'devices_visible' => $n]);
    }

    public function sync(Request $request, int $id)
    {
        $i = $this->find($id);
        $result = IntegrationSync::run($i);
        Audit::log($request, 'integration_synced', 'integration', $i->id, ['status' => $result['status']], $result['status'] === 'ok');

        return response()->json(['success' => $result['status'] === 'ok', 'data' => $result + ['integration' => $this->present($i->refresh())]]);
    }

    /** The platform's devices, so an admin picks one instead of typing an id. Marks the ones already linked. */
    public function devices(int $id)
    {
        $i = $this->find($id);
        $linked = ServerLink::where('integration_id', $i->id)->pluck('server_id', 'external_id');
        $devices = $this->guard(fn () => (new AcronisClient($i))->listMachines());

        return response()->json(['success' => true, 'data' => collect($devices)->map(fn ($d) => [
            'id' => $d['id'], 'name' => $d['name'], 'status' => $d['status'], 'plans' => $d['plans'],
            'last_success_at' => $d['last_success_at']?->toIso8601ZuluString(), 'linked_server_id' => $linked[$d['id']] ?? null,
        ])->sortBy('name')->values()]);
    }

    // --- server links ---

    private function server(int $id): Server
    {
        return Server::where('active', true)->find($id) ?? throw ApiException::notFound('Server', 'SERVER_001');
    }

    public function links(int $id)
    {
        $server = $this->server($id);
        $links = ServerLink::with('integration:id,name,platform')->where('server_id', $server->id)->get();

        return response()->json(['success' => true, 'data' => $links]);
    }

    public function link(Request $request, int $id)
    {
        $b = $request->validate(['integration_id' => ['required', 'integer:strict', 'min:1'], 'external_id' => ['required', 'string', 'max:255']]);
        $link = DB::transaction(function () use ($id, $b, $request) {
            $server = $this->server($id);
            $integration = $this->find($b['integration_id']);
            if (ServerLink::where('server_id', $server->id)->where('integration_id', $integration->id)->exists()) {
                throw ApiException::conflict('LINK_001', 'This server is already linked to a device on that connection. Remove the link first.');
            }
            if ($other = ServerLink::where('integration_id', $integration->id)->where('external_id', $b['external_id'])->first()) {
                throw ApiException::conflict('LINK_002', 'That device is already linked to another server.');
            }
            $device = collect($this->guard(fn () => (new AcronisClient($integration))->listMachines()))->firstWhere('id', $b['external_id'])
                ?? throw ApiException::badRequest('That device was not found on the platform.');
            $l = ServerLink::create(['server_id' => $server->id, 'integration_id' => $integration->id, 'external_id' => $device['id'], 'external_name' => $device['name']]);
            Audit::log($request, 'server_linked', 'server', $server->id, ['integration' => $integration->name, 'device' => $device['name']]);

            return $l;
        });
        IntegrationSync::run($link->integration); // show the real status straight away

        return response()->json(['success' => true, 'data' => $link->refresh()->load('integration:id,name,platform')], 201);
    }

    public function unlink(Request $request, int $id, int $linkId)
    {
        DB::transaction(function () use ($id, $linkId, $request) {
            $link = ServerLink::where('server_id', $this->server($id)->id)->find($linkId) ?? throw ApiException::notFound('Link', 'LINK_003');
            Audit::log($request, 'server_unlinked', 'server', $id, ['device' => $link->external_name]);
            $link->delete();
        });

        return response()->noContent();
    }
}
