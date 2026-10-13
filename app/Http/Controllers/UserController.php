<?php

namespace App\Http\Controllers;

use App\Models\CompanyAccess;
use App\Models\User;
use App\Services\Audit;
use App\Services\Mfa;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Passwords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ROLES = ['company_admin', 'company_manager', 'company_technician'];

    private function present(User $u): User
    {
        $u->setAttribute('mfa_enabled', Mfa::enabled($u));

        return $u->makeHidden(['company_id', 'updated_at']);
    }

    public function index(Request $request)
    {
        $own = User::where('company_id', CompanyContext::id())->get()->map(fn ($u) => $this->present($u)->toArray());
        // People from other companies who were granted access here. Shown so admins can see who can act in their company and
        // assign them to servers, but they are managed by a platform admin, so only the basics are shown and they cannot be edited here.
        $granted = CompanyAccess::with('user')->where('company_id', CompanyContext::id())->get()->filter(fn ($g) => $g->user)->map(fn ($g) => [
            'id' => $g->user->id, 'username' => $g->user->username, 'email' => null, 'first_name' => $g->user->first_name, 'last_name' => $g->user->last_name,
            'role' => $g->role, 'auth_method' => $g->user->auth_method, 'active' => $g->user->active, 'last_login' => null, 'granted_access' => true,
        ]);

        return response()->json(['success' => true, 'data' => $own->concat($granted)->sortBy('username', SORT_NATURAL | SORT_FLAG_CASE)->values()]);
    }

    public function store(Request $request)
    {
        $me = $request->user();
        $body = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(self::ROLES)],
            'auth_method' => ['sometimes', Rule::in(['local', 'ldap'])],
            'password' => ['nullable', 'string'],
            'company_id' => ['nullable', 'integer'],
        ]);
        // Refuse outright rather than silently overriding another company's id.
        if (isset($body['company_id']) && (int) $body['company_id'] !== CompanyContext::id()) {
            throw ApiException::forbidden('You can only create users in your assigned company');
        }
        $method = $body['auth_method'] ?? 'local';
        $password = null;
        if ($method === 'local') {
            if (empty($body['password'])) {
                throw ApiException::badRequest('A password is required for local users');
            }
            Passwords::assertStrong($body['password']);
            $password = $body['password'];
        }
        $user = DB::transaction(function () use ($body, $method, $password, $request) {
            if (User::where('username', $body['username'])->exists()) {
                throw ApiException::conflict('USER_002', 'Username already exists');
            }
            $u = User::create([
                'company_id' => CompanyContext::id(), 'username' => $body['username'], 'email' => $body['email'],
                'first_name' => $body['first_name'] ?? null, 'last_name' => $body['last_name'] ?? null, 'phone' => $body['phone'] ?? null,
                'role' => $body['role'], 'auth_method' => $method, 'password' => $password,
                'ldap_user' => $method === 'ldap' ? $body['username'] : null,
            ]);
            Audit::log($request, 'user_created', 'user', $u->id, ['username' => $body['username'], 'role' => $body['role']]);

            return $u->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->present($user)], 201);
    }

    /** Take away a person's two-step sign-in (lost phone). They are signed out everywhere and must set it up again. */
    public function resetMfa(Request $request, int $id)
    {
        $me = $request->user();
        if ($id === $me->id) {
            throw ApiException::badRequest('To change your own authenticator app, use Change authenticator on your profile.');
        }
        $u = DB::transaction(function () use ($id, $me, $request) {
            $u = User::where('company_id', CompanyContext::id())->lockForUpdate()->find($id) ?? throw ApiException::notFound('User', 'USER_001');
            if ($u->is_platform_admin && ! $me->is_platform_admin) {
                throw ApiException::forbidden('Only a platform admin can reset the two-step sign-in of a platform admin');
            }
            Mfa::reset($u);
            Audit::log($request, 'mfa_reset', 'user', $u->id, ['username' => $u->username]);

            return $u;
        });

        return response()->json(['success' => true, 'data' => $this->present($u->refresh())]);
    }

    public function update(Request $request, int $id)
    {
        $me = $request->user();
        $body = $request->validate([
            'email' => ['sometimes', 'email', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string'],
        ]);
        if (! $body) {
            throw ApiException::badRequest('No fields to update');
        }
        // Prevents an admin locking their own company out by demoting or disabling themselves.
        if ($id === $me->id && (array_key_exists('role', $body) || array_key_exists('active', $body))) {
            throw ApiException::badRequest('You cannot change your own role or active status');
        }
        $user = DB::transaction(function () use ($id, $body, $request) {
            $u = User::where('company_id', CompanyContext::id())->lockForUpdate()->find($id);
            if (! $u) {
                throw ApiException::notFound('User', 'USER_001');
            }
            // A platform admin can reach every company, so changing their password, role or status is not for a company admin.
            if ($u->is_platform_admin && ! $request->user()->is_platform_admin) {
                throw ApiException::forbidden('Only a platform admin can change a platform admin');
            }
            $changes = $body;
            if (array_key_exists('password', $body)) {
                if ($u->auth_method !== 'local') {
                    throw ApiException::badRequest('Password is managed by LDAP for this user');
                }
                Passwords::assertStrong($body['password']);
            }
            $u->fill($changes)->save();
            unset($body['password']);
            Audit::log($request, 'user_updated', 'user', $id, $body + ['password_reset' => array_key_exists('password', $changes)]);

            return $u->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->present($user)]);
    }
}
