<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyAccess;
use App\Models\User;
use App\Services\Audit;
use App\Services\Mfa;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform admins managing who can work in which company: access to extra companies with a role for each, and the
 * platform-admin flag itself. These are the only calls that reach users across companies.
 */
class PlatformUserController extends Controller
{
    private const ROLES = ['company_admin', 'company_manager', 'company_technician'];

    private function present(User $u, $grants): array
    {
        return [
            'id' => $u->id, 'username' => $u->username, 'email' => $u->email, 'first_name' => $u->first_name, 'last_name' => $u->last_name,
            'role' => $u->role, 'active' => $u->active, 'is_platform_admin' => $u->is_platform_admin, 'mfa_enabled' => Mfa::enabled($u), 'last_login' => $u->last_login,
            'company' => ['id' => $u->company_id, 'name' => $u->company->name, 'slug' => $u->company->slug],
            'access' => collect($grants)->map(fn ($g) => ['company_id' => $g->company_id, 'company' => $g->company->name, 'role' => $g->role])->values(),
        ];
    }

    public function index(Request $request)
    {
        $q = $request->validate(['q' => ['sometimes', 'string', 'max:100'], 'company_id' => ['sometimes', 'integer', 'min:1']]);
        $query = User::with('company')->orderBy('username');
        if (isset($q['q'])) {
            $like = '%'.addcslashes($q['q'], '%_\\').'%';
            $query->where(fn ($w) => $w->where('username', 'like', $like)->orWhere('email', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }
        isset($q['company_id']) && $query->where('company_id', $q['company_id']);
        [$users, $pagination] = Page::run($request, $query);
        $grants = CompanyAccess::with('company:id,name')->whereIn('user_id', $users->pluck('id'))->get()->groupBy('user_id');

        return response()->json(['success' => true, 'data' => $users->map(fn ($u) => $this->present($u, $grants[$u->id] ?? []))->values(), 'pagination' => $pagination]);
    }

    /**
     * Replace the set of other companies a user can work in. Each grant is logged in THAT company's audit log, so its
     * own admins can see who was given access and with which role.
     */
    public function setAccess(Request $request, int $id)
    {
        $b = $request->validate([
            'grants' => ['present', 'array', 'max:200'],
            'grants.*.company_id' => ['required', 'integer:strict', 'min:1'],
            'grants.*.role' => ['required', Rule::in(self::ROLES)],
        ]);
        $user = User::find($id) ?? throw ApiException::notFound('User', 'USER_001');
        $wanted = collect($b['grants'])->keyBy('company_id');
        if ($wanted->count() !== count($b['grants'])) {
            throw ApiException::badRequest('Each company can be listed once.');
        }
        if ($user->is_platform_admin && $wanted->isNotEmpty()) {
            throw ApiException::badRequest('A platform admin already has access to every company. Remove the platform-admin flag first if you want per-company access.');
        }
        if ($wanted->has($user->company_id)) {
            throw ApiException::badRequest('That is the user\'s own company, which they already belong to.');
        }
        $companies = Company::whereIn('id', $wanted->keys())->pluck('name', 'id');
        if ($companies->count() !== $wanted->count()) {
            throw ApiException::notFound('Company', 'COMPANY_001');
        }

        DB::transaction(function () use ($user, $wanted, $request) {
            $current = CompanyAccess::where('user_id', $user->id)->get()->keyBy('company_id');
            foreach ($current as $companyId => $grant) {
                if (! $wanted->has($companyId)) {
                    $grant->delete();
                    $this->log($request, $companyId, 'access_revoked', $user, ['role' => $grant->role]);
                }
            }
            foreach ($wanted as $companyId => $g) {
                $existing = $current->get($companyId);
                if (! $existing) {
                    CompanyAccess::create(['user_id' => $user->id, 'company_id' => $companyId, 'role' => $g['role'], 'granted_by' => $request->user()->id]);
                    $this->log($request, $companyId, 'access_granted', $user, ['role' => $g['role']]);
                } elseif ($existing->role !== $g['role']) {
                    $existing->forceFill(['role' => $g['role']])->save();
                    $this->log($request, $companyId, 'access_role_changed', $user, ['from' => $existing->getOriginal('role'), 'role' => $g['role']]);
                }
            }
        });

        return response()->json(['success' => true, 'data' => $this->present($user->fresh('company'), CompanyAccess::with('company:id,name')->where('user_id', $user->id)->get())]);
    }

    private function log(Request $request, int $companyId, string $action, User $subject, array $details): void
    {
        CompanyContext::run($companyId, fn () => Audit::log($request, $action, 'user', $subject->id, ['username' => $subject->username] + $details));
    }

    /** Take away anyone's two-step sign-in (lost phone). They are signed out everywhere and must set it up again. */
    public function resetMfa(Request $request, int $id)
    {
        $user = User::with('company')->find($id) ?? throw ApiException::notFound('User', 'USER_001');
        if ($user->id === $request->user()->id) {
            throw ApiException::badRequest('To change your own authenticator app, use Change authenticator on your profile.');
        }
        DB::transaction(function () use ($user, $request) {
            Mfa::reset($user);
            $this->log($request, $user->company_id, 'mfa_reset', $user, []);
        });

        return response()->json(['success' => true, 'data' => $this->present($user->fresh('company'), CompanyAccess::with('company:id,name')->where('user_id', $user->id)->get())]);
    }

    /**
     * Give or take away the platform-admin flag, which means access to every company as admin. Logged in the user's own
     * company. You cannot change your own. Because the caller is always an active platform admin, at least one always
     * remains, so the system cannot be locked out.
     */
    public function setPlatformAdmin(Request $request, int $id)
    {
        $b = $request->validate(['is_platform_admin' => ['required', 'boolean:strict']]);
        $user = User::with('company')->find($id) ?? throw ApiException::notFound('User', 'USER_001');
        if ($user->id === $request->user()->id) {
            throw ApiException::badRequest('You cannot change your own platform-admin status.');
        }
        DB::transaction(function () use ($user, $b, $request) {
            if ($b['is_platform_admin']) {
                if ($user->role !== 'company_admin' || ! $user->active) {
                    throw ApiException::badRequest('Only an active company admin can be a platform admin.');
                }
                CompanyAccess::where('user_id', $user->id)->delete(); // access to everything makes per-company grants redundant
            }
            $user->forceFill(['is_platform_admin' => $b['is_platform_admin']])->save();
            $this->log($request, $user->company_id, $b['is_platform_admin'] ? 'platform_admin_granted' : 'platform_admin_revoked', $user, []);
        });

        return response()->json(['success' => true, 'data' => $this->present($user->fresh('company'), [])]);
    }
}
