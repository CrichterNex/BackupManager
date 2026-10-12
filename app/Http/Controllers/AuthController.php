<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CookieToken;
use App\Models\User;
use App\Services\Audit;
use App\Support\ApiException;
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

        DB::transaction(function () use ($user, $request) {
            $user->forceFill(['last_login' => now()])->saveQuietly();
            Audit::log($request, 'login', 'user', $user->id, null, true, ['company_id' => $user->company_id, 'user_id' => $user->id]);
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

        return response()->json(['success' => true, 'data' => [
            'id' => $u->id, 'username' => $u->username, 'email' => $u->email, 'first_name' => $u->first_name, 'last_name' => $u->last_name,
            'company_id' => $u->company_id, 'company_name' => $u->company->name, 'company_slug' => $u->company->slug, 'role' => $u->role,
            'platform_admin' => (bool) $u->is_platform_admin,
        ]]);
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
