<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Mfa;
use App\Support\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** A person managing their own two-step sign-in. Reachable even while setup is still required, so they can finish it. */
class MfaController extends Controller
{
    private function status($u): array
    {
        return ['enabled' => Mfa::enabled($u), 'required' => Mfa::required(), 'setup_required' => Mfa::setupRequired($u), 'recovery_codes_left' => Mfa::recoveryCodesLeft($u)];
    }

    /** Someone who already has MFA must prove who they are (password and a current code) before changing it. */
    private function proveIdentity(Request $request): void
    {
        $b = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        $u = $request->user();
        if (! Hash::check($b['password'], (string) $u->password)) {
            throw ApiException::unauthorized('Your password is not right.', 'AUTH_001');
        }
        if (! Mfa::guardedCheck($u, $b['code'])) {
            throw ApiException::unauthorized('That code is not right. Check the time on your phone and try the current code.', 'MFA_002');
        }
    }

    /** Begin setting up (or changing) the authenticator app. Nothing changes until a code from the new secret is proven. */
    public function setup(Request $request)
    {
        $u = $request->user();
        if (Mfa::enabled($u)) {
            $this->proveIdentity($request);
        }

        return response()->json(['success' => true, 'data' => Mfa::startSetup($u)]);
    }

    public function enable(Request $request)
    {
        $b = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $u = $request->user();
        $wasEnabled = Mfa::enabled($u);
        $codes = DB::transaction(function () use ($u, $b, $request, $wasEnabled) {
            $codes = Mfa::finishSetup($u, $b['code']);
            Audit::log($request, $wasEnabled ? 'mfa_device_changed' : 'mfa_enabled', 'user', $u->id);

            return $codes;
        });

        // Shown once. Only keyed hashes are kept, so they cannot be shown again, only replaced.
        return response()->json(['success' => true, 'data' => $this->status($u->refresh()), 'recovery_codes' => $codes]);
    }

    public function recoveryCodes(Request $request)
    {
        $u = $request->user();
        if (! Mfa::enabled($u)) {
            throw ApiException::badRequest('Set up two-step sign-in first.');
        }
        $this->proveIdentity($request);
        $codes = DB::transaction(function () use ($u, $request) {
            $codes = Mfa::regenerateRecoveryCodes($u);
            Audit::log($request, 'mfa_recovery_codes_regenerated', 'user', $u->id);

            return $codes;
        });

        return response()->json(['success' => true, 'data' => $this->status($u->refresh()), 'recovery_codes' => $codes]);
    }

    /** Only possible where MFA is optional. When it is required, nobody can turn it off. They can change device. */
    public function disable(Request $request)
    {
        if (Mfa::required()) {
            throw ApiException::badRequest('Two-step sign-in is required here, so it cannot be turned off. You can change your authenticator app instead.');
        }
        $u = $request->user();
        if (! Mfa::enabled($u)) {
            throw ApiException::badRequest('Two-step sign-in is not turned on.');
        }
        $this->proveIdentity($request);
        DB::transaction(function () use ($u, $request) {
            Audit::log($request, 'mfa_disabled', 'user', $u->id);
            Mfa::reset($u);
        });

        return response()->json(['success' => true, 'data' => $this->status($u->refresh())]);
    }
}
