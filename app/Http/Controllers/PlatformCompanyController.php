<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\Audit;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Passwords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Platform-level company management. The one place in the app that reads across companies, so it is
 * limited to platform admins and only exposes counts, never another company's records.
 */
class PlatformCompanyController extends Controller
{
    public function index()
    {
        $rows = Company::query()->orderBy('name')->get()->map(fn (Company $c) => [
            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'subscription_tier' => $c->subscription_tier,
            'max_servers' => $c->max_servers, 'is_active' => $c->is_active, 'contact_email' => $c->contact_email, 'created_at' => $c->created_at,
            'users' => DB::table('users')->where('company_id', $c->id)->count(),
            'servers' => DB::table('servers')->where('company_id', $c->id)->where('active', 1)->count(),
        ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Start working inside a company. The UI then sends X-Acting-Company on every call. This records the entry in
     * the company's own audit trail, so its admins can see that a platform admin came in, and when.
     */
    public function enter(Request $request, int $id)
    {
        $company = Company::find($id) ?? throw ApiException::notFound('Company', 'COMPANY_001');
        if ($company->id !== $request->user()->company_id) {
            CompanyContext::run($company->id, fn () => DB::transaction(fn () => Audit::log($request, 'platform_access_started', 'company', $company->id)));
        }

        return response()->json(['success' => true, 'data' => ['id' => $company->id, 'name' => $company->name, 'slug' => $company->slug]]);
    }

    public function store(Request $request)
    {
        $b = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'subscription_tier' => ['sometimes', Rule::in(['basic', 'pro', 'enterprise'])],
            'max_servers' => ['sometimes', 'nullable', 'integer:strict', 'min:1', 'max:100000'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'admin_username' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['sometimes', 'nullable', 'string'],
        ], ['slug.regex' => 'Use lowercase letters, digits and single hyphens, e.g. "acme-corp"']);

        $generated = empty($b['admin_password']);
        $password = $generated ? Str::password(16, symbols: false) : $b['admin_password'];
        Passwords::assertStrong($password);

        $company = DB::transaction(function () use ($b, $password, $request) {
            if (Company::where('slug', $b['slug'])->exists()) {
                throw ApiException::conflict('COMPANY_002', 'A company with that slug already exists');
            }
            if (User::where('username', $b['admin_username'])->exists()) {
                throw ApiException::conflict('USER_002', 'Username already exists');
            }
            $c = Company::create([
                'name' => $b['name'], 'slug' => $b['slug'], 'subscription_tier' => $b['subscription_tier'] ?? 'basic',
                'max_servers' => $b['max_servers'] ?? null, 'contact_name' => $b['contact_name'] ?? null,
                'contact_email' => $b['contact_email'] ?? $b['admin_email'],
            ]);
            $admin = User::create([
                'company_id' => $c->id, 'username' => $b['admin_username'], 'email' => $b['admin_email'],
                'role' => 'company_admin', 'auth_method' => 'local', 'password' => $password,
            ]);
            // Recorded in the creating admin's own audit trail. The new company starts with an empty one.
            Audit::log($request, 'company_created', 'company', $c->id, ['name' => $c->name, 'slug' => $c->slug, 'admin_username' => $admin->username]);

            return $c->refresh();
        });

        return response()->json([
            'success' => true,
            'data' => ['id' => $company->id, 'name' => $company->name, 'slug' => $company->slug, 'subscription_tier' => $company->subscription_tier, 'max_servers' => $company->max_servers],
            // Shown once, only when the system generated it. It is not stored in readable form.
            'admin' => ['username' => $b['admin_username'], 'temporary_password' => $generated ? $password : null],
        ], 201);
    }
}
