<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CookieToken;
use App\Models\Company;
use App\Models\CompanyAccess;
use App\Models\User;
use App\Services\Audit;
use App\Services\Mfa;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Passwords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Compared against when the username does not exist, so response time does not reveal valid usernames. */
    private static ?string $dummyHash = null;

    public function login(Request $request)
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:200']]);
        $user = User::with('company')->where('username', $data['username'])->first();

        if ($user?->auth_method === 'ldap') {
            throw new ApiException(503, 'AUTH_003', 'LDAP authentication is not configured on this server');
        }

        self::$dummyHash ??= Hash::make('not-a-real-password');
        $passwordOk = Hash::check($data['password'], $user?->password ?? self::$dummyHash);
        if (! $user || ! $passwordOk) {
            if ($user) {
                DB::transaction(fn () => Audit::log($request, 'login_failed', 'user', $user->id, null, false, ['company_id' => $user->company_id, 'user_id' => $user->id]));
            } else {
                Audit::logUntenanted($request, 'login_failed', ['username' => mb_substr($data['username'], 0, 100)], false);
            }
            throw ApiException::unauthorized('Invalid credentials', 'AUTH_001');
        }
        if (! $user->active || ! $user->company->is_active) {
            throw ApiException::unauthorized('User account is disabled', 'AUTH_005');
        }

        // Two-step sign-in: the right password only earns a short-lived challenge. No session exists until a code is given.
        if (Mfa::enabled($user)) {
            return response()->json(['success' => true, 'mfa_required' => true, 'mfa_token' => Mfa::challenge($user), 'expires_in' => 300]);
        }

        return $this->completeLogin($request, $user);
    }

    /**
     * Step two: a current code from the authenticator app, or a one-time recovery code, with the challenge from step one.
     * Wrong codes are counted against the challenge (5 tries) and against the user (6 in 15 minutes), and each is audited.
     */
    public function verifyMfa(Request $request)
    {
        $data = $request->validate([
            'mfa_token' => ['required', 'string', 'max:2000'],
            'code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'recovery_code' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);
        if (blank($data['code'] ?? null) && blank($data['recovery_code'] ?? null)) {
            throw ApiException::badRequest('Enter the code from your authenticator app, or a recovery code.');
        }
        [$userId, $nonce] = Mfa::readChallenge($data['mfa_token']);
        $user = User::with('company')->find($userId);
        if (! $user || ! $user->active || ! $user->company->is_active || ! Mfa::enabled($user)) {
            throw ApiException::unauthorized('That sign-in has expired. Enter your password again.', 'MFA_001');
        }
        if (! Mfa::allowAttempt($user, $nonce)) {
            throw new ApiException(429, 'MFA_LOCKED', 'Too many wrong codes. Wait a few minutes, then sign in again.');
        }

        $usedRecovery = ! blank($data['recovery_code'] ?? null);
        $ok = $usedRecovery ? Mfa::useRecoveryCode($user, $data['recovery_code']) : Mfa::checkCode($user, (string) $data['code']);
        if (! $ok) {
            Mfa::recordFailure($user, $nonce);
            DB::transaction(fn () => Audit::log($request, 'mfa_failed', 'user', $user->id, ['recovery_code' => $usedRecovery], false, ['company_id' => $user->company_id, 'user_id' => $user->id]));
            throw ApiException::unauthorized($usedRecovery ? 'That recovery code is not valid or was already used.' : 'That code is not right. Check the time on your phone and try the current code.', 'MFA_002');
        }
        Mfa::consumeChallenge($nonce);
        Mfa::clearFailures($user);
        if ($usedRecovery) {
            DB::transaction(fn () => Audit::log($request, 'mfa_recovery_code_used', 'user', $user->id, ['codes_left' => Mfa::recoveryCodesLeft($user)], true, ['company_id' => $user->company_id, 'user_id' => $user->id]));
        }

        return $this->completeLogin($request, $user, ['mfa' => $usedRecovery ? 'recovery_code' : 'code']);
    }

    private function completeLogin(Request $request, User $user, ?array $details = null)
    {
        DB::transaction(function () use ($user, $request, $details) {
            $user->forceFill(['last_login' => now()])->saveQuietly();
            Audit::log($request, 'login', 'user', $user->id, $details, true, ['company_id' => $user->company_id, 'user_id' => $user->id]);
        });

        $hours = config('bms.session_hours');
        $token = $user->createToken('login', ['*'], now()->addHours($hours))->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'expires_in' => $hours * 3600,
            'user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email, 'first_name' => $user->first_name, 'last_name' => $user->last_name],
            'company' => ['id' => $user->company_id, 'company_name' => $user->company->name, 'company_slug' => $user->company->slug],
            'role' => $user->role,
            'platform_admin' => (bool) $user->is_platform_admin,
            // Required but not set up yet: the session works for nothing except setting it up.
            'mfa_setup_required' => Mfa::setupRequired($user),
            'redirect_to' => "/companies/{$user->company->slug}/dashboard",
        ])->withCookie(cookie(CookieToken::COOKIE, $token, $hours * 60, '/', null, app()->isProduction(), true, false, 'strict'));
    }

    public function logout(Request $request)
    {
        // Revoke the token server-side as well as clearing the cookie, so a copied token stops working.
        $plain = $request->bearerToken();
        if ($plain && ($token = PersonalAccessToken::findToken($plain))) {
            $token->delete();
        }

        return response()->json(['success' => true, 'message' => 'Logged out successfully'])->withoutCookie(CookieToken::COOKIE, '/');
    }

    public function me(Request $request)
    {
        $u = $request->user();
        // A platform admin working inside another company is shown that company, with their home company alongside.
        $company = Company::find(CompanyContext::id());

        return response()->json(['success' => true, 'data' => [
            'id' => $u->id, 'username' => $u->username, 'email' => $u->email, 'first_name' => $u->first_name, 'last_name' => $u->last_name,
            'company_id' => $company->id, 'company_name' => $company->name, 'company_slug' => $company->slug, 'role' => $u->effectiveRole(),
            'platform_admin' => (bool) $u->is_platform_admin,
            'mfa' => ['enabled' => Mfa::enabled($u), 'required' => Mfa::required(), 'setup_required' => Mfa::setupRequired($u), 'recovery_codes_left' => Mfa::recoveryCodesLeft($u)],
            // Other companies this user can switch into, and the role they would have there.
            'access' => CompanyAccess::with('company:id,name,slug,is_active')->where('user_id', $u->id)->get()
                ->filter(fn ($g) => $g->company?->is_active)->map(fn ($g) => ['id' => $g->company_id, 'name' => $g->company->name, 'slug' => $g->company->slug, 'role' => $g->role])->values(),
            'acting_in_other_company' => $company->id !== $u->company_id,
            'home_company' => ['id' => $u->company_id, 'name' => $u->company->name, 'slug' => $u->company->slug],
        ]]);
    }

    /**
     * Start working in a company the user has been granted access to. Recorded in that company's own audit log, so its
     * admins can see who came in. (Platform admins use their own "enter" call, which works for every company.)
     */
    public function enterCompany(Request $request, int $id)
    {
        $user = $request->user();
        $grant = CompanyAccess::with('company')->where('user_id', $user->id)->where('company_id', $id)->first();
        if (! $grant || ! $grant->company->is_active) {
            throw ApiException::forbidden('You do not have access to that company');
        }
        CompanyContext::run($id, fn () => DB::transaction(fn () => Audit::log($request, 'company_access_entered', 'company', $id, ['role' => $grant->role])));

        return response()->json(['success' => true, 'data' => ['id' => $id, 'name' => $grant->company->name, 'slug' => $grant->company->slug, 'role' => $grant->role]]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'new_password' => ['required', 'string']]);
        Passwords::assertStrong($data['new_password']);
        $user = $request->user();
        if ($user->auth_method !== 'local') {
            throw ApiException::badRequest('Password is managed by your directory (LDAP)');
        }
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ApiException::unauthorized('Current password is incorrect', 'AUTH_001');
        }
        DB::transaction(function () use ($user, $data, $request) {
            $user->forceFill(['password' => $data['new_password']])->save();
            Audit::log($request, 'password_changed', 'user', $user->id);
        });

        return response()->json(['success' => true, 'message' => 'Password changed']);
    }
}
