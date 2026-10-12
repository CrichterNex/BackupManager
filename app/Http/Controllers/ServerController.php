<?php

namespace App\Http\Controllers;

use App\Models\BackupJob;
use App\Models\Company;
use App\Models\Server;
use App\Models\User;
use App\Services\Audit;
use App\Support\ApiException;
use App\Support\Constants;
use App\Support\Page;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    private const COLS = ['hostname', 'ip_address', 'os_type', 'os_name', 'criticality', 'backup_methods', 'backup_location_path', 'retention_policy_days', 'notes'];

    public function index(Request $request)
    {
        $me = $request->user();
        $q = $request->validate([
            'os' => ['sometimes', Rule::in(['linux', 'windows'])],
            'status' => ['sometimes', Rule::in(['healthy', 'failing', 'pending_test', 'unknown'])],
            'criticality' => ['sometimes', Rule::in(['critical', 'standard', 'non-critical'])],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = Server::where('active', true);
        if (isset($q['os'])) {
            $query->where('os_type', $q['os']);
        }
        if (isset($q['criticality'])) {
            $query->where('criticality', $q['criticality']);
        }
        if (isset($q['search'])) {
            $query->where('hostname', 'like', '%'.addcslashes($q['search'], '%_\\').'%');
        }
        if (isset($q['status'])) {
            $query->whereRaw('('.Server::STATUS_SQL.') = ?', [$q['status']]);
        }
        Scope::technician($query, $me, 'servers.id');
        [$column, $dir] = Page::order($request, ['hostname' => 'servers.hostname', 'status' => 'status', 'last_backup' => 'servers.last_backup_time', 'last_test' => 'servers.last_test_time'], 'hostname');
        $column === 'status' ? $query->orderByRaw('('.Server::STATUS_SQL.") $dir") : $query->orderBy($column, $dir);
        $query->orderBy('servers.id');
        [$rows, $pagination] = Page::run($request, $query);

        return response()->json(['success' => true, 'data' => $rows, 'pagination' => $pagination]);
    }

    public function show(Request $request, int $id)
    {
        $query = Server::where('active', true)->whereKey($id);
        Scope::technician($query, $request->user(), 'servers.id');
        $server = $query->first() ?? throw ApiException::notFound('Server', 'SERVER_001');
        $assigned = DB::table('server_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.server_id', $server->id)->orderBy('u.username')->get(['u.id', 'u.username']);

        return response()->json(['success' => true, 'data' => [...$server->toArray(), 'assigned_technicians' => $assigned]]);
    }

    private function rules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'hostname' => [$req, 'string', 'min:1', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?$/'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'os_type' => [$req, Rule::in(['linux', 'windows'])],
            'os_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'criticality' => ['sometimes', Rule::in(['critical', 'standard', 'non-critical'])],
            'backup_methods' => ['sometimes', 'array', 'max:'.count(Constants::BACKUP_METHODS)],
            'backup_methods.*' => [Rule::in(Constants::BACKUP_METHODS)],
            'backup_location_path' => ['sometimes', 'nullable', 'string', 'max:512'],
            'retention_policy_days' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    private function duplicateHostname(string $hostname, ?int $exceptId = null): bool
    {
        return Server::where('active', true)->where('hostname', $hostname)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
    }

    public function store(Request $request)
    {
        $body = $request->validate($this->rules(false));
        $company = Company::findOrFail($request->user()->company_id);
        $server = DB::transaction(function () use ($body, $company, $request) {
            // Serialise server creation per company so the subscription limit cannot be raced past.
            Company::whereKey($company->id)->lockForUpdate()->first();
            if ($company->max_servers !== null && Server::where('active', true)->count() >= $company->max_servers) {
                throw ApiException::conflict('SERVER_004', "Server limit reached for your subscription ({$company->max_servers})");
            }
            if ($this->duplicateHostname($body['hostname'])) {
                throw ApiException::conflict('SERVER_002', 'Hostname already exists');
            }
            $data = ['criticality' => 'standard', 'backup_methods' => []];
            foreach (self::COLS as $c) {
                if (array_key_exists($c, $body)) {
                    $data[$c] = $body[$c];
                }
            }
            $s = Server::create($data);
            Audit::log($request, 'server_created', 'server', $s->id, ['hostname' => $body['hostname']]);

            return $s->refresh();
        });

        return response()->json(['success' => true, 'data' => $server], 201);
    }

    public function update(Request $request, int $id)
    {
        $body = $request->validate($this->rules(true));
        if (! $body) {
            throw ApiException::badRequest('No fields to update');
        }
        $server = DB::transaction(function () use ($id, $body, $request) {
            $s = Server::where('active', true)->lockForUpdate()->find($id) ?? throw ApiException::notFound('Server', 'SERVER_001');
            if (isset($body['hostname']) && $this->duplicateHostname($body['hostname'], $id)) {
                throw ApiException::conflict('SERVER_002', 'Hostname already exists');
            }
            $s->fill(array_intersect_key($body, array_flip(self::COLS)))->save();
            Audit::log($request, 'server_updated', 'server', $id, $body);

            return $s->refresh();
        });

        return response()->json(['success' => true, 'data' => $server]);
    }

    // Servers are soft-deleted so backup and test history stays intact for compliance evidence.
    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($id, $request) {
            $s = Server::where('active', true)->lockForUpdate()->find($id) ?? throw ApiException::notFound('Server', 'SERVER_001');
            $s->forceFill(['active' => false])->save();
            BackupJob::where('server_id', $id)->get()->each->delete();
            DB::table('server_assignments')->where('server_id', $id)->delete();
            Audit::log($request, 'server_deleted', 'server', $id);
        });

        return response()->noContent();
    }

    public function assign(Request $request, int $id)
    {
        $data = $request->validate(['user_ids' => ['present', 'array', 'max:100'], 'user_ids.*' => ['integer:strict', 'min:1']]);
        $ids = array_values(array_unique($data['user_ids']));
        $me = $request->user();
        DB::transaction(function () use ($id, $ids, $me, $request) {
            Server::where('active', true)->find($id) ?? throw ApiException::notFound('Server', 'SERVER_001');
            if ($ids) {
                $ok = User::whereIn('id', $ids)->where('company_id', $me->company_id)->where('role', 'company_technician')->where('active', true)->count();
                if ($ok !== count($ids)) {
                    throw ApiException::badRequest('All assignees must be active technicians in your company');
                }
            }
            DB::table('server_assignments')->where('server_id', $id)->delete();
            foreach ($ids as $uid) {
                DB::table('server_assignments')->insert(['server_id' => $id, 'user_id' => $uid, 'company_id' => $me->company_id]);
            }
            Audit::log($request, 'server_assignments_set', 'server', $id, ['user_ids' => $ids]);
        });

        return response()->json(['success' => true, 'data' => ['server_id' => $id, 'user_ids' => $ids]]);
    }
}
