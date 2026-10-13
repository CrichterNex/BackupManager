<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step sign-in with a time-based one-time code (TOTP, RFC 6238) from an authenticator app. The secrets are
 * encrypted with APP_KEY. Recovery codes are stored only as keyed hashes, so they can be checked but never shown again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('mfa_secret')->nullable()->after('password');          // the active secret, once set up is finished
            $t->text('mfa_pending_secret')->nullable()->after('mfa_secret');  // a secret being set up, not yet proven with a code
            $t->timestamp('mfa_enabled_at')->nullable()->after('mfa_pending_secret');
            $t->unsignedBigInteger('mfa_last_step')->default(0)->after('mfa_enabled_at'); // newest 30-second step already used, so a code cannot be replayed
            $t->text('mfa_recovery_codes')->nullable()->after('mfa_last_step'); // JSON list of keyed hashes of the unused codes
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['mfa_secret', 'mfa_pending_secret', 'mfa_enabled_at', 'mfa_last_step', 'mfa_recovery_codes']));
    }
};
