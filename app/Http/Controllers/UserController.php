<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Support\ApiException;
use App\Support\Passwords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ROLES = ['company_admin', 'company_manager', 'company_technician'];

    private function present(User $u): User
    {
        return $u->makeHidden(['company_id', 'updated_at']);
    }

    public function index(Request $request)
    {
        $users = User::where('company_id', $request->user()->company_id)->orderBy('username')->get();

        return response()->json(['success' => true, 'data' => $users->map(fn ($u) => $this->present($u))->values()]);
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
        if (isset($body['company_id']) && (int) $body['company_id'] !== $me->company_id) {
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
        $user = DB::transaction(function () use ($body, $me, $method, $password, $request) {
            if (User::where('username', $body['username'])->exists()) {
                throw ApiException::conflict('USER_002', 'Username already exists');
            }
            $u = User::create([
                'company_id' => $me->company_id, 'username' => $body['username'], 'email' => $body['email'],
                'first_name' => $body['first_name'] ?? null, 'last_name' => $body['last_name'] ?? null, 'phone' => $body['phone'] ?? null,
                'role' => $body['role'], 'auth_method' => $method, 'password' => $password,
                'ldap_user' => $method === 'ldap' ? $body['username'] : null,
            ]);
            Audit::log($request, 'user_created', 'user', $u->id, ['username' => $body['username'], 'role' => $body['role']]);

            return $u->refresh();
        });

        return response()->json(['success' => true, 'data' => $this->present($user)], 201);
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
        $user = DB::transaction(function () use ($id, $me, $body, $request) {
            $u = User::where('company_id', $me->company_id)->lockForUpdate()->find($id);
            if (! $u) {
                throw ApiException::notFound('User', 'USER_001');
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
