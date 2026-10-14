<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\BackupJob;
use App\Models\Company;
use App\Models\CompanyAccess;
use App\Models\Server;
use App\Models\ServerLink;
use App\Models\User;
use App\Services\Audit;
use App\Services\PlatformValidation;
use App\Services\PlatformWorkloads;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Constants;
use App\Support\Page;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    private const COLS = ['hostname', 'ip_address', 'os_type', 'os_name', 'criticality', 'backup_methods', 'retention_policy_days', 'notes', 'test_targets', 'reference_items', 'timezone'];

    /** What can protect a Microsoft 365 tenant: it has no files on a machine, so the file-copy methods make no sense for it. */
    private const M365_METHODS = ['acronis_m365', 'microsoft_native_365'];

    public function index(Request $request)
    {
        $me = $request->user();
        $q = $request->validate([
            'os' => ['sometimes', Rule::in(['linux', 'windows', 'microsoft_365'])],
            'status' => ['sometimes', Rule::in(['healthy', 'failing', 'pending_test', 'test_overdue', 'unknown'])],
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
            $query->whereRaw('('.Server::statusSql().') = ?', [$q['status']]);
        }
        Scope::technician($query, $me, 'servers.id');
        [$column, $dir] = Page::order($request, ['hostname' => 'servers.hostname', 'status' => 'status', 'last_backup' => 'servers.last_backup_time', 'last_test' => 'servers.last_test_time'], 'hostname');
        $column === 'status' ? $query->orderByRaw('('.Server::statusSql().") $dir") : $query->orderBy($column, $dir);
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

        return response()->json(['success' => true, 'data' => [...$server->toArray(), 'assigned_technicians' => $assigned, 'links' => ServerLink::with('integration:id,name,platform')->where('server_id', $server->id)->get(), 'validation' => PlatformValidation::plan($server->id), 'workloads' => PlatformWorkloads::forServer($server->id), 'agent' => ($agent = Agent::where('server_id', $server->id)->first()) ? $agent->summary() : ['enrolled' => false]]]);
    }

    private function rules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'hostname' => [$req, 'string', 'min:1', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?$/'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'os_type' => [$req, Rule::in(['linux', 'windows', 'microsoft_365'])],
            'os_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            // The zone dates and times in the backup console are read in, used to pick the day for a test by hand and to check what was entered.
            'timezone' => ['sometimes', Rule::in(timezone_identifiers_list())],
            'criticality' => ['sometimes', Rule::in(['critical', 'standard', 'non-critical'])],
            'backup_methods' => ['sometimes', 'array', 'max:'.count(Constants::BACKUP_METHODS)],
            'backup_methods.*' => [Rule::in(Constants::BACKUP_METHODS)],
            'retention_policy_days' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Mailboxes, sites or other items a person samples when testing a Microsoft 365 tenant.
            'test_targets' => ['sometimes', 'nullable', 'array', 'max:500'],
            'test_targets.*' => ['nullable', 'string', 'max:255'],
            // Known files kept in the tenant's test mailboxes, with the fingerprint recorded when they were created.
            'reference_items' => ['sometimes', 'nullable', 'array', 'max:50'],
            'reference_items.*.label' => ['required', 'string', 'min:1', 'max:200'],
            'reference_items.*.hash' => ['required', 'string', 'max:300'],
            'reference_items.*.where' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    /**
     * Tidy the list of items to sample and enforce what a Microsoft 365 tenant may be: protected by a Microsoft 365 method, never by file
     * copy; and a machine may not carry a list of mailboxes. Applied to the values the server will end up with, so an edit that changes
     * only the type is checked against the methods it already has.
     *
     * @param  array<string, mixed>  $body  what the request changed
     * @param  array<string, mixed>  $current  what the server has now (empty for a new one)
     * @return array<string, mixed> $body with test_targets cleaned
     */
    private function checkTenant(array $body, array $current = []): array
    {
        if (array_key_exists('test_targets', $body)) {
            $list = array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $body['test_targets'] ?? []), fn ($t) => $t !== '')));
            $body['test_targets'] = $list ?: null;
        }
        // Only when the request touches these. An older server that already has an odd combination can still be edited for anything else.
        if (! array_intersect_key($body, array_flip(['os_type', 'backup_methods', 'test_targets', 'reference_items']))) {
            return $body;
        }
        if (array_key_exists('reference_items', $body)) {
            $body['reference_items'] = $this->cleanReferenceItems($body['reference_items'] ?? []);
        }
        $os = $body['os_type'] ?? $current['os_type'] ?? null;
        $methods = $body['backup_methods'] ?? $current['backup_methods'] ?? [];
        $targets = array_key_exists('test_targets', $body) ? $body['test_targets'] : ($current['test_targets'] ?? null);
        $references = array_key_exists('reference_items', $body) ? $body['reference_items'] : ($current['reference_items'] ?? null);
        if ($os === 'microsoft_365') {
            $bad = array_values(array_diff($methods, self::M365_METHODS));
            if ($bad) {
                throw ApiException::badRequest('A Microsoft 365 tenant can only be protected by Acronis Microsoft 365 or Microsoft native 365, not '.implode(', ', array_map(fn ($m) => str_replace('_', ' ', $m), $bad)).'.');
            }
            if (! empty($body['ip_address'])) {
                throw ApiException::badRequest('A Microsoft 365 tenant has no IP address.');
            }
        } else {
            if ($targets || $references) {
                throw ApiException::badRequest('Only a Microsoft 365 tenant has mailboxes to sample and reference items. Clear those lists or change the type.');
            }
            if (array_intersect($methods, self::M365_METHODS)) {
                throw ApiException::badRequest('The Microsoft 365 methods are for a Microsoft 365 tenant. Choose that type, or remove them.');
            }
        }

        return $body;
    }

    /**
     * A reference item's fingerprint must be real hex, and its algorithm is read from its length (SHA-256 is 64 characters and so on), so a
     * typo cannot be stored and later compared against. Capitals and spaces are tidied.
     *
     * @param  list<array{label: string, hash: string, where?: ?string}>  $items
     * @return list<array{label: string, algorithm: string, hash: string, where: ?string}>|null
     */
    private function cleanReferenceItems(array $items): ?array
    {
        $byLength = [32 => 'md5', 40 => 'sha1', 64 => 'sha256', 128 => 'sha512'];
        $out = [];
        foreach ($items as $i) {
            $label = trim((string) $i['label']);
            $hash = strtolower(preg_replace('/\s+/', '', (string) $i['hash']));
            if (! preg_match('/^[0-9a-f]+$/', $hash) || ! isset($byLength[strlen($hash)])) {
                throw ApiException::badRequest("The fingerprint for \"$label\" is not a valid MD5, SHA-1, SHA-256 or SHA-512 value: it must be 32, 40, 64 or 128 hexadecimal characters.");
            }
            if (isset($out[strtolower($label)])) {
                throw ApiException::badRequest("There are two reference items called \"$label\".");
            }
            $out[strtolower($label)] = ['label' => $label, 'algorithm' => $byLength[strlen($hash)], 'hash' => $hash, 'where' => isset($i['where']) && trim((string) $i['where']) !== '' ? trim($i['where']) : null];
        }

        return $out ? array_values($out) : null;
    }

    private function duplicateHostname(string $hostname, ?int $exceptId = null): bool
    {
        return Server::where('active', true)->where('hostname', $hostname)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
    }

    public function store(Request $request)
    {
        $body = $this->checkTenant($request->validate($this->rules(false)));
        $company = Company::findOrFail(CompanyContext::id());
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
            $body = $this->checkTenant($body, $s->only(['os_type', 'backup_methods', 'test_targets', 'reference_items']));
            // Turning a machine into a tenant (or back) would strand its agent, so it is not allowed while it has one.
            if (isset($body['os_type']) && $body['os_type'] !== $s->os_type && ($body['os_type'] === 'microsoft_365' || $s->os_type === 'microsoft_365')
                && Agent::where('server_id', $s->id)->exists()) {
                throw ApiException::conflict('SERVER_005', 'Remove the server\'s agent before changing it to or from a Microsoft 365 tenant.');
            }
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
        DB::transaction(function () use ($id, $ids, $request) {
            Server::where('active', true)->find($id) ?? throw ApiException::notFound('Server', 'SERVER_001');
            if ($ids) {
                // Technicians of this company, plus technicians from elsewhere who were granted technician access to it.
                $granted = CompanyAccess::where('company_id', CompanyContext::id())->where('role', 'company_technician')->pluck('user_id');
                $ok = User::whereIn('id', $ids)->where('active', true)->where(fn ($q) => $q->where(fn ($own) => $own->where('company_id', CompanyContext::id())->where('role', 'company_technician'))->orWhereIn('id', $granted))->count();
                if ($ok !== count($ids)) {
                    throw ApiException::badRequest('All assignees must be active technicians in your company');
                }
            }
            DB::table('server_assignments')->where('server_id', $id)->delete();
            foreach ($ids as $uid) {
                DB::table('server_assignments')->insert(['server_id' => $id, 'user_id' => $uid, 'company_id' => CompanyContext::id()]);
            }
            Audit::log($request, 'server_assignments_set', 'server', $id, ['user_ids' => $ids]);
        });

        return response()->json(['success' => true, 'data' => ['server_id' => $id, 'user_ids' => $ids]]);
    }
}
