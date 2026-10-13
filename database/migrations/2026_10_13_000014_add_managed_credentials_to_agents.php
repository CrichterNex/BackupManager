<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-ins an agent uses for a location's network share. The user names and passwords are stored encrypted with APP_KEY and are
 * never shown again. The version counts changes, so an agent that already has the current set is not sent the passwords again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $t) {
            $t->longText('managed_credentials')->nullable()->after('managed_source_roots');
            $t->unsignedInteger('credentials_version')->default(0)->after('managed_credentials');
        });
    }

    public function down(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->dropColumn(['managed_credentials', 'credentials_version']));
    }
};
